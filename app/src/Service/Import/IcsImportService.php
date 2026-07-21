<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Domain\AggregateType;
use App\Domain\EventContext;
use App\Domain\EventSource;
use App\Domain\EventType;
use App\Repository\ImportSourceRepository;
use App\Repository\MatchRepository;
use App\Repository\SettingRepository;
use App\Repository\TeamHomePitchRepository;
use App\Repository\VenueRepository;
use App\Service\EventStore\EventStore;
use App\Service\Kalender\VenueMatcher;
use App\Service\Stats\AlarmMailer;

/**
 * ICS sync (CLAUDE.md section 7). Per event in the feed, keyed by
 * (import_source_id, ics_uid): unknown -> insert, sync_hash changed ->
 * update (a relocated match moves automatically, the UID stays), unchanged
 * -> skip. Afterwards: UIDs in the DB but missing from the feed are set to
 * status 'abgesagt' - NEVER hard-deleted (also protects against empty
 * feeds). Errors are isolated per source. All writes go through the event
 * store (quelle 'import').
 *
 * Pitch assignment for home matches follows a fixed priority (CLAUDE.md
 * section 6): (1) a manual assignment (pitch_manuell) is never touched -
 * an empty selection resets to automatic; (2) the team_home_pitch rule
 * valid at kickoff (both boundary dates inclusive); (3) the venue's
 * default_pitch_id. Rule changes reach existing FUTURE non-manual matches
 * on the next run even if nothing else changed (a pitch-only Updated event
 * that reuses the same sync_hash, so the run after that is idempotent);
 * past matches are never reflowed.
 *
 * The cancel follow-up (Issue #48) only ever cancels matches whose kickoff
 * is strictly after the import moment - a match that has already started
 * (kickoff <= now, boundary included) is left alone even if its UID is
 * missing from the feed, since feeds commonly drop past events and that is
 * not an actual cancellation.
 *
 * Spielfrei detection (Issue #65): a feed entry is a bye, not a match, when
 * BOTH conditions hold - an empty LOCATION AND a configured keyword
 * (case-insensitive, several allowed) appears in the raw SUMMARY. Either
 * condition alone is not enough (an away match without a maintained
 * LOCATION is not a bye). Unlike pitch reflow, this is re-derived for EVERY
 * feed entry on every run, past included: the flag classifies immutable
 * feed content, so re-deriving it corrects a misclassification rather than
 * rewriting history (a stored pitch_id, by contrast, records where a match
 * actually happened). The keyword is not part of sync_hash, so a
 * settings-only change would reach no row without the extra
 * `$spielfrei === $storedSpielfrei` skip clause below (same idiom as the
 * pitch-reflow `$pitchId === $stored` clause); a second run after a keyword
 * change is idempotent.
 */
