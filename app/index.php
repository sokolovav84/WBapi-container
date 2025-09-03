<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once "vendor/autoload.php";

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

use DI\Container;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use App\Middleware\JwtMiddleware;
use App\Middleware\RoleMiddleware;
use App\Controllers\AuthController;
use Slim\Middleware\BodyParsingMiddleware;

$container = new Container();

// Правильная настройка базы данных
$container->set('db', function () {
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        $dsn = "mysql:host={$_ENV['MYSQL_HOST']};dbname={$_ENV['MYSQL_DATABASE']};charset=utf8mb4";

        return new PDO($dsn, $_ENV['MYSQL_USER'], $_ENV['MYSQL_PASSWORD'], $options);
    } catch (\PDOException $e) {
        throw new \PDOException($e->getMessage(), (int)$e->getCode());
    }
});

// JWT секретный ключ
$container->set('jwt_secret', function () {
    return $_ENV['JWT_SECRET'] ?? 'your-secret-key';
});

// Контроллер аутентификации
$container->set(AuthController::class, function (Container $container) {
    return new AuthController(
        $container->get('db'),
        $container->get('jwt_secret')
    );
});

AppFactory::setContainer($container);
$app = AppFactory::create();

$app->addBodyParsingMiddleware();

// Middleware для обработки CORS
$app->add(function (Request $request, $handler): Response {
    $response = $handler->handle($request);
    $origin = $request->getHeaderLine('Origin');

    return $response
        ->withHeader('Access-Control-Allow-Origin', $origin)
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, Cache-Control')
        ->withHeader('Access-Control-Allow-Credentials', 'true');
});

// Обработка preflight-запросов
$app->options('/{routes:.+}', function (Request $request, Response $response) {
    $origin = $request->getHeaderLine('Origin');

    return $response
        ->withHeader('Access-Control-Allow-Origin', $origin)
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, Cache-Control')
        ->withHeader('Access-Control-Allow-Credentials', 'true')
        ->withHeader('Access-Control-Max-Age', '86400');
});

// Маршрут для аутентификации
$app->post('/auth/login', [AuthController::class, 'login']);

// Публичный маршрут
$app->get('/', function (Request $request, Response $response) {
    $response->getBody()->write("Hello world!");
    return $response;
});

// Защищенные маршруты
$app->group('/api', function ($group) {

    // Маршрут для всех аутентифицированных пользователей
    $group->get('/profile', function (Request $request, Response $response) {
        $resp = $request->getParsedBody();
        $user = $request->getAttribute('user');
        $response->getBody()->write(json_encode(['resp'=>$resp,'user' => $user]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Маршрут только для администраторов
    $group->get('/admin', function (Request $request, Response $response) {
        $response->getBody()->write(json_encode(['message' => 'Admin area']));
        return $response->withHeader('Content-Type', 'application/json');
    })->add(new RoleMiddleware(['admin']));

    // Маршрут для администраторов и менеджеров
    $group->get('/management', function (Request $request, Response $response) {
        $response->getBody()->write(json_encode(['message' => 'Management area']));
        return $response->withHeader('Content-Type', 'application/json');
    })->add(new RoleMiddleware(['admin', 'manager']));

    $group->post('/demoData', function (Request $request, Response $response) {
        $jsonData = $request->getParsedBody();

        // Возвращаем полученные данные
        $response->getBody()->write(json_encode([
            'message' => 'Data received successfully',
            'received_data' => $jsonData,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    })->add(new RoleMiddleware(['admin']));


})->add(new JwtMiddleware($container->get('jwt_secret')));

$app->run();