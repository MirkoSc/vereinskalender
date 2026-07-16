<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\TeamHomePitchRepository;
use App\Service\ValidationException;
use App\Tests\Support\DatabaseTestCase;

/**
 * Issue #10: seasonal home pitch rules per team, gueltig_ab/gueltig_bis are
 * both inclusive, so a team's rules must never overlap - not even on a
 * shared boundary day (CLAUDE.md section 3).
 */
final class TeamHomePitchServiceTest extends DatabaseTestCase
{
    public function testCreatePersistsRuleAsEventAndProjection(): void
    {
        $venueId = $this->createVenue();
        $pitchId = $this->createPitch($venueId);
        $teamId = $this->createTeam();

        $id = $this->teamHomePitchService()->create([
            'team_id' => (string) $teamId,
            'pitch_id' => (string) $pitchId,
            'gueltig_ab' => '2026-08-01',
            'gueltig_bis' => '2026-11-30',
        ], $this->context());

        $row = new TeamHomePitchRepository($this->pdo())->find($id);
        self::assertNotNull($row);
        self::assertSame($teamId, (int) $row['team_id']);
        self::assertSame($pitchId, (int) $row['pitch_id']);
        self::assertSame('2026-08-01', $row['gueltig_ab']);
        self::assertSame('2026-11-30', $row['gueltig_bis']);

        $event = $this->pdo()
            ->query('SELECT * FROM event WHERE aggregat_typ = "team_home_pitch" ORDER BY id DESC LIMIT 1')
            ->fetch();
        self::assertSame('created', $event['event_typ']);
        $payload = json_decode((string) $event['payload'], true);
        self::assertSame($teamId, $payload['team_id']);
        self::assertSame($pitchId, $payload['pitch_id']);
    }

    public function testOverlapIncludingSharedBoundaryDayIsRejected(): void
    {
        $venueId = $this->createVenue();
        $pitchA = $this->createPitch($venueId, 'Platz A');
        $pitchB = $this->createPitch($venueId, 'Platz B');
        $teamId = $this->createTeam();

        $this->createHomePitchRule($teamId, $pitchA, '2026-08-01', '2026-11-30');

        try {
            $this->teamHomePitchService()->create([
                'team_id' => (string) $teamId,
                'pitch_id' => (string) $pitchB,
                'gueltig_ab' => '2026-11-30',
                'gueltig_bis' => '2027-06-01',
            ], $this->context());
            self::fail('expected ValidationException for shared boundary day');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('gueltig_ab', $e->getErrors());
        }
    }

    public function testAdjacentRuleStartingTheDayAfterIsAccepted(): void
    {
        $venueId = $this->createVenue();
        $pitchA = $this->createPitch($venueId, 'Platz A');
        $pitchB = $this->createPitch($venueId, 'Platz B');
        $teamId = $this->createTeam();

        $this->createHomePitchRule($teamId, $pitchA, '2026-08-01', '2026-11-30');

        $id = $this->teamHomePitchService()->create([
            'team_id' => (string) $teamId,
            'pitch_id' => (string) $pitchB,
            'gueltig_ab' => '2026-12-01',
            'gueltig_bis' => '2027-06-01',
        ], $this->context());

        self::assertGreaterThan(0, $id);
    }

    public function testOverlapOnlyCheckedPerTeam(): void
    {
        $venueId = $this->createVenue();
        $pitchId = $this->createPitch($venueId);
        $teamA = $this->createTeam('E1');
        $teamB = $this->createTeam('E2');

        $this->createHomePitchRule($teamA, $pitchId, '2026-08-01', '2026-11-30');

        $id = $this->teamHomePitchService()->create([
            'team_id' => (string) $teamB,
            'pitch_id' => (string) $pitchId,
            'gueltig_ab' => '2026-08-01',
            'gueltig_bis' => '2026-11-30',
        ], $this->context());

        self::assertGreaterThan(0, $id);
    }

    public function testValidationErrors(): void
    {
        $venueId = $this->createVenue();
        $pitchId = $this->createPitch($venueId);
        $teamId = $this->createTeam();

        try {
            $this->teamHomePitchService()->create([
                'team_id' => (string) $teamId,
                'pitch_id' => '999999',
                'gueltig_ab' => '2026-08-01',
                'gueltig_bis' => '2026-11-30',
            ], $this->context());
            self::fail('expected ValidationException for unknown pitch');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('pitch_id', $e->getErrors());
        }

        try {
            $this->teamHomePitchService()->create([
                'team_id' => (string) $teamId,
                'pitch_id' => (string) $pitchId,
                'gueltig_ab' => '2026-11-30',
                'gueltig_bis' => '2026-08-01',
            ], $this->context());
            self::fail('expected ValidationException for ab > bis');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('gueltig_ab', $e->getErrors());
        }

        try {
            $this->teamHomePitchService()->create([
                'team_id' => (string) $teamId,
                'pitch_id' => (string) $pitchId,
                'gueltig_ab' => 'nicht-ein-datum',
                'gueltig_bis' => '2026-11-30',
            ], $this->context());
            self::fail('expected ValidationException for malformed date');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('gueltig_ab', $e->getErrors());
        }

        try {
            $this->teamHomePitchService()->create([
                'team_id' => (string) $teamId,
                'pitch_id' => (string) $pitchId,
                'gueltig_ab' => '2026-01-01',
                'gueltig_bis' => '2028-01-01',
            ], $this->context());
            self::fail('expected ValidationException for range > 400 days');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('gueltig_bis', $e->getErrors());
        }
    }

    public function testDeleteRemovesRowAndAppendsDeletedEvent(): void
    {
        $venueId = $this->createVenue();
        $pitchId = $this->createPitch($venueId);
        $teamId = $this->createTeam();
        $id = $this->createHomePitchRule($teamId, $pitchId, '2026-08-01', '2026-11-30');

        $this->teamHomePitchService()->delete($id, $this->context());

        self::assertNull(new TeamHomePitchRepository($this->pdo())->find($id));

        $event = $this->pdo()
            ->query('SELECT * FROM event WHERE aggregat_typ = "team_home_pitch" ORDER BY id DESC LIMIT 1')
            ->fetch();
        self::assertSame('deleted', $event['event_typ']);
    }

    public function testDeleteUnknownIdIsRejected(): void
    {
        try {
            $this->teamHomePitchService()->delete(999999, $this->context());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('id', $e->getErrors());
        }
    }
}
