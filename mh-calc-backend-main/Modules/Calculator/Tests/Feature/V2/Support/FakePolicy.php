<?php

namespace Modules\Calculator\Tests\Feature\V2\Support;

use Modules\Calculator\V2\Contracts\PolicyV2;

/**
 * Фейковая версия политики T01 с РЕАЛЬНЫМ provenance-API домена:
 * versionId()/configHash() — методы, как у PolicyV2 (ревью W1 MF-1: фейк с
 * публичными свойствами id/config_hash маскировал чтение несуществующих полей).
 * Родительский конструктор намеренно не вызывается: фейку нужен только
 * provenance-API; обращение к прочим секциям политики упадёт громко.
 */
class FakePolicy extends PolicyV2
{
    private int $fakeVersionId;

    private string $fakeConfigHash;

    public function __construct(int $versionId = 42, string $configHash = 'cafebabe')
    {
        $this->fakeVersionId = $versionId;
        $this->fakeConfigHash = $configHash;
    }

    public function versionId(): int
    {
        return $this->fakeVersionId;
    }

    public function configHash(): string
    {
        return $this->fakeConfigHash;
    }
}
