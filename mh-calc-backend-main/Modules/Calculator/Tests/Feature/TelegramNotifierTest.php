<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Calculator\Services\ActivationService;
use Modules\Calculator\Services\MemberService;
use Modules\Calculator\Services\Telegram\TelegramNotifications;
use Modules\Calculator\Services\Telegram\TelegramNotifier;
use Tests\TestCase;

/**
 * Исходящие Telegram-уведомления: opt-in, best-effort, экранирование.
 */
class TelegramNotifierTest extends TestCase
{
    use RefreshDatabase;

    public function testDisabledByDefaultSendsNothing(): void
    {
        Http::fake();
        app(TelegramNotifier::class)->notify(123, 'hi');
        Http::assertNothingSent();
    }

    public function testEnabledSendsMessage(): void
    {
        Http::fake();
        config(['calculator.telegram_notify_enabled' => true, 'calculator.telegram_bot_token' => 'TT']);

        app(TelegramNotifier::class)->notify(123, 'привет');

        Http::assertSent(fn ($req) => str_contains($req->url(), '/botTT/sendMessage')
            && $req['chat_id'] == 123);
    }

    public function testActivationNotifiesTelegramMember(): void
    {
        Http::fake();
        config(['calculator.telegram_notify_enabled' => true, 'calculator.telegram_bot_token' => 'TT']);

        $member = app(MemberService::class)->registerTelegram(500, 'Tg User', 'tg500');
        app(ActivationService::class)->activate($member->id, 1, 'evt-tg');

        Http::assertSent(fn ($req) => $req['chat_id'] == 500);
    }

    public function testActivationSurvivesTelegramError(): void
    {
        // Best-effort: сбой доставки в Telegram не должен ронять активацию/расчёт.
        Http::fake(fn () => throw new \RuntimeException('network down'));
        config(['calculator.telegram_notify_enabled' => true, 'calculator.telegram_bot_token' => 'TT']);

        $member = app(MemberService::class)->registerTelegram(501, 'Tg', 'tg501');
        $event = app(ActivationService::class)->activate($member->id, 1, 'evt-err');

        $this->assertNotNull($event->id);
        $this->assertDatabaseHas('members', ['telegram_id' => 501, 'status' => 'active']);
    }

    public function testMessagesEscapeHtml(): void
    {
        $msg = TelegramNotifications::newReferralActivated('<b>x</b>');
        $this->assertStringContainsString('&lt;b&gt;', $msg);
        $this->assertStringNotContainsString('<b>x</b>', $msg);
    }
}
