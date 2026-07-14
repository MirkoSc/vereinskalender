<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Domain\AggregateType;
use App\Domain\EventContext;
use App\Domain\EventSource;
use App\Domain\EventType;
use App\Repository\ImportSourceRepository;
use App\Repository\MatchRepository;
use App\Repository\VenueRepository;
use App\Service\EventStore\EventStore;
use App\Service\Kalender\VenueMatcher;

/**
 * ICS sync (CLAUDE.md section 7). Per event in the feed, keyed by
 * (import_source_id, ics_uid): unknown -> insert, sync_hash changed ->
 * update (a relocated match moves automatically, the UID stays), unchanged
 * -> skip. Afterwards: UIDs in the DB but missing from the feed are set to
 * status 'abgesagt' - NEVER hard-deleted (also protects against empty
 * feeds). Errors are isolated per source. All writes go through the event
 * store (quelle 'import').
 */
final readonly class IcsImportService
{
    private const string EDITOR_NAME = 'ICS-Import';

    public function __construct(
        private EventStore $eventStore,
        private ImportSourceRepository $sources,
        private MatchRepository $matches,
        private VenueRepository $venues,
        private VenueMatcher $venueMatcher,
        private IcsFeedFetcher $fetcher,
    ) {
    }

    /**
     * @return list<ImportSourceResult> one broken source never stops the others
     */
    public function runAll(): array
    {
        $results = [];
        foreach ($this->sources->findActive() as $source) {
            $results[] = $this->runSource($source);
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $source import_source row
     */
    public function runSource(array $source): ImportSourceResult
    {
        $sourceId = (int) $source['id'];

        try {
            $result = $this->sync($source);
            $this->sources->updateRunStatus($sourceId, 'ok', null);

            return $result;
        } catch (\Throwable $e) {
            $this->sources->updateRunStatus($sourceId, 'fehler', $e->getMessage());

            return new ImportSourceResult($sourceId, ok: false, fehlertext: $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $source
     */
    private function sync(array $source): ImportSourceResult
    {
        $sourceId = (int) $source['id'];
        $teamId = (int) $source['team_id'];
        $context = new EventContext(self::EDITOR_NAME, '', EventSource::Import);

        $icsEvents = IcsParser::parse($this->fetcher->fetch((string) $source['ics_url']));

        $defaultPitchByVenue = [];
        foreach ($this->venues->findAll() as $venue) {
            $defaultPitchByVenue[(int) $venue['id']] = $venue['default_pitch_id'] !== null
                ? (int) $venue['default_pitch_id']
                : null;
        }

        $inserted = $updated = $skipped = 0;
        $feedUids = [];

        foreach ($icsEvents as $icsEvent) {
            $feedUids[$icsEvent->uid] = true;

            $anstoss = $icsEvent->start->format('Y-m-d H:i:s');
            $gegner = mb_substr($icsEvent->summary, 0, 150);
            $ortText = mb_substr($icsEvent->location, 0, 255);
            $status = $icsEvent->cancelled ? 'abgesagt' : 'geplant';
            $syncHash = self::syncHash($anstoss, $gegner, $ortText, $status);

            $existing = $this->matches->findBySourceAndUid($sourceId, $icsEvent->uid);
            if ($existing !== null && (string) $existing['sync_hash'] === $syncHash) {
                $skipped++;
                continue;
            }

            // home detection at import time; the pitch itself is NOT in the
            // ICS: pre-fill with the venue's default pitch. A manual pitch
            // assignment survives updates as long as the location text is
            // unchanged.
            $venueId = $this->venueMatcher->match($ortText);
            if ($existing !== null && (string) $existing['ort_text'] === $ortText) {
                $pitchId = $existing['pitch_id'] !== null ? (int) $existing['pitch_id'] : null;
            } else {
                $pitchId = $venueId !== null ? ($defaultPitchByVenue[$venueId] ?? null) : null;
            }

            $payload = [
                'team_id' => $teamId,
                'anstoss' => $anstoss,
                'gegner' => $gegner,
                'heimspiel' => $venueId !== null,
                'ort_text' => $ortText,
                'pitch_id' => $pitchId,
                'status' => $status,
                'import_source_id' => $sourceId,
                'ics_uid' => $icsEvent->uid,
                'ics_sequence' => $icsEvent->sequence,
                'sync_hash' => $syncHash,
            ];

            if ($existing === null) {
                $this->eventStore->append(AggregateType::Match, null, EventType::Created, $payload, $context);
                $inserted++;
            } else {
                $this->eventStore->append(AggregateType::Match, (int) $existing['id'], EventType::Updated, $payload, $context);
                $updated++;
            }
        }

        // follow-up: future matches whose UID vanished from the feed are
        // cancelled (never deleted); past matches are left untouched, some
        // feeds drop past events
        $cancelled = 0;
        $today = new \DateTimeImmutable('today')->format('Y-m-d H:i:s');
        foreach ($this->matches->findBySource($sourceId) as $match) {
            if (isset($feedUids[(string) $match['ics_uid']])
                || (string) $match['status'] === 'abgesagt'
                || (string) $match['anstoss'] < $today) {
                continue;
            }

            $payload = [
                'team_id' => (int) $match['team_id'],
                'anstoss' => (string) $match['anstoss'],
                'gegner' => (string) $match['gegner'],
                'heimspiel' => (int) $match['heimspiel'] === 1,
                'ort_text' => (string) $match['ort_text'],
                'pitch_id' => $match['pitch_id'] !== null ? (int) $match['pitch_id'] : null,
                'status' => 'abgesagt',
                'import_source_id' => $sourceId,
                'ics_uid' => (string) $match['ics_uid'],
                'ics_sequence' => (int) $match['ics_sequence'],
                'sync_hash' => self::syncHash(
                    (string) $match['anstoss'],
                    (string) $match['gegner'],
                    (string) $match['ort_text'],
                    'abgesagt',
                ),
            ];
            $this->eventStore->append(AggregateType::Match, (int) $match['id'], EventType::Updated, $payload, $context);
            $cancelled++;
        }

        return new ImportSourceResult(
            $sourceId,
            ok: true,
            inserted: $inserted,
            updated: $updated,
            cancelled: $cancelled,
            skipped: $skipped,
        );
    }

    /**
     * Includes the status so a match that reappears in the feed after being
     * auto-cancelled gets a different hash and flips back to 'geplant'.
     */
    private static function syncHash(string $anstoss, string $gegner, string $ortText, string $status): string
    {
        return hash('sha256', $anstoss . '|' . $gegner . '|' . $ortText . '|' . $status);
    }
}
