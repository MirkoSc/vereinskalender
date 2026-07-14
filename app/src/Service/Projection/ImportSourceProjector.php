<?php

declare(strict_types=1);

namespace App\Service\Projection;

use App\Domain\AggregateType;

/**
 * Projects only the config fields; the run-status columns (letzter_lauf,
 * letzter_status, fehlertext) are technical and never part of payloads.
 */
final class ImportSourceProjector extends TableProjector
{
    public function aggregateType(): AggregateType
    {
        return AggregateType::ImportSource;
    }

    public function tableName(): string
    {
        return 'import_source';
    }

    public function references(): array
    {
        return ['team_id' => 'team'];
    }

    protected function columns(): array
    {
        return ['team_id', 'ics_url', 'aktiv'];
    }
}
