<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Calculator\Models\FeatureFlag;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * AI-ассистент партнёра. Покрывает: feature-flag гейт, null-member (лид), валидацию,
 * rate-limit, ошибку Claude API → AI_UNAVAILABLE, успешный ответ.
 */
class AiAssistantTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    private const BRONZE = 1;
    private const ENDPOINT = '/api/v1/cabinet/assistant/ask';
    private const ANTHROPIC = 'https://api.anthropic.com/*';

    private string $memberData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();

        // Активный партнёр (Bronze) — единственный субъект во всех сценариях
        [$rootData] = $this->registerTg(900, name: 'Root');
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->tgHeaders($rootData))->assertOk();
        $this->memberData = $rootData;

        // По умолчанию флаг включён — большинство тестов проверяет логику за флагом
        FeatureFlag::firstOrCreate(['key' => 'ai_assistant'], ['enabled' => true]);
    }

    /** Telegram-запрос без зарегистрированного member (лид без пакета) → 403 */
    public function testLeadGets403(): void
    {
        [$sponsorData] = $this->registerTg(901, name: 'Sponsor');
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->tgHeaders($sponsorData))->assertOk();

        [$leadData] = $this->makeLead(902, $this->memberByTg(901)->ref_code);

        $this->postJson(self::ENDPOINT, ['question' => 'Привет', 'locale' => 'ru'], $this->tgHeaders($leadData))
            ->assertStatus(403)
            ->assertJsonPath('code', 'FEATURE_DISABLED');
    }

    /** Feature flag отключён → 403 FEATURE_DISABLED */
    public function testFeatureFlagDisabled(): void
    {
        FeatureFlag::where('key', 'ai_assistant')->update(['enabled' => false]);

        $this->postJson(self::ENDPOINT, ['question' => 'Привет', 'locale' => 'ru'], $this->tgHeaders($this->memberData))
            ->assertStatus(403)
            ->assertJsonPath('code', 'FEATURE_DISABLED');
    }

    /** question пустой → 422 */
    public function testValidationEmptyQuestion(): void
    {
        $this->postJson(self::ENDPOINT, ['question' => '', 'locale' => 'ru'], $this->tgHeaders($this->memberData))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['question']]);
    }

    /** question длиннее 500 символов → 422 */
    public function testValidationQuestionTooLong(): void
    {
        $this->postJson(self::ENDPOINT, ['question' => str_repeat('а', 501), 'locale' => 'ru'], $this->tgHeaders($this->memberData))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['question']]);
    }

    /** locale невалидный → 422 */
    public function testValidationBadLocale(): void
    {
        $this->postJson(self::ENDPOINT, ['question' => 'Привет', 'locale' => 'kk'], $this->tgHeaders($this->memberData))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['locale']]);
    }

    /** 11-й запрос (лимит 10/мин) → 429 RATE_LIMITED */
    public function testRateLimitExceeded(): void
    {
        Http::fake([self::ANTHROPIC => Http::response(['content' => [['type' => 'text', 'text' => 'ok']]], 200)]);

        config(['calculator.assistant_rate_per_minute' => 2]);

        $member = $this->memberByTg(900);
        RateLimiter::clear('ai-assistant:' . $member->id);

        $payload = ['question' => 'Привет', 'locale' => 'ru'];
        $headers = $this->tgHeaders($this->memberData);

        $this->postJson(self::ENDPOINT, $payload, $headers)->assertOk();
        $this->postJson(self::ENDPOINT, $payload, $headers)->assertOk();
        $this->postJson(self::ENDPOINT, $payload, $headers)
            ->assertStatus(429)
            ->assertJsonPath('code', 'RATE_LIMITED')
            ->assertJsonStructure(['retry_after']);
    }

    /** Claude API отвечает 500 → 200 {code: AI_UNAVAILABLE} (не 500) */
    public function testClaudeApiError(): void
    {
        Http::fake([self::ANTHROPIC => Http::response(['error' => 'overloaded'], 529)]);

        $this->postJson(self::ENDPOINT, ['question' => 'Как работают бонусы?', 'locale' => 'ru'], $this->tgHeaders($this->memberData))
            ->assertStatus(200)
            ->assertJsonPath('code', 'AI_UNAVAILABLE');
    }

    /** Claude API недоступен (исключение) → 200 {code: AI_UNAVAILABLE} */
    public function testClaudeApiTimeout(): void
    {
        Http::fake([self::ANTHROPIC => fn () => throw new \RuntimeException('timeout')]);

        $this->postJson(self::ENDPOINT, ['question' => 'Сколько стоит Bronze?', 'locale' => 'en'], $this->tgHeaders($this->memberData))
            ->assertStatus(200)
            ->assertJsonPath('code', 'AI_UNAVAILABLE');
    }

    /** Успешный ответ Claude → 200 {status: success, answer: "...", locale} */
    public function testSuccess(): void
    {
        Http::fake([
            self::ANTHROPIC => Http::response([
                'content' => [['type' => 'text', 'text' => 'Bronze стоит $90 и даёт 90 PV.']],
            ], 200),
        ]);

        $this->postJson(self::ENDPOINT, ['question' => 'Сколько стоит Bronze?', 'locale' => 'ru'], $this->tgHeaders($this->memberData))
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('locale', 'ru')
            ->assertJsonStructure(['answer']);
    }

    /** EN locale проходит валидацию и прокидывается в ответ */
    public function testEnLocale(): void
    {
        Http::fake([
            self::ANTHROPIC => Http::response([
                'content' => [['type' => 'text', 'text' => 'Bronze costs $90.']],
            ], 200),
        ]);

        $this->postJson(self::ENDPOINT, ['question' => 'How much is Bronze?', 'locale' => 'en'], $this->tgHeaders($this->memberData))
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('locale', 'en');
    }
}
