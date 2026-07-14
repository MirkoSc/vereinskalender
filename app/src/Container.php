<?php

declare(strict_types=1);

namespace App;

use App\Admin\AuthController;
use App\Admin\DashboardController;
use App\Admin\PitchController;
use App\Admin\RebuildController;
use App\Admin\TeamController;
use App\Admin\VenueController;
use App\Api\BookingApiController;
use App\Api\EventsApiController;
use App\Config\Config;
use App\Config\Paths;
use App\Database\ConnectionFactory;
use App\Http\Session;
use App\PublicPages\PublicController;
use App\Repository\AdminRepository;
use App\Repository\MatchRepository;
use App\Repository\PitchRepository;
use App\Repository\PitchRestrictionRepository;
use App\Repository\SettingRepository;
use App\Repository\SlotExceptionRepository;
use App\Repository\TeamRepository;
use App\Repository\TrainingSlotRepository;
use App\Repository\VenueRepository;
use App\Service\Auth\AuthService;
use App\Service\EventStore\EventStore;
use App\Service\EventStore\RebuildService;
use App\Service\EventStore\Replayer;
use App\Service\Kalender\AvailabilityService;
use App\Service\Kalender\BookingService;
use App\Service\Kalender\EventFeedService;
use App\Service\Kalender\RestrictionService;
use App\Service\Kalender\VenueMatcher;
use App\Service\Projection\MatchProjector;
use App\Service\Projection\PitchProjector;
use App\Service\Projection\PitchRestrictionProjector;
use App\Service\Projection\ProjectorRegistry;
use App\Service\Projection\SlotExceptionProjector;
use App\Service\Projection\TeamProjector;
use App\Service\Projection\TrainingSlotProjector;
use App\Service\Projection\VenueBegriffProjector;
use App\Service\Projection\VenueProjector;
use App\Service\Stammdaten\PitchService;
use App\Service\Stammdaten\TeamService;
use App\Service\Stammdaten\VenueService;
use App\Support\Version;
use App\View\View;

/**
 * Hand-wired lazy service container. The PDO connection is only opened
 * when a route actually needs the database.
 */
final class Container
{
    /** @var array<string, object> */
    private array $instances = [];

    public function __construct(
        public readonly Config $config,
        public readonly Paths $paths,
        public readonly Version $version,
    ) {
    }

    public function pdo(): \PDO
    {
        return $this->cached('pdo', fn(): \PDO => ConnectionFactory::create($this->config));
    }

    public function view(): View
    {
        return $this->cached('view', fn(): View => new View($this->paths->viewsDir(), $this->version->value));
    }

    public function session(): Session
    {
        return $this->cached('session', static fn(): Session => new Session());
    }

    public function projectorRegistry(): ProjectorRegistry
    {
        return $this->cached('projectorRegistry', fn(): ProjectorRegistry => new ProjectorRegistry([
            new VenueProjector($this->pdo()),
            new VenueBegriffProjector($this->pdo()),
            new PitchProjector($this->pdo()),
            new TeamProjector($this->pdo()),
            new TrainingSlotProjector($this->pdo()),
            new SlotExceptionProjector($this->pdo()),
            new PitchRestrictionProjector($this->pdo()),
            new MatchProjector($this->pdo()),
        ]));
    }

    public function eventStore(): EventStore
    {
        return $this->cached('eventStore', fn(): EventStore => new EventStore($this->pdo(), $this->projectorRegistry()));
    }

    public function rebuildService(): RebuildService
    {
        return $this->cached('rebuildService', fn(): RebuildService => new RebuildService(
            $this->pdo(),
            $this->projectorRegistry(),
            new Replayer($this->pdo(), $this->projectorRegistry()),
            $this->paths->sharedDir() . '/var/rebuild_state.json',
        ));
    }

    public function teamRepository(): TeamRepository
    {
        return $this->cached('teamRepository', fn(): TeamRepository => new TeamRepository($this->pdo()));
    }

    public function pitchRepository(): PitchRepository
    {
        return $this->cached('pitchRepository', fn(): PitchRepository => new PitchRepository($this->pdo()));
    }

    public function venueRepository(): VenueRepository
    {
        return $this->cached('venueRepository', fn(): VenueRepository => new VenueRepository($this->pdo()));
    }

    public function adminRepository(): AdminRepository
    {
        return $this->cached('adminRepository', fn(): AdminRepository => new AdminRepository($this->pdo()));
    }

    public function settingRepository(): SettingRepository
    {
        return $this->cached('settingRepository', fn(): SettingRepository => new SettingRepository($this->pdo()));
    }

    public function trainingSlotRepository(): TrainingSlotRepository
    {
        return $this->cached('trainingSlotRepository', fn(): TrainingSlotRepository => new TrainingSlotRepository($this->pdo()));
    }

    public function slotExceptionRepository(): SlotExceptionRepository
    {
        return $this->cached('slotExceptionRepository', fn(): SlotExceptionRepository => new SlotExceptionRepository($this->pdo()));
    }

    public function pitchRestrictionRepository(): PitchRestrictionRepository
    {
        return $this->cached('pitchRestrictionRepository', fn(): PitchRestrictionRepository => new PitchRestrictionRepository($this->pdo()));
    }

