<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use PDO;
use RuntimeException;
use Throwable;

class SiteService
{
    private PDO $db;
    private array $supplier;
    private Client $siteClient;
    private Client $wbClient;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->supplier = $this->getActiveSupplier();

        $siteUrl = rtrim((string)($this->supplier['siteUrl'] ?? ''), '/');
        $siteToken = (string)($this->supplier['siteToken'] ?? '');

        if ($siteUrl === '' || $siteToken === '') {
            throw new RuntimeException('Active supplier has empty siteUrl or siteToken');
        }

        $this->siteClient = new Client([
            'base_uri' => $siteUrl . '/',
            'timeout' => 120,
            'headers' => [
                'Authorization' => "Bearer {$siteToken}",
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);

        $this->wbClient = $this->createWbClient();
    }

    private function getActiveSupplier(): array
    {
        $stmt = $this->db->query("
            SELECT *
            FROM suppliers
            WHERE isActive = 1
            ORDER BY Id DESC
            LIMIT 1
        ");

        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$supplier) {
            throw new RuntimeException('Active supplier not found');
        }

        return $supplier;
    }

    private function createWbClient(): Client
    {
        $options = [
            'timeout' => 60,
            'connect_timeout' => 10,
            'headers' => [
                'Authorization' => $this->supplier['wbApiKey'] ?? '',
                'Content-Type' => 'application/json',
            ],
        ];

        if (!empty($this->supplier['proxy'])) {
            $proxy = $this->supplier['proxy'];

            if (!empty($this->supplier['proxy_auth'])) {
                $proxy = str_replace(
                    'http://',
                    "http://{$this->supplier['proxy_auth']}@",
                    $proxy
                );
            }

            $options['proxy'] = [
                'http' => $proxy,
                'https' => $proxy,
            ];
        }

        return new Client($options);
    }

    public function getEmptyTovars(int $supplierId, int $limit = 100): array
    {
        $stmt = $this->db->prepare("
            SELECT vendorCode
            FROM products
            WHERE specifications IS NULL
              AND supplier_id = :supplier_id
            LIMIT :limit
        ");

        $stmt->bindValue(':supplier_id', $supplierId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function syncProducts(int $limit = 10): array
    {
        $supplierId = (int)$this->supplier['Id'];
        $vendorCodes = $this->getEmptyTovars($supplierId, $limit);

        if (empty($vendorCodes)) {
            return [
                'success' => true,
                'message' => 'No products to sync',
                'updated' => 0
            ];
        }

        $codesToUpdate = array_column($vendorCodes, 'vendorCode');
        $result = $this->getCards($codesToUpdate);
        $updated = $this->updateTovarsFromSite($result);

        return [
            'success' => true,
            'updated' => $updated,
            'data' => $result
        ];
    }

    public function updateTovarsFromSite(array $tovars): int
    {
        $sql = "
            UPDATE products
            SET specifications = :specifications,
                nomType = :nomType,
                url = :url,
                barcode = :barcode,
                stocks = :stocks,
                price = :price,
                lastUpdateStocks = NOW()
            WHERE vendorCode = :vendorCode
        ";

        $stmt = $this->db->prepare($sql);
        $updated = 0;

        foreach ($tovars as $key => $tovar) {
            try {
                $stmt->execute([
                    ':specifications' => json_encode($tovar, JSON_UNESCAPED_UNICODE),
                    ':nomType' => $tovar['type'] ?? null,
                    ':url' => $tovar['url'] ?? null,
                    ':barcode' => $tovar['barcode'] ?? null,
                    ':stocks' => $tovar['stocks'] ?? null,
                    ':price' => $tovar['price'] ?? null,
                    ':vendorCode' => $key,
                ]);

                $updated += $stmt->rowCount() > 0 ? 1 : 0;
            } catch (Throwable $e) {
                error_log("VendorCode {$key} sync failed: " . $e->getMessage());
            }
        }

        return $updated;
    }

    public function getCards(array $cardsIds): array
    {
        $response = $this->siteClient->post('mp_api_client/getTovars/', [
            'json' => [
                'vendor' => $this->supplier['name'] ?? '',
                'main_id' => $cardsIds,
            ],
        ]);

        return json_decode((string)$response->getBody(), true) ?? [];
    }

    public function sendToWB(array $data): array
    {
        $response = $this->wbClient->post('https://suppliers-api.wildberries.ru/endpoint', [
            'json' => $data,
        ]);

        return json_decode((string)$response->getBody(), true) ?? [];
    }
}