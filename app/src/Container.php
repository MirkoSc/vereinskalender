<?php

declare(strict_types=1);

namespace App;

use App\Admin\AuthController;
use App\Admin\BackupController;
use App\Admin\DashboardController;
use App\Admin\EventHistoryController;
use App\Admin\ImportSourceController;
use App\Admin\PageAdminController;
use App\Admin\PitchController;
use App\Admin\RebuildController;
use App\Admin\SaisonController;
use App\Admin\SettingsController;
use App\Admin\TeamController;
use App\Admin\UpdateController;
use App\Admin\VenueController;
use App\Api\BookingApiController;
use App\Api\CronController;
use App\Api\EventsApiController;
use App\Api\ExportController;
use App\Api\PushApiController;
use App\Api\StatController;
use App\Config\Config;
use App\Config\Paths;
use App\Database\ConnectionFactory;
use App\Http\Session;
use App\PublicPages\PublicController;
use App\Repository\AdminRepository;
use App\Repository\EventHistoryRepository;
use App\Repository\ImportSourceRepository;
use App\Repository\MatchRepository;
use App\Repository\NotificationQueueRepository;
use App\Repository\PageRepository;
use App\Repository\PitchRepository;
use App\Repository\PitchRestrictionRepository;
use App\Repository\PushSubscriptionRepository;
use App\Repository\SettingRepository;
use App\Repository\SlotExceptionRepository;
use App\Repository\TeamHomePitchRepository;
use App\Repository\TeamRepository;
use App\Repository\TrainingSlotRepository;
use App\Repository\UsageStatRepository;
use App\Repository\VenueRepository;
use App\Service\Auth\AuthService;
use App\Service\Backup\BackupService;
use App\Service\EventStore\EventStore;
use App\Service\EventStore\RebuildService;
use App\Service\EventStore\Replayer;
use App\Service\Export\IcsExporter;
use App\Service\Import\HttpIcsFeedFetcher;
use App\Service\Import\IcsImportService;
use App\Service\Import\ImportSourceService;
use App\Service\Kalender\AvailabilityService;
use App\Service\Kalender\BookingService;
use App\Service\Kalender\EventFeedService;
use App\Service\Kalender\MatchService;
use App\Service\Kalender\OfflineBundleService;
use App\Service\Kalender\RestrictionService;
use App\Service\Kalender\VenueMatcher;
use App\Service\Push\NotificationTrigger;
use App\Service\Push\PushSender;
use App\Service\RateLimiter;
use App\Service\Saison\SaisonService;
use App\Service\Stats\AlarmMailer;
use App\Service\Projection\ImportSourceProjector;
use App\Service\Projection\MatchProjector;
use App\Service\Projection\PitchProjector;
use App\Service\Projection\PitchRestrictionProjector;
use App\Service\Projection\ProjectorRegistry;
use App\Service\Projection\SlotExceptionProjector;
use App\Service\Projection\TeamHomePitchProjector;
use App\Service\Projection\TeamProjector;
use App\Service\Projection\TrainingSlotProjector;
use App\Service\Projection\VenueBegriffProjector;
use App\Service\Projection\VenueProjector;
use App\Service\Migration\Migrator;
use App\Service\Stammdaten\PitchService;
use App\Service\Stammdaten\TeamHomePitchService;
use App\Service\Stammdaten\TeamService;
use App\Service\Stammdaten\VenueService;
use App\Service\Update\ReleaseDownloader;
use App\Service\Update\ReleaseSwitcher;
use App\Service\Update\UpdateService;
use App\Service\Wappen\WappenService;
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
        return $this->cached('view', fn(): View => new View(
            $this->paths->viewsDir(),
            $this->version->value,
            $this->wappenService()->exists(),
            $this->wappenService()->version(),
        ));
    }

    public function wappenService(): WappenService
    {
        return $this->cached('wappenService', fn(): WappenService => new WappenService($this->paths->wappenDir()));
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
            new ImportSourceProjector($this->pdo()),
            new MatchProjector($this->pdo()),
            new TeamHomePitchProjector($this->pdo()),
        ]));
    }

    public function eventStore(): EventStore
    {
        return $this->cached('eventStore', fn(): EventStore => new EventStore(
            $this->pdo(),
            $this->projectorRegistry(),
            fn(\App\Domain\StoredEvent $event) => $this->notificationTrigger()->afterEventInsert($event),
        ));
    }

    public function notificationTrigger(): NotificationTrigger
    {
        return $this->cached('notificationTrigger', fn(): NotificationTrigger => new NotificationTrigger(
            $this->pdo(),
            $this->notificationQueueRepository(),
        ));
    }

    public function notificationQueueRepository(): NotificationQueueRepository
    {
        return $this->cached('notificationQueueRepository', fn(): NotificationQueueRepository => new NotificationQueueRepository($this->pdo()));
    }

    public function pushSubscriptionRepository(): PushSubscriptionRepository
    {
        return $this->cached('pushSubscriptionRepository', fn(): PushSubscriptionRepository => new PushSubscriptionRepository($this->pdo()));
    }

    public function pushSender(): PushSender
    {
        return $this->cached('pushSender', fn(): PushSender => new PushSender(
            $this->pushSubscriptionRepository(),
            $this->notificationQueueRepository(),
            $this->teamRepository(),
            $this->pitchRepository(),
            $this->paths->sharedDir() . '/vapid.json',
        ));
    }

    public function usageStatRepository(): UsageStatRepository
    {
        return $this->cached('usageStatRepository', fn(): UsageStatRepository => new UsageStatRepository($this->pdo()));
    }

    public function pageRepository(): PageRepository
    {
        return $this->cached('pageRepository', fn(): PageRepository => new PageRepository($this->pdo()));
    }

    public function eventHistoryRepository(): EventHistoryRepository
    {
        return $this->cached('eventHistoryRepository', fn(): EventHistoryRepository => new EventHistoryRepository($this->pdo()));
    }

    public function rateLimiter(): RateLimiter
    {
        return $this->cached('rateLimiter', fn(): RateLimiter => new RateLimiter($this->pdo()));
    }

    public function alarmMailer(): AlarmMailer
    {
        return $this->cached('alarmMailer', fn(): AlarmMailer => AlarmMailer::withPhpMail($this->settingRepository()));
    }

    public function icsExporter(): IcsExporter
    {
        return $this->cached('icsExporter', fn(): IcsExporter => new IcsExporter(
            $this->teamRepository(),
            $this->matchRepository(),
            $this->trainingSlotRepository(),
            $this->slotExceptionRepository(),
            $this->pitchRepository(),
        ));
    }

    public function offlineBundleService(): OfflineBundleService
    {
        return $this->cached('offlineBundleService', fn(): OfflineBundleService => new OfflineBundleService(
            $this->eventFeedService(),
            $this->availabilityService(),
            $this->teamRepository(),
            $this->venueRepository(),
            $this->pitchRepository(),
            $this->settingRepository(),
        ));
    }

    public function saisonService(): SaisonService
    {
        return $this->cached('saisonService', fn(): SaisonService => new SaisonService(
            $this->pdo(),
            $this->trainingSlotRepository(),
            $this->bookingService(),
            $this->teamHomePitchService(),
        ));
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

    public function teamHomePitchRepository(): TeamHomePitchRepository
    {
        return $this->cached('teamHomePitchRepository', fn(): TeamHomePitchRepository => new TeamHomePitchRepository($this->pdo()));
    }

    public function matchRepository(): MatchRepository
    {
        return $this->cached('matchRepository', fn(): MatchRepository => new MatchRepository($this->pdo()));
    }

    public function backupService(): BackupService
    {
        return $this->cached('backupService', fn(): BackupService => new BackupService(
            $this->pdo(),
            $this->paths->sharedDir() . '/var/backups',
            $this->paths->configFile(),
            $this->version->value,
            $this->wappenService(),
        ));
    }

    public function updateService(): UpdateService
    {
        return $this->cached('updateService', fn(): UpdateService => new UpdateService(
            $this->paths,
            $this->version->value,
            $this->settingRepository(),
            $this->backupService(),
            new ReleaseDownloader(),
            new ReleaseSwitcher(dirname($this->paths->releaseRoot)),
            new Migrator($this->pdo(), $this->paths->migrationsDir()),
            $this->alarmMailer(),
        ));
    }

    public function updateController(): UpdateController
    {
        return $this->cached('updateController', fn(): UpdateController => new UpdateController(
            $this->view(),
            $this->session(),
            $this->updateService(),
        ));
    }

    public function backupController(): BackupController
    {
        return $this->cached('backupController', fn(): BackupController => new BackupController(
            $this->view(),
            $this->session(),
            $this->backupService(),
        ));
    }

    public function importSourceRepository(): ImportSourceRepository
    {
        return $this->cached('importSourceRepository', fn(): ImportSourceRepository => new ImportSourceRepository($this->pdo()));
    }

    public function importSourceService(): ImportSourceService
    {
        return $this->cached('importSourceService', fn(): ImportSourceService => new ImportSourceService(
            $this->eventStore(),
            $this->importSourceRepository(),
            $this->teamRepository(),
        ));
    }

    public function icsImportService(): IcsImportService
    {
        return $this->cached('icsImportService', fn(): IcsImportService => new IcsImportService(
            $this->eventStore(),
            $this->importSourceRepository(),
            $this->matchRepository(),
            $this->venueRepository(),
            $this->teamHomePitchRepository(),
            $this->venueMatcher(),
            new HttpIcsFeedFetcher(),
            $this->alarmMailer(),
        ));
    }

    public function matchService(): MatchService
    {
        return $this->cached('matchService', fn(): MatchService => new MatchService(
            $this->eventStore(),
            $this->matchRepository(),
            $this->pitchRepository(),
            $this->teamRepository(),
            $this->venueMatcher(),
            $this->bookingService(),
        ));
    }

    public function cronController(): CronController
    {
        return $this->cached('cronController', fn(): CronController => new CronController(
            $this->config,
            $this->icsImportService(),
            $this->pushSender(),
            $this->eventHistoryRepository(),
            $this->settingRepository(),
            $this->rateLimiter(),
        ));
    }

    public function exportController(): ExportController
    {
        return $this->cached('exportController', fn(): ExportController => new ExportController(
            $this->icsExporter(),
            $this->usageStatRepository(),
        ));
    }

    public function pushApiController(): PushApiController
    {
        return $this->cached('pushApiController', fn(): PushApiController => new PushApiController(
            $this->pushSubscriptionRepository(),
            $this->pushSender(),
            $this->usageStatRepository(),
        ));
    }

    public function statController(): StatController
    {
        return $this->cached('statController', fn(): StatController => new StatController($this->usageStatRepository()));
    }

    public function eventHistoryController(): EventHistoryController
    {
        return $this->cached('eventHistoryController', fn(): EventHistoryController => new EventHistoryController(
            $this->view(),
            $this->session(),
            $this->eventHistoryRepository(),
            $this->eventStore(),
        ));
    }

    public function saisonController(): SaisonController
    {
        return $this->cached('saisonController', fn(): SaisonController => new SaisonController(
            $this->view(),
            $this->session(),
            $this->saisonService(),
            $this->teamRepository(),
            $this->importSourceRepository(),
        ));
    }

    public function pageAdminController(): PageAdminController
    {
        return $this->cached('pageAdminController', fn(): PageAdminController => new PageAdminController(
            $this->view(),
            $this->session(),
            $this->pageRepository(),
        ));
    }

    public function settingsController(): SettingsController
    {
        return $this->cached('settingsController', fn(): SettingsController => new SettingsController(
            $this->view(),
            $this->session(),
            $this->settingRepository(),
            $this->wappenService(),
        ));
    }

    public function importSourceController(): ImportSourceController
    {
        return $this->cached('importSourceController', fn(): ImportSourceController => new ImportSourceController(
            $this->view(),
            $this->session(),
            $this->importSourceRepository(),
            $this->teamRepository(),
            $this->importSourceService(),
            $this->icsImportService(),
        ));
    }

    public function venueMatcher(): VenueMatcher
    {
        return $this->cached('venueMatcher', fn(): VenueMatcher => VenueMatcher::fromDatabase($this->pdo()));
    }

    public function bookingService(): BookingService
    {
        return $this->cached('bookingService', fn(): BookingService => new BookingService(
            $this->pdo(),
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

    public function teamHomePitchService(): TeamHomePitchService
    {
        return $this->cached('teamHomePitchService', fn(): TeamHomePitchService => new TeamHomePitchService(
            $this->eventStore(),
            $this->teamHomePitchRepository(),
            $this->teamRepository(),
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
            $this->offlineBundleService(),
            $this->usageStatRepository(),
        ));
    }

    public function bookingApiController(): BookingApiController
    {
        return $this->cached('bookingApiController', fn(): BookingApiController => new BookingApiController(
            $this->session(),
            $this->bookingService(),
            $this->restrictionService(),
            $this->matchService(),
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
            $this->pageRepository(),
            $this->usageStatRepository(),
            $this->version->value,
            $this->paths->publicDir(),
            $this->wappenService(),
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
            $this->usageStatRepository(),
            $this->importSourceRepository(),
            $this->pushSubscriptionRepository(),
            $this->pdo(),
        ));
    }

    public function teamController(): TeamController
    {
        return $this->cached('teamController', fn(): TeamController => new TeamController(
            $this->view(),
            $this->session(),
            $this->teamRepository(),
            $this->teamService(),
            $this->teamHomePitchRepository(),
            $this->teamHomePitchService(),
            $this->pitchRepository(),
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
