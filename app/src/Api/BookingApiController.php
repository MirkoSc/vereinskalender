<?php

declare(strict_types=1);

namespace App\Api;

use App\Domain\EventContext;
use App\Domain\EventSource;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Service\Kalender\BookingService;
use App\Service\Kalender\ConflictException;
use App\Service\Kalender\RestrictionService;
use App\Service\ValidationException;

/**
 * Public write path (CLAUDE.md section 6): every write carries an
 * editor_name from localStorage; the server rejects writes without a name
 * but deliberately does not verify it further. Everything is traceable and
 * revertible through the event history.
 */
final readonly class BookingApiController
{
    public function __construct(
        private Session $session,
        private BookingService $booking,
        private RestrictionService $restrictions,
    ) {
    }

    public function csrf(Request $request): Response
    {
        return Response::json(['token' => $this->session->csrfToken()]);
    }

    public function check(Request $request): Response
    {
        try {
            $result = $this->booking->check(
                $request->post,
                ($request->post['slot_id'] ?? '') !== '' ? (int) $request->post['slot_id'] : null,
            );

            return Response::json(['konflikte' => $result->conflicts, 'warnungen' => $result->warnings]);
        } catch (ValidationException $e) {
            return Response::json(['fehler' => $e->getErrors()], 422);
        }
    }

    public function createSlot(Request $request): Response
    {
        try {
            $result = $this->booking->createSlot($request->post, $this->context($request));

            return Response::json(['id' => $result['id'], 'warnungen' => $result['warnings']], 201);
        } catch (ValidationException $e) {
            return Response::json(['fehler' => $e->getErrors()], 422);
        } catch (ConflictException $e) {
            return Response::json(['konflikte' => $e->getConflicts()], 409);
        }
    }

    /**
     * @param array<string, string> $params
     */
    public function updateSlot(Request $request, array $params): Response
    {
        try {
            $result = $this->booking->updateSlot((int) $params['id'], $request->post, $this->context($request));

            return Response::json(['id' => $result['id'], 'warnungen' => $result['warnings']]);
        } catch (ValidationException $e) {
            return Response::json(['fehler' => $e->getErrors()], 422);
        } catch (ConflictException $e) {
            return Response::json(['konflikte' => $e->getConflicts()], 409);
        }
    }

    /**
     * @param array<string, string> $params
     */
    public function deleteSlot(Request $request, array $params): Response
    {
        try {
            $this->booking->deleteSlot((int) $params['id'], $this->context($request));

            return Response::json(['ok' => true]);
        } catch (ValidationException $e) {
            return Response::json(['fehler' => $e->getErrors()], 422);
        }
    }

    /**
     * @param array<string, string> $params
     */
    public function addException(Request $request, array $params): Response
    {
        try {
            $id = $this->booking->addException((int) $params['id'], $request->post, $this->context($request));

            return Response::json(['id' => $id], 201);
        } catch (ValidationException $e) {
            return Response::json(['fehler' => $e->getErrors()], 422);
        }
    }

    /**
     * @param array<string, string> $params
     */
    public function deleteException(Request $request, array $params): Response
    {
        try {
            $this->booking->deleteException((int) $params['id'], $this->context($request));

            return Response::json(['ok' => true]);
        } catch (ValidationException $e) {
            return Response::json(['fehler' => $e->getErrors()], 422);
        }
    }

    public function createRestriction(Request $request): Response
    {
        try {
            $id = $this->restrictions->create($request->post, $this->context($request));

            return Response::json(['id' => $id], 201);
        } catch (ValidationException $e) {
            return Response::json(['fehler' => $e->getErrors()], 422);
        }
    }

    /**
     * @param array<string, string> $params
     */
    public function deleteRestriction(Request $request, array $params): Response
    {
        try {
            $this->restrictions->delete((int) $params['id'], $this->context($request));

            return Response::json(['ok' => true]);
        } catch (ValidationException $e) {
            return Response::json(['fehler' => $e->getErrors()], 422);
        }
    }

    private function context(Request $request): EventContext
    {
        return new EventContext(
            trim((string) ($request->post['editor_name'] ?? '')),
            $request->ip,
            EventSource::Web,
        );
    }
}
