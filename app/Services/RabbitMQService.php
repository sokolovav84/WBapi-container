<?php
// app/Services/RabbitMQService.php

namespace App\Services;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PDO;
use Exception;

class RabbitMQService
{
    private $connection;
    private $channel;
    private $db;
    private $config;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->config = $this->getRabbitMQConfig();
        $this->connect();
    }

    /**
     * Получить настройки RabbitMQ из базы
     */
    private function getRabbitMQConfig(): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                rmq_host as host,
                rmq_port as port,
                rmq_user as user,
                rmq_password as password,
                rmq_vhost as vhost
            FROM wbSettings 
            WHERE active = 1 
            ORDER BY Id DESC 
            LIMIT 1
        ");

        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            // Значения по умолчанию
            return [
                'host' => 'localhost',
                'port' => 5672,
                'user' => 'guest',
                'password' => 'guest',
                'vhost' => '/'
            ];
        }

        return $result;
    }

    /**
     * Подключение к RabbitMQ
     */
    private function connect(): void
    {
        try {
            $this->connection = new AMQPStreamConnection(
                $this->config['host'],
                $this->config['port'],
                $this->config['user'],
                $this->config['password'],
                $this->config['vhost']
            );

            $this->channel = $this->connection->channel();

            // Объявляем обменник и очереди
            $this->channel->exchange_declare('wb_api_exchange', AMQPExchangeType::DIRECT, false, true, false);

            $this->channel->queue_declare('wb_products_queue', false, true, false, false);
            $this->channel->queue_declare('wb_stocks_queue', false, true, false, false);
            $this->channel->queue_declare('wb_orders_queue', false, true, false, false);

            $this->channel->queue_bind('wb_products_queue', 'wb_api_exchange', 'products');
            $this->channel->queue_bind('wb_stocks_queue', 'wb_api_exchange', 'stocks');
            $this->channel->queue_bind('wb_orders_queue', 'wb_api_exchange', 'orders');

        } catch (Exception $e) {
            throw new Exception('RabbitMQ connection failed: ' . $e->getMessage());
        }
    }

    /**
     * Отправить сообщение в очередь
     */
    public function publish(string $routingKey, array $data, array $properties = []): bool
    {
        try {
            $message = new AMQPMessage(
                json_encode($data),
                array_merge([
                    'content_type' => 'application/json',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'timestamp' => time()
                ], $properties)
            );

            $this->channel->basic_publish($message, 'wb_api_exchange', $routingKey);
            return true;

        } catch (Exception $e) {
            error_log("RabbitMQ publish error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Отправить задачу на отправку товаров
     */
    public function publishProductsTask(array $mainIds, string $vendor = null): bool
    {
        return $this->publish('products', [
            'type' => 'send_products',
            'main_id' => $mainIds,
            'vendor' => $vendor,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Отправить задачу на отправку остатков
     */
    public function publishStocksTask(array $vendorCodes = []): bool
    {
        return $this->publish('stocks', [
            'type' => 'send_stocks',
            'vendor_codes' => $vendorCodes,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Отправить задачу на отправку заказов
     */
    public function publishOrdersTask(string $dateFrom): bool
    {
        return $this->publish('orders', [
            'type' => 'send_orders',
            'date_from' => $dateFrom,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Потреблять сообщения из очереди
     */
    public function consume(string $queueName, callable $callback, int $maxMessages = null): void
    {
        $messageCount = 0;

        $this->channel->basic_qos(null, 1, null);

        $this->channel->basic_consume(
            $queueName,
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $message) use ($callback, &$messageCount, $maxMessages) {
                try {
                    $data = json_decode($message->getBody(), true);
                    $result = $callback($data);

                    if ($result) {
                        $message->ack();
                    } else {
                        $message->nack(true); // Переотправка в очередь
                    }

                    $messageCount++;

                    // Ограничение количества сообщений
                    if ($maxMessages && $messageCount >= $maxMessages) {
                        $message->getChannel()->basic_cancel($message->getConsumerTag());
                    }

                } catch (Exception $e) {
                    error_log("Message processing error: " . $e->getMessage());
                    $message->nack(true); // Переотправка при ошибке
                }
            }
        );

        while ($this->channel->is_consuming()) {
            $this->channel->wait();
        }
    }

    /**
     * Закрыть соединение
     */
    public function close(): void
    {
        if ($this->channel) {
            $this->channel->close();
        }
        if ($this->connection) {
            $this->connection->close();
        }
    }

    /**
     * Получить статистику очередей
     */
    public function getQueueStats(): array
    {
        try {
            $queues = ['wb_products_queue', 'wb_stocks_queue', 'wb_orders_queue'];
            $stats = [];

            foreach ($queues as $queue) {
                list(, $messageCount, ) = $this->channel->queue_declare($queue, true);
                $stats[$queue] = $messageCount;
            }

            return $stats;

        } catch (Exception $e) {
            error_log("Queue stats error: " . $e->getMessage());
            return [];
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}