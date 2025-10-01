<?php
// workers/products_worker.php

require_once "vendor/autoload.php";

use DI\Container;
use App\Services\RabbitMQService;
use App\Services\RabbitMQWorker;
use App\Services\OutboundApiService;
use App\Services\WildberriesService;

$container = new Container();

// Настройка зависимостей
$container->set('db', function () {
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
    return new PDO('mysql:host=localhost;dbname=your_db', 'user', 'password', $options);
});

$container->set(RabbitMQService::class, function (Container $container) {
    return new RabbitMQService($container->get('db'));
});

$container->set(OutboundApiService::class, function (Container $container) {
    return new OutboundApiService($container->get('db'));
});

$container->set(WildberriesService::class, function (Container $container) {
    return new WildberriesService($container->get('db'));
});

$container->set(RabbitMQWorker::class, function (Container $container) {
    return new RabbitMQWorker(
        $container->get(RabbitMQService::class),
        $container->get(OutboundApiService::class),
        $container->get(WildberriesService::class)
    );
});

$worker = $container->get(RabbitMQWorker::class);

// Бесконечный цикл для обработки очереди товаров
while (true) {
    try {
        echo "[" . date('Y-m-d H:i:s') . "] Starting products worker...\n";
        $result = $worker->run('wb_products_queue', 10);
        echo "Processed: {$result['processed']} messages\n";

        if ($result['processed'] === 0) {
            sleep(30); // Нет сообщений - ждем 30 секунд
        }

    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        sleep(60);
    }
}