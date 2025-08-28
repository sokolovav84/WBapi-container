<?php
ini_set('display_errors', 1);


echo 'sdfsd';

require_once "vendor/autoload.php";


$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

use DI\Container;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;



$container = new Container();

$container->set('db', function () {
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        return new PDO($_ENV['MYSQL_DATABASE'], $_ENV['MYSQL_USER'], $_ENV['MYSQL_PASSWORD'], $options);
    } catch (\PDOException $e) {
        throw new \PDOException($e->getMessage(), (int)$e->getCode());
    }
});

// Передаем контейнер в приложение
AppFactory::setContainer($container);


$app = AppFactory::create();

$app->get('/', function (Request $request, Response $response, $args) {
    $response->getBody()->write("Hello world!".PHP_EOL.PRINT_R($_ENV),TRUE);
    return $response;
});

$app->run();

/*
$wbSellerAPI = new \Dakword\WBSeller\API($options = [
    'masterkey' => 'eyJhbGciOiJFUzI1NiIsImtpZCI6IjIwMjUwNTIwdjEiLCJ0eXAiOiJKV1QifQ.eyJlbnQiOjEsImV4cCI6MTc2NTMyMTAzOCwiaWQiOiIwMTk3NTk3Yy1jNDZmLTdhNmMtOTI1YS02YjA5NTQ3ODIzMjgiLCJpaWQiOjgxNzAxNDI5LCJvaWQiOjI1MDAxMTU3MywicyI6MTAyNzAsInNpZCI6IjRkZjJlODU5LTQ2Y2ItNDJiZi05OTM0LTY0YWVjNGMwMWQzYiIsInQiOmZhbHNlLCJ1aWQiOjgxNzAxNDI5fQ.EB21nvCCA8WjWUfWUCp35Km9k5h3WfyAAXlc9SQz9wNKA2EBdaa2vBFC4Rj0gHo2JRTwzW2fHuS5OpI_NNlW0g',
    //'keys' => [...],
    //'apiurls' => [...],
    //'locale' => 'ru'
]);
// API Контента
$contentAPI = $wbSellerAPI->Content();
$contentAPI->getCardsList(); // Получить список карточек

print_r($contentAPI->getCardsList());
*/