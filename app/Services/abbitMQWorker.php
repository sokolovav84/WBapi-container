<?php
// app/Services/RabbitMQWorker.php

namespace App\Services;

use Exception;

class RabbitMQWorker
{
    private $rabbitMQService;
    private $outboundService;
    private $wildberriesService;

    public function __construct(
        RabbitMQService $rabbitMQService,
        OutboundApiService $outboundService,
        WildberriesService $wildberriesService
    ) {
        $this->rabbitMQService = $rabbitMQService;
        $this->outboundService = $outboundService;
        $this->wildberriesService = $wildberriesService;
    }

    /**
     * Запустить воркер для обработки очередей
     */
    public function run(string $queueName, int $maxMessages = null): array
    {
        $processed = 0;
        $results = [];

        $callback = function (array $data) use (&$processed, &$results) {
            try {
                $result = $this->processMessage($data);
                $results[] = [
                    'message_id' => $data['message_id'] ?? uniqid(),
                    'type' => $data['type'],
                    'success' => true,
                    'result' => $result,
                    'processed_at' => date('Y-m-d H:i:s')
                ];
                $processed++;
                return true;

            } catch (Exception $e) {
                $results[] = [
                    'message_id' => $data['message_id'] ?? uniqid(),
                    'type' => $data['type'],
                    'success' => false,
                    'error' => $e->getMessage(),
                    'processed_at' => date('Y-m-d H:i:s')
                ];
                $processed++;
                return false; // Переотправка в очередь
            }
        };

        $this->rabbitMQService->consume($queueName, $callback, $maxMessages);

        return [
            'processed' => $processed,
            'results' => $results
        ];
    }

    /**
     * Обработка сообщения
     */
    private function processMessage(array $data): array
    {
        switch ($data['type']) {
            case 'send_products':
                $mainIds = $data['main_id'] ?? [];
                $vendor = $data['vendor'] ?? null;
                return $this->outboundService->sendProductsData($mainIds, $vendor);

            case 'send_stocks':
                $vendorCodes = $data['vendor_codes'] ?? [];
                return $this->outboundService->sendStocksData($vendorCodes);

            case 'send_orders':
                $dateFrom = $data['date_from'] ?? date('Y-m-d', strtotime('-1 day'));
                return $this->wildberriesService->getOrders($dateFrom);

            case 'import_products':
                $batchSize = $data['batch_size'] ?? 100;
                return $this->wildberriesService->importProductsToDatabase($batchSize);

            case 'update_stocks':
                return $this->wildberriesService->updateStocksFromWB();

            default:
                throw new Exception("Unknown message type: {$data['type']}");
        }
    }

    /**
     * Запустить воркер для всех очередей
     */
    public function runAll(int $maxMessagesPerQueue = 10): array
    {
        $queues = ['wb_products_queue', 'wb_stocks_queue', 'wb_orders_queue'];
        $totalResults = [];

        foreach ($queues as $queue) {
            echo "Processing queue: {$queue}\n";
            $results = $this->run($queue, $maxMessagesPerQueue);
            $totalResults[$queue] = $results;

            // Пауза между очередями
            sleep(1);
        }

        return $totalResults;
    }
}