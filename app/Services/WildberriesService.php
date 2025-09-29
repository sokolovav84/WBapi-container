<?php
// app/Services/WildberriesService.php

namespace App\Services;

use GuzzleHttp\Client;
use Dakword\WBSeller;
use GuzzleHttp\Exception\RequestException;
use PDO;
use Exception;

class WildberriesService
{
    private $client;
    private $wbApi;
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

        $this->wbApi = new WBSeller\API(["keys"=>["content"=>$this->getApiKey()]]);
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
     * Импорт товаров из WB в базу данных с использованием ON DUPLICATE KEY UPDATE
     */
    public function importProductsToDatabase(int $batchSize = 100): array
    {
        try {
            $imported = 0;
            $updated = 0;
            $errors = 0;
            $offset = 0;

            $updatedAt = '';
            $nmId = 0;
            $ascending = false;
            $withPhoto = -1;
            $objectIDs = [];
            $brands = [];
            $tagIDs = [];
            $imtID = 0;
            $allowedCategoriesOnly = false;


            do {
                // Получаем товары через WBSeller API
                $contentAPI = $this->wbApi->Content();
                $products = $contentAPI->getCardsList('',
                    $batchSize,
                    $updatedAt,
                    $nmId,
                    $ascending,
                    $withPhoto,
                    $objectIDs,
                    $brands,
                    $tagIDs,
                    $imtID,
                    $allowedCategoriesOnly
                );


                print_r($products->cursor);echo PHP_EOL;

                if (empty($products)) {
                    break;
                }
                $updatedAt = $products->cursor->updatedAt ?? '';
                $nmId      = $products->cursor->nmID ?? 0;
                // Обрабатываем партией для эффективности
                $batchResult = $this->processProductsBatch($products->cards);
                $imported += $batchResult['imported'];
                $updated += $batchResult['updated'];
                $errors += $batchResult['errors'];

                $offset += $batchSize;

                // Пауза чтобы не превысить лимиты API
                usleep(500000); // 0.5 секунды

            } while (!empty($products));

            return [
                'success' => true,
                'total' => $imported + $updated
            ];

        } catch (Exception $e) {
            error_log("Import failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'imported' => 0,
                'updated' => 0,
                'errors' => $errors ?? 0
            ];
        }
    }

    /**
     * Обработка партии товаров одним запросом
     */
    private function processProductsBatch(array $products): array
    {
        $imported = 0;
        $updated = 0;
        $errors = 0;

        if (empty($products)) {
            return compact('imported', 'updated', 'errors');
            die('products is empty');
        }


        $sql = "INSERT INTO products ( mainId, vendorCode, specificationsWB, nm_id, imt_id, chrt_id, createdAt ) VALUES (:mainId, :vendorCode, :specificationsWB, :nm_id, :imt_id, :chrt_id, NOW())
                    ON DUPLICATE KEY UPDATE 
                    mainId = VALUES(mainId),
                    vendorCode = VALUES(vendorCode),
                    specificationsWB = VALUES(specificationsWB),
                    imt_id = VALUES(imt_id),
                    chrt_id = VALUES(chrt_id),
                    updatedAt = NOW()";

        $stmt = $this->db->prepare($sql);

        try {
            foreach ($products as $product) {
                try {


                    $productData = $this->prepareProductData((array)$product);

                    //var_dump($productData);

                    if($productData) {
                        $stmt->execute([
                            ':mainId'         => $productData[':mainId'],
                            ':vendorCode'     => $productData[':vendorCode'],
                            ':specificationsWB' => $productData[':specificationsWB'], // строка JSON
                            ':nm_id'          => $productData[':nm_id'],
                            ':imt_id'         => $productData[':imt_id'],
                            ':chrt_id'        => $productData[':chrt_id'],
                        ]);


                    }
                } catch (Exception $e) {
                    $errors++;
                    error_log("Error preparing product data: " . $e->getMessage());
                }



            }


            //$this->db->commit();

        } catch (Exception $e) {
            //$this->db->rollBack();
            $errors += count($products); // Все товары в партии считаем ошибками
            error_log("Batch processing failed: " . $e->getMessage());
        }

        return compact('imported', 'updated', 'errors');
    }

    /**
     * Подготовка данных товара для вставки
     */
    private function prepareProductData(array $product): ?array
    {
        $nm_id = $product['nmID'] ?? null;
        if (!$nm_id) {
            throw new Exception('nm_id is required');
        }

        return [
            ':mainId' => $product['id'] ?? $product['imtID'] ?? null,
            ':vendorCode' => $product['vendorCode'] ?? null,
            //'nomTtype' => $product['nomenclatureType'] ?? null,
            //'url' => $this->extractProductUrl($product),
            ':specificationsWB' => json_encode($product ?? []),
            ':nm_id' => $nm_id,
            ':imt_id' => $product['imtID'] ?? null,
            ':chrt_id' => $product['chrtID'] ?? $product['variations'][0]['chrtID'] ?? null,
            //'barcode' => $this->extractBarcode($product),
            //'stocks' => $this->extractStocks($product),
            //'lastUpdateStocks' => $this->extractLastUpdateDate($product),
            //'price' => $this->extractPrice($product),
            //'discount' => $this->extractDiscount($product),
        ];
    }

