<?php

namespace Modules\Calculator\Services;

use Modules\Calculator\Models\KycRecord;
use Modules\Calculator\Models\Member;
use RuntimeException;

/**
 * KYC-intake (Фаза 4, S8). Приём документов через Telegram Passport + ручной аппрув +
 * пороговый гейт перед выводом. Telegram Passport САМ верификацию не делает — здесь только
 * сбор; подлинность/AML — Фаза 5 (Sumsub и т.п.).
 *
 * ⚠️ NEEDS-LIVE-VERIFY: расшифровка Passport-payload приватным ключом (Key Vault) — Фаза 5;
 * сейчас документы сохраняются как есть для ручного ревью.
 */
class KycService
{
    /** Подать/обновить документы (Telegram Passport). Статус сбрасывается в pending. */
    public function submit(Member $member, array $documents): array
    {
        $record = KycRecord::query()->updateOrCreate(
            ['member_id' => $member->id],
            [
                'source' => 'telegram_passport',
                'documents' => $documents,
                'review_status' => KycRecord::STATUS_PENDING,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'reject_reason' => null,
            ],
        );

        return $this->present($record);
    }

    /** Статус KYC участника (или none, если не подавал). */
    public function statusFor(Member $member): array
    {
        $record = KycRecord::query()->where('member_id', $member->id)->first();
        if ($record === null) {
            return ['status' => 'none'];
        }

        return $this->present($record);
    }

    public function isApproved(Member $member): bool
    {
        return KycRecord::query()
            ->where('member_id', $member->id)
            ->where('review_status', KycRecord::STATUS_APPROVED)
            ->exists();
    }

    /**
     * Пороговый гейт перед выводом. Если порог не задан (null) — гейт выключен (Фаза 3).
     * Если сумма > порога — нужен одобренный KYC, иначе RuntimeException.
     */
    public function assertCleared(Member $member, int $amountCents): void
    {
        $threshold = config('calculator.kyc_threshold_cents');
        if ($threshold === null) {
            return;
        }
        if ($amountCents <= (int) $threshold) {
            return;
        }
        if (!$this->isApproved($member)) {
            throw new RuntimeException('Для вывода этой суммы требуется пройти верификацию (KYC)');
        }
    }

    // --- Админ ---

    public function listForAdmin(?string $status = null): array
    {
        return KycRecord::query()
            ->when($status !== null && $status !== '', fn ($q) => $q->where('review_status', $status))
            ->orderByDesc('id')
            ->get()
            ->map(fn (KycRecord $r) => $this->present($r) + ['member_id' => $r->member_id])
            ->all();
    }

    public function review(int $id, Member $reviewer, bool $approve, ?string $reason = null): array
    {
        $record = KycRecord::query()->find($id);
        if ($record === null) {
            throw new RuntimeException('KYC-запись не найдена');
        }

        $record->review_status = $approve ? KycRecord::STATUS_APPROVED : KycRecord::STATUS_REJECTED;
        $record->reviewed_by = $reviewer->id;
        $record->reviewed_at = now();
        $record->reject_reason = $approve ? null : $reason;
        $record->save();

        return $this->present($record);
    }

    private function present(KycRecord $record): array
    {
        return [
            'id' => $record->id,
            'status' => $record->review_status,
            'source' => $record->source,
            'reviewed_at' => optional($record->reviewed_at)->toIso8601String(),
            'reject_reason' => $record->reject_reason,
        ];
    }
}
