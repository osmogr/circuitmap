<?php

declare(strict_types=1);

namespace CircuitMap;

use CircuitMap\Controllers\AdminController;
use CircuitMap\Controllers\AuthController;
use CircuitMap\Controllers\CircuitController;
use CircuitMap\Controllers\CircuitProviderController;
use CircuitMap\Controllers\EditController;
use CircuitMap\Controllers\StatusController;
use CircuitMap\Middleware\AuthGateMiddleware;
use CircuitMap\Middleware\CsrfMiddleware;
use CircuitMap\Middleware\ProxyAuthMiddleware;
use CircuitMap\Middleware\SessionMiddleware;
use CircuitMap\Models\AuditLogRepository;
use CircuitMap\Models\CircuitProviderRepository;
use CircuitMap\Models\CircuitRepository;
use CircuitMap\Models\CircuitVersionRepository;
use CircuitMap\Models\UserRepository;
use CircuitMap\Routes\AdminRoutes;
use CircuitMap\Routes\AuthRoutes;
use CircuitMap\Routes\CircuitRoutes;
use CircuitMap\Services\Auth\AuthService;
use CircuitMap\Services\Auth\CsrfService;
use CircuitMap\Services\Kml\GeoJsonConverter;
use CircuitMap\Services\Kml\KmlParser;
use CircuitMap\Services\Kml\KmlSanitizer;
use CircuitMap\Services\Kml\KmlValidator;
use CircuitMap\Services\Kml\KmzExtractor;
use CircuitMap\Services\RateLimit\RateLimiterService;
use CircuitMap\Services\Status\ManualStatusProvider;
use CircuitMap\Services\Storage\FileStorageService;
use CircuitMap\Support\Database;
use CircuitMap\Support\Env;
use CircuitMap\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App as SlimApp;
use Slim\Factory\AppFactory;

final class App
{
    public static function create(): SlimApp
    {
        View::setTemplatesPath(dirname(__DIR__) . '/templates');

        $pdo = Database::connection();
        $services = self::buildServices($pdo);

        $app = AppFactory::create();
        $app->addBodyParsingMiddleware();

        $app->get('/healthz', function (Request $request, Response $response) {
            $response->getBody()->write('OK');
            return $response->withHeader('Content-Type', 'text/plain');
        });

        self::registerHomeRoute($app, $services);
        AuthRoutes::register($app, $services);
        CircuitRoutes::register($app, $services);
        AdminRoutes::register($app, $services);

        // Added before SessionMiddleware (see add-order note below) so it
        // executes AFTER the session is started but BEFORE route-specific
        // middleware like AuthGateMiddleware reads currentUser().
        if (Env::getBool('PROXY_AUTH_ENABLED', false)) {
            $defaultRole = Env::get('PROXY_AUTH_DEFAULT_ROLE', 'editor');
            if (!in_array($defaultRole, ['editor', 'admin'], true)) {
                $defaultRole = 'editor';
            }

            $app->add(new ProxyAuthMiddleware(
                $services['auth'],
                Env::get('PROXY_AUTH_HEADER', 'REMOTE_USER'),
                $defaultRole
            ));
        }

        // Slim middleware runs LIFO: the last one added here (error
        // middleware) executes first, wrapping everything else; the first
        // one added (SessionMiddleware, or ProxyAuthMiddleware above it)
        // executes last, right before route dispatch.
        $app->add(new SessionMiddleware());
        $app->addRoutingMiddleware();

        $displayErrors = Env::getBool('APP_DEBUG', false);
        $app->addErrorMiddleware($displayErrors, true, true);

        return $app;
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildServices(\PDO $pdo): array
    {
        $users = new UserRepository($pdo);
        $circuits = new CircuitRepository($pdo);
        $circuitVersions = new CircuitVersionRepository($pdo);
        $circuitProviders = new CircuitProviderRepository($pdo);
        $auditLog = new AuditLogRepository($pdo);
        $auth = new AuthService($users);
        $csrf = new CsrfService();
        $rateLimiter = new RateLimiterService($pdo);

        $storagePath = Env::get('KML_STORAGE_PATH', '/var/lib/circuitmap/kml');
        $maxUploadBytes = Env::getInt('MAX_UPLOAD_BYTES', 10_485_760);
        $storage = new FileStorageService($storagePath);
        $parser = new KmlParser($maxUploadBytes);
        $validator = new KmlValidator();
        $sanitizer = new KmlSanitizer();
        $geoJsonConverter = new GeoJsonConverter();
        $kmzExtractor = new KmzExtractor();
        // StatusProviderInterface binding: swap this for a future polling
        // or webhook-based adapter without touching StatusController.
        $statusProvider = new ManualStatusProvider($circuits);

        $authGateMiddleware = new AuthGateMiddleware($auth);
        $csrfMiddleware = new CsrfMiddleware($csrf);

        return [
            'pdo' => $pdo,
            'userRepo' => $users,
            'circuits' => $circuits,
            'circuitProviders' => $circuitProviders,
            'auditLog' => $auditLog,
            'auth' => $auth,
            'csrf' => $csrf,
            'rateLimiter' => $rateLimiter,
            'storage' => $storage,
            'authGateMiddleware' => $authGateMiddleware,
            'csrfMiddleware' => $csrfMiddleware,
            'authController' => new AuthController($auth, $csrf, $auditLog),
            'circuitController' => new CircuitController(
                $auth,
                $csrf,
                $circuits,
                $circuitProviders,
                $auditLog,
                $storage,
                $parser,
                $validator,
                $sanitizer,
                $geoJsonConverter,
                $kmzExtractor
            ),
            'statusController' => new StatusController($circuits, $auditLog, $statusProvider),
            'adminController' => new AdminController($users, $auditLog, $csrf),
            'circuitProviderController' => new CircuitProviderController($circuitProviders, $auditLog, $csrf),
            'editController' => new EditController(
                $auth,
                $csrf,
                $circuits,
                $circuitProviders,
                $circuitVersions,
                $auditLog,
                $storage,
                $parser,
                $validator,
                $sanitizer,
                $geoJsonConverter
            ),
        ];
    }

    /**
     * @param array<string, mixed> $services
     */
    private static function registerHomeRoute(SlimApp $app, array $services): void
    {
        $route = $app->get('/', function (Request $request, Response $response) use ($services) {
            /** @var AuthService $auth */
            $auth = $services['auth'];
            /** @var CsrfService $csrf */
            $csrf = $services['csrf'];

            $html = View::render('layout', [
                'title' => 'Map',
                'csrfToken' => $csrf->getToken(),
                'currentUser' => $auth->currentUser(),
                'content' => View::render('map', [
                    'currentUser' => $auth->currentUser(),
                ]),
            ]);

            $response->getBody()->write($html);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        });

        if (Env::getBool('REQUIRE_AUTH_FOR_VIEW', false)) {
            /** @var AuthGateMiddleware $authGate */
            $authGate = $services['authGateMiddleware'];
            $route->add($authGate);
        }
    }
}
