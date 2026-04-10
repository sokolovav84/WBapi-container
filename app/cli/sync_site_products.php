<?php

require_once __DIR__ . '/../vendor/autoload.php';
use App\Services\SiteService;
use Dotenv\Dotenv;
use App\Services\SupplierService;


$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
$dsn = "mysql:host={$_ENV['MYSQL_HOST']};dbname={$_ENV['MYSQL_DATABASE']};charset=utf8mb4";
$db = new PDO($dsn, $_ENV['MYSQL_USER'], $_ENV['MYSQL_PASSWORD'], $options);


$supplierService = new SupplierService($db);
$suppliers = $supplierService->getActiveSuppliers();

//print_r($suppliers);exit();

foreach ($suppliers as $supplier) {
    $siteService = new SiteService($db, $supplier);

    echo "Обрабатываем поставщика: {$supplier['name']}\n";

    try {
        $siteService->syncProducts(10);
    } catch (Exception $e) {
        echo "Ошибка: " . $e->getMessage() . "\n";
    }
}