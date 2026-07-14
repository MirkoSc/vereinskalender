<?php

declare(strict_types=1);

namespace App\Admin;

use App\Http\Request;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\PitchRepository;
use App\Repository\TeamRepository;
use App\Repository\VenueRepository;
use App\Service\EventStore\EventStore;
use App\View\View;

final class DashboardController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        private readonly TeamRepository $teams,
        private readonly PitchRepository $pitches,
        private readonly VenueRepository $venues,
        private readonly EventStore $eventStore,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): ResponseInterface
    {
        return $this->render('admin/dashboard', [
            'title' => 'Admin',
            'teamCount' => $this->teams->count(),
            'pitchCount' => $this->pitches->count(),
            'venueCount' => $this->venues->count(),
            'eventCount' => $this->eventStore->countActive(),
        ]);
    }
}
