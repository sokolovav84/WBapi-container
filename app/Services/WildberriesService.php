<?php
// app/Services/WildberriesService.php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use PDO;

class WildberriesService
{
    private $client;
    private $db;
    private $baseUrl = 'https://content-api.wildberries.ru';

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            // настройки для избежания DNS проблем
            'curl' => [
                CURLOPT_DNS_CACHE_TIMEOUT => 3600,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ]
        ]);
    }

    /**
     * Получить настройки Wildberries из базы
     */
    private function getSettings(): array
    {
        $stmt = $this->db->prepare("
            SELECT accessToken, warehouse_id, proxy 
            FROM wbSettings 
            WHERE active = 1 
            ORDER BY Id DESC 
            LIMIT 1
        ");

        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result || empty($result['accessToken'])) {
            throw new \Exception('Wildberries settings not found or inactive');
        }

        return $result;
    }

    /**
     * Получить API ключ
     */
    private function getApiKey(): string
    {
        $settings = $this->getSettings();
        return $settings['accessToken'];
    }

    /**
     * Получить warehouse_id
     */
    private function getWarehouseId(): ?string
    {
        $settings = $this->getSettings();
        return $settings['warehouse_id'] ?? null;
    }

    /**
     * Получить настройки прокси
     */
    private function getProxyOptions(): array
    {
        $settings = $this->getSettings();
        $proxy = $settings['proxy'] ?? null;

        if (!$proxy) {
            return [];
        }

        return [
            'proxy' => $proxy,
            'verify' => false, // Отключаем проверку SSL для прокси
            'timeout' => 60
        ];
    }

    /**
     * Получить список товаров
     */
    public function getProducts(int $limit = 100, int $offset = 0): array
    {
        $apiKey = $this->getApiKey();
        $proxyOptions = $this->getProxyOptions();

        return $this->request($apiKey, 'GET', '/content/v2/cards/limits', [
            'query' => [
                'limit' => $limit,
                'offset' => $offset
            ]
        ], $proxyOptions);
    }

    /**
     * Получить информацию о товаре по артикулу
     */
    public function getProductByVendorCode(string $vendorCode): array
    {
        $apiKey = $this->getApiKey();
        $proxyOptions = $this->getProxyOptions();

        return $this->request($apiKey, 'POST', '/content/v1/cards/filter', [
            'json' => [
                'vendorCodes' => [$vendorCode]
            ]
        ], $proxyOptions);
    }

    /**
     * Получить список остатков товаров
     */
    public function getStocks(): array
    {
        $apiKey = $this->getApiKey();
        $warehouseId = $this->getWarehouseId();
        $proxyOptions = $this->getProxyOptions();

        $url = '/api/v2/stocks';
        if ($warehouseId) {
            $url .= '/' . $warehouseId;
        }

        return $this->request($apiKey, 'GET', $url, [], $proxyOptions);
    }

    /**
     * Обновить остатки товаров
     */
    public function updateStocks(array $stocksData): array
    {
        $apiKey = $this->getApiKey();
        $proxyOptions = $this->getProxyOptions();

        return $this->request($apiKey, 'POST', '/api/v2/stocks', [
            'json' => $stocksData
        ], $proxyOptions);
    }

    /**
     * Получить список заказов
     */
    public function getOrders(string $dateFrom, int $flag = 0): array
    {
        $apiKey = $this->getApiKey();
        $proxyOptions = $this->getProxyOptions();

        return $this->request($apiKey, 'GET', '/api/v2/orders', [
            'query' => [
                'dateFrom' => $dateFrom,
                'flag' => $flag
            ]
        ], $proxyOptions);
    }

    /**
     * Общий метод для выполнения запросов
     */
    private function request(string $apiKey, string $method, string $uri, array $options = [], array $proxyOptions = []): array
    {
        try {
            // Добавляем авторизацию к заголовкам
            $options['headers'] = array_merge($options['headers'] ?? [], [
                'Authorization' => 'Bearer ' . $apiKey
            ]);

            // Объединяем с прокси настройками
            $finalOptions = array_merge($options, $proxyOptions);

            $response = $this->client->request($method, $uri, $finalOptions);

            $responseBody = $response->getBody()->getContents();
            $result = json_decode($responseBody, true);

            return $result ?? ['data' => $responseBody];

        } catch (RequestException $e) {
            return [
                'error' => true,
                'message' => $e->getMessage(),
                'code' => $e->getCode()
            ];
        }
    }

    /**
     * Получить текущие настройки (для админки)
     */
    public function getCurrentSettings(): array
    {
        try {
            return $this->getSettings();
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Обновить настройки (для админки)
     */
    public function updateSettings(array $settings): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO wbSettings (name, accessToken, proxy, warehouse_id, active)
            VALUES (:name, :accessToken, :proxy, :warehouse_id, :active)
            ON DUPLICATE KEY UPDATE 
                accessToken = :accessToken,
                proxy = :proxy,
                warehouse_id = :warehouse_id,
                active = :active,
                name = :name
        ");

        return $stmt->execute([
            ':name' => $settings['name'] ?? 'default',
            ':accessToken' => $settings['accessToken'],
            ':proxy' => $settings['proxy'] ?? null,
            ':warehouse_id' => $settings['warehouse_id'] ?? null,
            ':active' => $settings['active'] ?? 1
        ]);
    }
}