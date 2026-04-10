<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/vendor/autoload.php';

use App\Controllers\AuthController;
use App\Controllers\SiteController;
use App\Controllers\WildberriesController;
use App\Factories\WildberriesClientFactory;
use App\Middleware\JwtMiddleware;
use App\Middleware\RoleMiddleware;
use App\Repositories\ProductRepository;
use App\Services\SiteService;
use App\Services\SupplierService;
use App\Services\WildberriesService;
use DI\Container;
use Dotenv\Dotenv;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use App\Services\RedisCursorStore;

Dotenv::createImmutable(__DIR__)->safeLoad();

$container = new Container();

$container->set('db', function (): \PDO {
    $host = $_ENV['MYSQL_HOST'] ?? 'mysql';
    $db   = $_ENV['MYSQL_DATABASE'] ?? 'api_wb';
    $user = $_ENV['MYSQL_USER'] ?? 'root';
    $pass = $_ENV['MYSQL_PASSWORD'] ?? $_ENV['MYSQL_ROOT_PASSWORD'] ?? '';

    $dsn = "mysql:host={$host};dbname={$db};charset=utf8mb4";

    return new \PDO($dsn, $user, $pass, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES => false,
    ]);
});

$container->set('jwt_secret', function (): string {
    $secret = $_ENV['JWT_SECRET'] ?? '';
    if ($secret === '') {
        throw new \RuntimeException('JWT_SECRET is not configured');
    }
    return $secret;
});

$container->set(WildberriesClientFactory::class, fn() => new WildberriesClientFactory());

$container->set(RedisCursorStore::class, function (): RedisCursorStore {
    $host = $_ENV['REDIS_HOST'] ?? 'redis';
    $port = (int)($_ENV['REDIS_PORT'] ?? 6379);

    return new RedisCursorStore($host, $port);
});


$container->set(ProductRepository::class, function (Container $c): ProductRepository {
    return new ProductRepository($c->get('db'));
});

$container->set(SupplierService::class, function (Container $c): SupplierService {
    return new SupplierService($c->get('db'));
});

$container->set(WildberriesService::class, function (Container $c): WildberriesService {
    return new WildberriesService(
        $c->get(WildberriesClientFactory::class),
        $c->get(ProductRepository::class),
        $c->get(RedisCursorStore::class)
    );
});

$container->set(SiteService::class, function (Container $c): SiteService {
    return new SiteService($c->get('db'));
});

$container->set(SiteController::class, function (Container $c): SiteController {
    return new SiteController($c->get(SiteService::class));
});

$container->set(AuthController::class, function (Container $c): AuthController {
    return new AuthController(
        $c->get('db'),
        $c->get('jwt_secret')
    );
});

$container->set(WildberriesController::class, function (Container $c): WildberriesController {
    return new WildberriesController(
        $c->get(WildberriesService::class),
        $c->get(SupplierService::class),
        $c->get(RedisCursorStore::class)
    );
});

AppFactory::setContainer($container);
$app = AppFactory::create();
$app->addBodyParsingMiddleware();

$allowedOrigins = array_filter(array_map('trim', explode(',', $_ENV['CORS_ALLOWED_ORIGINS'] ?? '')));

$app->add(function (Request $request, $handler) use ($allowedOrigins): Response {
    $response = $handler->handle($request);
    $origin = $request->getHeaderLine('Origin');

    if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, Cache-Control')
            ->withHeader('Access-Control-Allow-Credentials', 'true');
    }

    return $response;
});

$app->options('/{routes:.+}', function (Request $request, Response $response) use ($allowedOrigins) {
    $origin = $request->getHeaderLine('Origin');

    if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, Cache-Control')
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Max-Age', '86400');
    }

    return $response->withStatus(204);
});

$app->post('/auth/login', [AuthController::class, 'login']);

$app->get('/', function (Request $request, Response $response) {
    $response->getBody()->write(json_encode([
        'success' => true,
        'message' => 'WB API is running'
    ], JSON_UNESCAPED_UNICODE));

    return $response->withHeader('Content-Type', 'application/json');
});

$app->group('/api', function ($group) {
    $group->get('/profile', function (Request $request, Response $response) {
        $user = $request->getAttribute('user');

        $response->getBody()->write(json_encode([
            'success' => true,
            'user' => $user
        ], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    });

    $group->get('/admin', function (Request $request, Response $response) {
        $response->getBody()->write(json_encode(['message' => 'Admin area']));
        return $response->withHeader('Content-Type', 'application/json');
    })->add(new RoleMiddleware(['admin']));

    $group->get('/management', function (Request $request, Response $response) {
        $response->getBody()->write(json_encode(['message' => 'Management area']));
        return $response->withHeader('Content-Type', 'application/json');
    })->add(new RoleMiddleware(['admin', 'manager']));

    $group->get('/site/products', [SiteController::class, 'getTovars'])
        ->add(new RoleMiddleware(['admin']));



    $group->get('/wb/products/{supplierId}', [WildberriesController::class, 'getProducts']);
    $group->get('/wb/stocks/{supplierId}', [WildberriesController::class, 'getStocks']);
    $group->get('/wb/orders/{supplierId}', [WildberriesController::class, 'getOrders']);

    $group->get('/wb/settings/{supplierId}', [WildberriesController::class, 'getSettings'])
        ->add(new RoleMiddleware(['admin']));

    $group->get('/wb/import-status/{supplierId}', [WildberriesController::class, 'getImportStatus']);
    $group->post('/wb/import-products/{supplierId}', [WildberriesController::class, 'importProducts'])
        ->add(new RoleMiddleware(['admin']));
})->add(new JwtMiddleware($container->get('jwt_secret')));

$app->run();