    /**
     * Создание плейсхолдера для подготовленного запроса
     */
    private function createPlaceholder(): string
    {
        return '(?, ?, ?, ?, ?, ?)';
    }

    /**
     * Выполнение пакетной вставки с ON DUPLICATE KEY UPDATE
     */
    private function executeBatchInsert(array $placeholders, array $values): array
    {
        $sql = "
        INSERT INTO products (
            mainId, vendorCode, specificationsWB, 
            nm_id, imt_id, chrt_id
        ) VALUES " . implode(', ', $placeholders) . "
        ON DUPLICATE KEY UPDATE 
            mainId = VALUES(mainId),
            vendorCode = VALUES(vendorCode),
            specificationsWB = VALUES(specificationsWB),
            imt_id = VALUES(imt_id),
            chrt_id = VALUES(chrt_id),
            updatedAt = NOW()
    ";



        $stmt = $this->db->prepare($sql);

        // Добавляем даты создания/обновления для каждого значения
        $finalValues = [];
        foreach ($values as $i => $value) {
            $finalValues[] = $value;
            // Добавляем createdAt и updatedAt после каждых 13 значений (количество полей)
            if (($i + 1) % 6 === 0) {
                $finalValues[] = date('Y-m-d H:i:s'); // createdAt
                $finalValues[] = date('Y-m-d H:i:s'); // updatedAt
            }
        }

        $stmt->execute($finalValues);

        // Получаем статистику вставки/обновления
        $rowCount = $stmt->rowCount();
        $imported = 0;
        $updated = 0;

        // В MySQL, при ON DUPLICATE KEY UPDATE:
        // - 1 для вставленной строки
        // - 2 для обновленной строки
        // Но для пакетной вставки это не так просто, поэтому используем альтернативный подход

        // Альтернативный способ: считаем по количеству affected rows
        if ($rowCount > 0) {
            // Это приблизительная оценка, так как MySQL не дает точной статистики для batch операций
            $imported = count($placeholders); // Предполагаем, что все вставлены
            $updated = $rowCount - count($placeholders); // Разница может указывать на обновления
        }

        return [
            'imported' => max(0, $imported),
            'updated' => max(0, $updated)
        ];
    }
/**=======================================================================*/
    /**
     * Альтернативный метод: обработка по одному товару с ON DUPLICATE KEY UPDATE
     * (Менее эффективно, но проще для отслеживания статистики)
     */
    public function importProductsSingle(int $batchSize = 100): array
    {
        try {
            $imported = 0;
            $updated = 0;
            $errors = 0;
            $offset = 0;

            do {
                $contentAPI = $this->wbApi->Content();
                $products = $contentAPI->getCardsList($offset, $batchSize);

                if (empty($products)) {
                    break;
                }

                foreach ($products as $product) {
                    try {
                        $result = $this->processSingleProduct($product);
                        if ($result === 'imported') {
                            $imported++;
                        } elseif ($result === 'updated') {
                            $updated++;
                        }
                    } catch (Exception $e) {
                        $errors++;
                        error_log("Error processing product: " . $e->getMessage());
                    }
                }

                $offset += $batchSize;
                usleep(500000);

            } while (!empty($products));

            return [
                'success' => true,
                'imported' => $imported,
                'updated' => $updated,
                'errors' => $errors,
                'total' => $imported + $updated
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'imported' => 0,
                'updated' => 0,
                'errors' => $errors ?? 0
            ];
        }
    }

    /**
     * Обработка одного товара с ON DUPLICATE KEY UPDATE
     */
    private function processSingleProduct(array $product): string
    {
        $nm_id = $product['nmID'] ?? null;
        if (!$nm_id) {
            throw new Exception('nm_id is required');
        }

        $sql = "
        INSERT INTO products (
            mainId, vendorCode, specificationsWB, 
            nm_id, imt_id, chrt_id, updatedAt
        ) VALUES (
            :mainId, :vendorCode, :specificationsWB,
            :nm_id, :imt_id, :chrt_id,  NOW()
        )
        ON DUPLICATE KEY UPDATE 
            mainId = VALUES(mainId),
            vendorCode = VALUES(vendorCode),
            specificationsWB = VALUES(specificationsWB),
            imt_id = VALUES(imt_id),
            chrt_id = VALUES(chrt_id),
            updatedAt = NOW()
    ";

        $stmt = $this->db->prepare($sql);
        $this->bindProductData($stmt, $product);

        $stmt->execute();

        // Определяем тип операции по rowCount
        return $stmt->rowCount() === 1 ? 'imported' : 'updated';
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