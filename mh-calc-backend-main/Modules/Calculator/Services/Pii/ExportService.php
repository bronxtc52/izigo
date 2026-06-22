<?php

namespace Modules\Calculator\Services\Pii;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Calculator\Models\KycRecord;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\WithdrawalRequest;

/**
 * C5 (Block C): сбор данных участника для экспорта (JSON + CSV).
 *
 * PII-поля (telegram_username, payout_details, KYC-статус) маскируются через PiiService,
 * если $masked = true (для не-owner маска принудительна — гейтится в контроллере/роуте).
 * CSV защищён от формульных инъекций (ячейки, начинающиеся с = + - @ или табов/CR,
 * префиксуются апострофом). Новых таблиц/миграций нет — читаем существующие модели.
 */
class ExportService
{
    public function __construct(private readonly PiiService $pii)
    {
    }

    /**
     * Собрать плоскую структуру данных участника. PII маскируется при $masked = true.
     *
     * @return array<string, mixed>
     */
    public function collect(int $memberId, bool $masked = true): array
    {
        /** @var Member $member */
        $member = Member::query()->findOrFail($memberId);

        $username = $member->telegram_username;
        // payout_details живёт на заявке вывода (на Member его нет): берём из последней заявки.
        $payout = WithdrawalRequest::query()
            ->where('member_id', $memberId)
            ->orderByDesc('id')
            ->value('payout_details');
        $kycStatus = KycRecord::query()
            ->where('member_id', $memberId)
            ->orderByDesc('id')
            ->value('review_status');

        return [
            'id' => $member->id,
            'name' => $member->name,
            'ref_code' => $member->ref_code,
            'status' => $member->status,
            'rank_id' => $member->rank_id,
            'package_id' => $member->package_id,
            'sponsor_id' => $member->sponsor_id,
            'parent_id' => $member->parent_id,
            'position' => $member->position,
            // --- PII (маскируется при $masked) ---
            'telegram_username' => $masked
                ? $this->pii->mask($username, PiiService::TYPE_USERNAME)
                : $username,
            'payout_details' => $masked
                ? $this->pii->mask($payout, PiiService::TYPE_PAYOUT)
                : $payout,
            // KYC: present() отдаёт review_status под ключом status (память izigo-kyc-admin-status-field).
            // Статус сам по себе не PII, но KYC-блок в PII-списке — маскируем сырое значение.
            'kyc_status' => $masked
                ? $this->pii->mask($kycStatus, PiiService::TYPE_KYC)
                : $kycStatus,
        ];
    }

    /**
     * Текущие сырые значения PII участника (для reveal owner-only).
     * Возвращает только PII-поля — НЕ всю карточку.
     *
     * @return array<string, ?string>
     */
    public function revealPii(int $memberId): array
    {
        if (!Member::query()->whereKey($memberId)->exists()) {
            throw new ModelNotFoundException('member not found');
        }

        return [
            'telegram_username' => Member::query()->whereKey($memberId)->value('telegram_username'),
            'payout_details' => WithdrawalRequest::query()
                ->where('member_id', $memberId)->orderByDesc('id')->value('payout_details'),
            'kyc_status' => KycRecord::query()
                ->where('member_id', $memberId)->orderByDesc('id')->value('review_status'),
        ];
    }

    /** Поля участника в виде JSON-структуры (массив, контроллер сам отдаёт json). */
    public function toJson(int $memberId, bool $masked = true): array
    {
        return $this->collect($memberId, $masked);
    }

    /** Поля участника в виде CSV-строки (header + одна строка значений), с anti-injection. */
    public function toCsv(int $memberId, bool $masked = true): string
    {
        $row = $this->collect($memberId, $masked);

        $header = implode(',', array_map([$this, 'csvCell'], array_keys($row)));
        $values = implode(',', array_map(
            fn ($v) => $this->csvCell($v === null ? '' : (string) $v),
            array_values($row),
        ));

        return $header . "\r\n" . $values . "\r\n";
    }

    /**
     * Экранировать ячейку CSV. Две защиты:
     *  1. Anti-injection: ячейки, начинающиеся с = + - @ (или таб/CR — формульные триггеры
     *     в Excel/Sheets/LibreOffice), префиксуются апострофом, чтобы не исполнились как формула.
     *  2. Стандартное CSV-квотирование: значения с запятой/кавычкой/переводом строки берутся
     *     в кавычки, внутренние кавычки удваиваются.
     */
    private function csvCell(string $value): string
    {
        // Триггер ищем после ведущих пробелов/управляющих — иначе `" =cmd"` обошёл бы защиту
        // (Excel/LibreOffice игнорируют ведущий пробел и всё равно исполнят формулу).
        if (preg_match('/^[\s]*[=+\-@\t\r]/', $value) === 1) {
            $value = "'" . $value;
        }

        if (preg_match('/[",\r\n]/', $value) === 1) {
            $value = '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }
}