    public function matchRepository(): MatchRepository
    {
        return $this->cached('matchRepository', fn(): MatchRepository => new MatchRepository($this->pdo()));
    }

    public function venueMatcher(): VenueMatcher
    {
        return $this->cached('venueMatcher', fn(): VenueMatcher => VenueMatcher::fromDatabase($this->pdo()));
    }

    public function bookingService(): BookingService
    {
        return $this->cached('bookingService', fn(): BookingService => new BookingService(
            $this->eventStore(),
            $this->trainingSlotRepository(),
            $this->slotExceptionRepository(),
            $this->pitchRestrictionRepository(),
            $this->matchRepository(),
            $this->teamRepository(),
            $this->pitchRepository(),
        ));
    }

    public function restrictionService(): RestrictionService
    {
        return $this->cached('restrictionService', fn(): RestrictionService => new RestrictionService(
            $this->eventStore(),
            $this->pitchRestrictionRepository(),
            $this->pitchRepository(),
        ));
    }

    public function availabilityService(): AvailabilityService
    {
        return $this->cached('availabilityService', fn(): AvailabilityService => new AvailabilityService(
            $this->trainingSlotRepository(),
            $this->slotExceptionRepository(),
            $this->pitchRestrictionRepository(),
            $this->matchRepository(),
            $this->teamRepository(),
            $this->pitchRepository(),
            $this->venueRepository(),
            $this->settingRepository(),
            $this->venueMatcher(),
        ));
    }

    public function eventFeedService(): EventFeedService
    {
        return $this->cached('eventFeedService', fn(): EventFeedService => new EventFeedService(
            $this->trainingSlotRepository(),
            $this->slotExceptionRepository(),
            $this->pitchRestrictionRepository(),
            $this->matchRepository(),
            $this->teamRepository(),
            $this->pitchRepository(),
            $this->venueRepository(),
            $this->settingRepository(),
            $this->venueMatcher(),
        ));
    }

    public function eventsApiController(): EventsApiController
    {
        return $this->cached('eventsApiController', fn(): EventsApiController => new EventsApiController(
            $this->eventFeedService(),
            $this->availabilityService(),
        ));
    }

    public function bookingApiController(): BookingApiController
    {
        return $this->cached('bookingApiController', fn(): BookingApiController => new BookingApiController(
            $this->session(),
            $this->bookingService(),
            $this->restrictionService(),
        ));
    }

    public function publicController(): PublicController
    {
        return $this->cached('publicController', fn(): PublicController => new PublicController(
            $this->view(),
            $this->teamRepository(),
            $this->pitchRepository(),
            $this->venueRepository(),
            $this->settingRepository(),
        ));
    }

    public function authService(): AuthService
    {
        return $this->cached('authService', fn(): AuthService => new AuthService($this->adminRepository(), $this->config));
    }

    public function teamService(): TeamService
    {
        return $this->cached('teamService', fn(): TeamService => new TeamService($this->eventStore(), $this->teamRepository()));
    }

    public function pitchService(): PitchService
    {
        return $this->cached('pitchService', fn(): PitchService => new PitchService(
            $this->eventStore(),
            $this->pitchRepository(),
            $this->venueRepository(),
        ));
    }

    public function venueService(): VenueService
    {
        return $this->cached('venueService', fn(): VenueService => new VenueService(
            $this->pdo(),
            $this->eventStore(),
            $this->venueRepository(),
            $this->pitchRepository(),
        ));
    }

    public function authController(): AuthController
    {
        return $this->cached('authController', fn(): AuthController => new AuthController(
            $this->view(),
            $this->session(),
            $this->authService(),
        ));
    }

    public function dashboardController(): DashboardController
    {
        return $this->cached('dashboardController', fn(): DashboardController => new DashboardController(
            $this->view(),
            $this->session(),
            $this->teamRepository(),
            $this->pitchRepository(),
            $this->venueRepository(),
            $this->eventStore(),
        ));
    }

    public function teamController(): TeamController
    {
        return $this->cached('teamController', fn(): TeamController => new TeamController(
            $this->view(),
            $this->session(),
            $this->teamRepository(),
            $this->teamService(),
        ));
    }

    public function pitchController(): PitchController
    {
        return $this->cached('pitchController', fn(): PitchController => new PitchController(
            $this->view(),
            $this->session(),
            $this->pitchRepository(),
            $this->venueRepository(),
            $this->pitchService(),
        ));
    }

    public function venueController(): VenueController
    {
        return $this->cached('venueController', fn(): VenueController => new VenueController(
            $this->view(),
            $this->session(),
            $this->venueRepository(),
            $this->pitchRepository(),
            $this->venueService(),
        ));
    }

    public function rebuildController(): RebuildController
    {
        return $this->cached('rebuildController', fn(): RebuildController => new RebuildController(
            $this->view(),
            $this->session(),
            $this->rebuildService(),
        ));
    }

    /**
     * @template T of object
     * @param \Closure(): T $factory
     * @return T
     */
    private function cached(string $key, \Closure $factory): object
    {
        /** @var T */
        return $this->instances[$key] ??= $factory();
    }
}
