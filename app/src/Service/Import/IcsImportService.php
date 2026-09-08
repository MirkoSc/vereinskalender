<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Domain\AggregateType;
use App\Domain\EventContext;
use App\Domain\EventSource;
use App\Domain\EventType;
use App\Repository\ImportSourceRepository;
use App\Repository\MatchRepository;
use App\Repository\PitchRepository;
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
 * status 'abgesagt' - hard-deleted only as the feed-rebuild exception
 * documented below, never for a genuine absence (also protects against
 * empty feeds). Errors are isolated per source. All writes go through the
 * event store (quelle 'import').
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
 *
 * Feed rebuild (a source occasionally reassigns every VEVENT a fresh UID):
 * without special handling this reads as "every match cancelled, every
 * match re-created" - a duplicate at the exact same kickoff, one row
 * abgesagt and one geplant, plus a spurious "Spielverlegung/-absage" push
 * for each. A live feed entry (this run) sharing the SAME kickoff as a
 * stale UID (gone from the feed) is treated as its replacement rather than
 * an unrelated coincidence: the stale row is hard-deleted instead of
 * cancelled, strictly future kickoffs only (same Issue #48 boundary as the
 * ordinary cancel path, so a feed that merely drops past events never loses
 * history). This runs AFTER the "already abgesagt" state would otherwise
 * short-circuit it, so it also retroactively cleans up duplicates an
 * earlier run already created before this behaviour existed - the fix
 * heals itself on the next run. A manual pitch assignment on the deleted
 * row moves to its replacement, but only within the same venue (an
 * away/other-venue replacement keeps its automatically derived pitch).
 */