final readonly class IcsImportService
{
    private const string EDITOR_NAME = 'ICS-Import';

    public function __construct(
        private EventStore $eventStore,
        private ImportSourceRepository $sources,
        private MatchRepository $matches,
        private VenueRepository $venues,
        private TeamHomePitchRepository $homePitchRules,
        private VenueMatcher $venueMatcher,
        private IcsFeedFetcher $fetcher,
        private SettingRepository $settings,
        private ?AlarmMailer $alarmMailer = null,
        private ?\DateTimeImmutable $now = null,
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

            $this->alarmMailer?->alert(
                'importfehler',
                'ICS-Import fehlgeschlagen',
                sprintf(
                    "Import-Quelle #%d meldet einen Fehler:\n\n%s\n\nURL: %s\n",
                    $sourceId,
                    $e->getMessage(),
                    (string) $source['ics_url'],
                ),
            );

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

        $rules = $this->homePitchRules->findByTeam($teamId);
        $today = new \DateTimeImmutable('today')->format('Y-m-d H:i:s');
        $nowStr = ($this->now ?? new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $spielfreiBegriffe = self::spielfreiBegriffe($this->settings->get('spielfrei_begriffe', 'Spielfrei'));

        $inserted = $updated = $skipped = 0;
        $feedUids = [];

        foreach ($icsEvents as $icsEvent) {
            $feedUids[$icsEvent->uid] = true;

            $anstoss = $icsEvent->start->format('Y-m-d H:i:s');
            $gegner = mb_substr($icsEvent->summary, 0, 150);
            $ortText = mb_substr($icsEvent->location, 0, 255);
            $status = $icsEvent->cancelled ? 'abgesagt' : 'geplant';
            $syncHash = self::syncHash($anstoss, $gegner, $ortText, $status);
            $spielfrei = self::istSpielfrei($ortText, $icsEvent->summary, $spielfreiBegriffe);

            $existing = $this->matches->findBySourceAndUid($sourceId, $icsEvent->uid);
            $storedSpielfrei = $existing !== null && (int) $existing['spielfrei'] === 1;

            // home detection at import time; the pitch itself is NOT in the
            // ICS. Assignment priority: (1) manual assignment is never
            // touched; (2) the team_home_pitch rule valid at kickoff;
            // (3) the venue's default pitch. Past, non-manual updates keep
            // the legacy location-text heuristic and are never reflowed by
            // rule changes.
            $venueId = $this->venueMatcher->match($ortText);
            $isHome = $venueId !== null;
            $isFuture = $anstoss >= $today;
            $manuell = $existing !== null && (int) $existing['pitch_manuell'] === 1;
            $stored = $existing !== null && $existing['pitch_id'] !== null ? (int) $existing['pitch_id'] : null;

            if ($manuell) {
                $pitchId = $stored;
            } elseif ($existing === null || $isFuture) {
                $pitchId = $isHome
                    ? (self::rulePitchForKickoff($rules, $anstoss) ?? $defaultPitchByVenue[$venueId] ?? null)
                    : null;
            } else {
                $pitchId = (string) $existing['ort_text'] === $ortText
                    ? $stored
                    : ($isHome ? ($defaultPitchByVenue[$venueId] ?? null) : null);
            }

            // skip only when nothing relevant changed: same hash AND the
            // desired pitch already matches what's stored AND spielfrei is
            // unchanged (a rule/keyword change must still reach the row even
            // though the hash stays equal - Issue #65).
            if ($existing !== null && (string) $existing['sync_hash'] === $syncHash
                && $pitchId === $stored && $spielfrei === $storedSpielfrei) {
                $skipped++;
                continue;
            }

            $payload = [
                'team_id' => $teamId,
                'anstoss' => $anstoss,
                'ende' => null,
                'gegner' => $gegner,
                'heimspiel' => $isHome,
                'spielfrei' => $spielfrei,
                'ort_text' => $ortText,
                'pitch_id' => $pitchId,
                'pitch_manuell' => $manuell,
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
        // cancelled (never deleted); matches that have already started
        // (kickoff <= import moment, boundary included) are left untouched,
        // some feeds drop past events (Issue #48)
        $cancelled = 0;
        foreach ($this->matches->findBySource($sourceId) as $match) {
            if (isset($feedUids[(string) $match['ics_uid']])
                || (string) $match['status'] === 'abgesagt'
                || (string) $match['anstoss'] <= $nowStr) {
                continue;
            }

            $payload = [
                'team_id' => (int) $match['team_id'],
                'anstoss' => (string) $match['anstoss'],
                'ende' => $match['ende'] !== null ? (string) $match['ende'] : null,
                'gegner' => (string) $match['gegner'],
                'heimspiel' => (int) $match['heimspiel'] === 1,
                'spielfrei' => (int) $match['spielfrei'] === 1,
                'ort_text' => (string) $match['ort_text'],
                'pitch_id' => $match['pitch_id'] !== null ? (int) $match['pitch_id'] : null,
                'pitch_manuell' => (int) $match['pitch_manuell'] === 1,
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
     * First team_home_pitch rule whose validity range contains the kickoff
     * date, both gueltig_ab and gueltig_bis inclusive. The per-team overlap
     * validation in TeamHomePitchService guarantees at most one match.
     *
     * @param list<array<string, mixed>> $rules
     */
    private static function rulePitchForKickoff(array $rules, string $anstoss): ?int
    {
        $datum = substr($anstoss, 0, 10);
        foreach ($rules as $rule) {
            if ((string) $rule['gueltig_ab'] <= $datum && $datum <= (string) $rule['gueltig_bis']) {
                return (int) $rule['pitch_id'];
            }
        }

        return null;
    }

    /**
     * Includes the status so a match that reappears in the feed after being
     * auto-cancelled gets a different hash and flips back to 'geplant'.
     */
    private static function syncHash(string $anstoss, string $gegner, string $ortText, string $status): string
    {
        return hash('sha256', $anstoss . '|' . $gegner . '|' . $ortText . '|' . $status);
    }

    /**
     * @return list<string> non-empty, trimmed keywords; an empty setting
     *         yields an empty list, which turns detection off rather than
     *         matching everything (mb_stripos($s, '') would otherwise be 0,
     *         i.e. a false "match", for every SUMMARY).
     */
    private static function spielfreiBegriffe(string $setting): array
    {
        return array_values(array_filter(
            array_map(trim(...), explode(',', $setting)),
            static fn(string $begriff): bool => $begriff !== '',
        ));
    }

    /**
     * Issue #65: a bye, not a match - BOTH conditions required: an empty
     * LOCATION and a configured keyword (case-insensitive) in the raw
     * SUMMARY. An away match without a maintained LOCATION must not be
     * misclassified, so a keyword hit alone is never enough.
     *
     * @param list<string> $begriffe
     */
    private static function istSpielfrei(string $ortText, string $summary, array $begriffe): bool
    {
        if (trim($ortText) !== '') {
            return false;
        }

        foreach ($begriffe as $begriff) {
            if (mb_stripos($summary, $begriff) !== false) {
                return true;
            }
        }

        return false;
    }
}
