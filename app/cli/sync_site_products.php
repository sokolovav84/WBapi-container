<?php
require __DIR__ . '/../vendor/autoload.php';

use App\Services\SiteService;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
$dsn = "mysql:host={$_ENV['MYSQL_HOST']};dbname={$_ENV['MYSQL_DATABASE']};charset=utf8mb4";
$db = new PDO($dsn, $_ENV['MYSQL_USER'], $_ENV['MYSQL_PASSWORD'], $options);

$siteService = new SiteService($db);

try {
    $result = $siteService->syncProducts(10);
    echo "[" . date('Y-m-d H:i:s') . "] Обновлено товаров: " . count($result) . "\n";
} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}