final readonly class IcsImportService
{
    private const string EDITOR_NAME = 'ICS-Import';

    public function __construct(
        private EventStore $eventStore,
        private ImportSourceRepository $sources,
        private MatchRepository $matches,
        private VenueRepository $venues,
        private PitchRepository $pitches,
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

        // pitch -> venue, only needed to decide whether a manual pitch
        // assignment may move onto a feed-rebuild replacement (same venue)
        $venueByPitch = [];
        foreach ($this->pitches->findAll() as $pitch) {
            $venueByPitch[(int) $pitch['id']] = (int) $pitch['venue_id'];
        }

        $rules = $this->homePitchRules->findByTeam($teamId);
        $today = new \DateTimeImmutable('today')->format('Y-m-d H:i:s');
        $nowStr = ($this->now ?? new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $spielfreiBegriffe = self::spielfreiBegriffe($this->settings->get('spielfrei_begriffe', 'Spielfrei'));

        // One query for the source's whole stock instead of one lookup per
        // feed entry: a season feed has dozens of events per team and the
        // cancel follow-up below needed the full list anyway. Keyed by UID,
        // which is unique per source (UNIQUE(import_source_id, ics_uid)).
        $bestand = [];
        foreach ($this->matches->findBySource($sourceId) as $row) {
            $bestand[(string) $row['ics_uid']] = $row;
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
            $spielfrei = self::istSpielfrei($ortText, $icsEvent->summary, $spielfreiBegriffe);

            $existing = $bestand[$icsEvent->uid] ?? null;
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
                $event = $this->eventStore->append(AggregateType::Match, null, EventType::Created, $payload, $context);
                $matchId = $event->aggregateId;
                $inserted++;
            } else {
                $matchId = (int) $existing['id'];
                $this->eventStore->append(AggregateType::Match, $matchId, EventType::Updated, $payload, $context);
                $updated++;
            }

            // Keep the stock in step with what was just written. A feed CAN
            // repeat a UID - IcsParser does not collapse RECURRENCE-ID
            // overrides - and a second occurrence has to update the row the
            // first one created. Re-reading per entry used to hide this;
            // against a stale snapshot it would insert twice and die on
            // UNIQUE(import_source_id, ics_uid), failing the whole source.
            // Payload keys are the column names (projection contract), so the
            // payload doubles as a row once the id is added.
            $bestand[$icsEvent->uid] = ['id' => $matchId, ...$payload];
        }

        // A feed rebuild reassigns every VEVENT a fresh UID. A CURRENT,
        // active (non-abgesagt) feed entry sharing the exact same kickoff as
        // a stale UID is its replacement, not an unrelated coincidence - one
        // import_source is one team, so the kickoff alone identifies the
        // fixture. Built from $bestand (already current after the loop
        // above), not $feedUids alone, because it needs each candidate's
        // anstoss/status/pitch, not just its uid.
        $ersatzUidNachAnstoss = [];
        foreach ($bestand as $row) {
            if (isset($feedUids[(string) $row['ics_uid']]) && (string) $row['status'] !== 'abgesagt') {
                $ersatzUidNachAnstoss[(string) $row['anstoss']] ??= (string) $row['ics_uid'];
            }
        }

        // follow-up: a future match whose UID vanished from the feed is
        // either the stale half of a feed rebuild (deleted, see above) or a
        // genuine cancellation (marked abgesagt, never deleted). A match
        // that has already started (kickoff <= import moment, boundary
        // included) is left untouched either way - some feeds drop past
        // events (Issue #48), and a past duplicate is not proof of a
        // rebuild. The rebuild check runs BEFORE the "already abgesagt"
        // skip, so it also retroactively deletes a duplicate an earlier run
        // (before this behaviour existed) already cancelled.
        //
        // Iterates the stock loaded above instead of re-querying. Same
        // result: every row this run touched carries a UID that IS in the
        // feed, so it is skipped here either way, and rows the run did not
        // touch read identically before and after. Order is unchanged too -
        // the pre-existing entries keep the query's ORDER BY anstoss, and
        // the freshly created ones appended at the end are always skipped.
        $cancelled = 0;
        $deleted = 0;
        foreach ($bestand as $match) {
            if (isset($feedUids[(string) $match['ics_uid']])
                || (string) $match['anstoss'] <= $nowStr) {
                continue;
            }

            $ersatzUid = $ersatzUidNachAnstoss[(string) $match['anstoss']] ?? null;
            if ($ersatzUid !== null) {
                $altPitch = $match['pitch_id'] !== null ? (int) $match['pitch_id'] : null;
                if ((int) $match['pitch_manuell'] === 1 && $altPitch !== null) {
                    $ersatz = $bestand[$ersatzUid];
                    $ersatzPitch = $ersatz['pitch_id'] !== null ? (int) $ersatz['pitch_id'] : null;
                    $altVenue = $venueByPitch[$altPitch] ?? null;
                    // only within the same venue: an away/other-venue
                    // replacement keeps its automatically derived pitch
                    // instead of inheriting one from a different location
                    if ($ersatzPitch !== $altPitch && $altVenue !== null
                        && $altVenue === $this->venueMatcher->match((string) $ersatz['ort_text'])) {
                        $ersatzPayload = self::rowPayload($ersatz, $sourceId, [
                            'pitch_id' => $altPitch,
                            'pitch_manuell' => true,
                        ]);
                        $this->eventStore->append(AggregateType::Match, (int) $ersatz['id'], EventType::Updated, $ersatzPayload, $context);
                        $bestand[$ersatzUid] = ['id' => (int) $ersatz['id'], ...$ersatzPayload];
                        $updated++;
                    }
                }

                $this->eventStore->append(
                    AggregateType::Match,
                    (int) $match['id'],
                    EventType::Deleted,
                    self::rowPayload($match, $sourceId),
                    $context,
                );
                $deleted++;
                continue;
            }

            if ((string) $match['status'] === 'abgesagt') {
                continue;
            }

            $payload = self::rowPayload($match, $sourceId, [
                'status' => 'abgesagt',
                'sync_hash' => self::syncHash(
                    (string) $match['anstoss'],
                    (string) $match['gegner'],
                    (string) $match['ort_text'],
                    'abgesagt',
                ),
            ]);
            $this->eventStore->append(AggregateType::Match, (int) $match['id'], EventType::Updated, $payload, $context);
            $cancelled++;
        }

        return new ImportSourceResult(
            $sourceId,
            ok: true,
            inserted: $inserted,
            updated: $updated,
            cancelled: $cancelled,
            deleted: $deleted,
            skipped: $skipped,
        );
    }

    /**
     * Full-picture payload from a $bestand row (mixed PDO-string / native
     * types, see the comment at $bestand above) - same shape as
     * MatchService::rowPayload(). $overrides replaces individual columns,
     * e.g. a recomputed sync_hash when the status changes.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function rowPayload(array $row, int $sourceId, array $overrides = []): array
    {
        return [
            'team_id' => (int) $row['team_id'],
            'anstoss' => (string) $row['anstoss'],
            'ende' => $row['ende'] !== null ? (string) $row['ende'] : null,
            'gegner' => (string) $row['gegner'],
            'heimspiel' => (int) $row['heimspiel'] === 1,
            'spielfrei' => (int) $row['spielfrei'] === 1,
            'ort_text' => (string) $row['ort_text'],
            'pitch_id' => $row['pitch_id'] !== null ? (int) $row['pitch_id'] : null,
            'pitch_manuell' => (int) $row['pitch_manuell'] === 1,
            'status' => (string) $row['status'],
            'import_source_id' => $sourceId,
            'ics_uid' => (string) $row['ics_uid'],
            'ics_sequence' => (int) $row['ics_sequence'],
            'sync_hash' => (string) $row['sync_hash'],
            ...$overrides,
        ];
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
