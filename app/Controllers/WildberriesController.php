<?php


declare(strict_types=1);

namespace App\Controllers;

use App\Services\RedisCursorStore;
use App\Services\SupplierService;
use App\Services\WildberriesService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class WildberriesController
{
    public function __construct(
        private WildberriesService $wildberriesService,
        private SupplierService $supplierService,
        private RedisCursorStore $cursorStore
    ) {
    }

    /**
     * 📦 Импорт товаров
     * POST /api/wb/import-products/{supplierId}
     */
    public function importProducts(Request $request, Response $response, array $args): Response
    {
        try {
            set_time_limit(300);
            ini_set('max_execution_time', '300');

            $supplierId = (int)$args['supplierId'];
            $queryParams = $request->getQueryParams();

            $batchSize = isset($queryParams['batchSize']) ? (int)$queryParams['batchSize'] : 100;
            $batchSize = max(1, min($batchSize, 100));

            $maxBatchesPerRun = isset($queryParams['maxBatches']) ? (int)$queryParams['maxBatches'] : 50;
            $maxBatchesPerRun = max(1, min($maxBatchesPerRun, 100));

            $supplier = $this->supplierService->getById($supplierId);

            if (!$supplier) {
                return $this->json($response, [
                    'success' => false,
                    'error' => 'Supplier not found',
                ], 404);
            }

            $result = $this->wildberriesService->importProductsWithResume(
                $supplier,
                $batchSize,
                $maxBatchesPerRun
            );

            return $this->json($response, $result);
        } catch (\Throwable $e) {
            error_log('Import products failed: ' . $e->getMessage());

            return $this->json($response, [
                'success' => false,
                'error' => $e->getMessage(),
                'imported' => 0,
                'errors' => 0,
            ], 500);
        }
    }

    public function getImportStatus(Request $request, Response $response, array $args): Response
    {
        $supplierId = (int)$args['supplierId'];
        $state = $this->cursorStore->getState($supplierId);

        return $this->json($response, [
            'success' => true,
            'state' => $state,
        ]);
    }

    /**
     * 📦 Получить товары WB (не из БД, а из API)
     * GET /api/wb/products/{supplierId}
     */
    public function getProducts(Request $request, Response $response, array $args): Response
    {
        $supplierId = (int)$args['supplierId'];

        try {
            $supplier = $this->supplierService->getById($supplierId);

            if (!$supplier) {
                return $this->json($response, [
                    'success' => false,
                    'message' => 'Supplier not found'
                ], 404);
            }

            $data = $this->wbService->getProducts($supplier);

            return $this->json($response, [
                'success' => true,
                'data' => $data
            ]);

        } catch (\Throwable $e) {

            return $this->json($response, [
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 📦 Остатки
     * GET /api/wb/stocks/{supplierId}
     */
    public function getStocks(Request $request, Response $response, array $args): Response
    {
        $supplierId = (int)$args['supplierId'];

        try {
            $supplier = $this->supplierService->getById($supplierId);

            if (!$supplier) {
                return $this->json($response, [
                    'success' => false,
                    'message' => 'Supplier not found'
                ], 404);
            }

            $data = $this->wbService->getStocks($supplier);

            return $this->json($response, [
                'success' => true,
                'data' => $data
            ]);

        } catch (\Throwable $e) {

            return $this->json($response, [
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 📦 Заказы
     * GET /api/wb/orders/{supplierId}?dateFrom=2024-01-01&flag=0
     */
    public function getOrders(Request $request, Response $response, array $args): Response
    {
        $supplierId = (int)$args['supplierId'];

        $query = $request->getQueryParams();
        $dateFrom = $query['dateFrom'] ?? date('Y-m-d', strtotime('-7 days'));
        $flag = (int)($query['flag'] ?? 0);

        try {
            $supplier = $this->supplierService->getById($supplierId);

            if (!$supplier) {
                return $this->json($response, [
                    'success' => false,
                    'message' => 'Supplier not found'
                ], 404);
            }

            $data = $this->wbService->getOrders($supplier, $dateFrom, $flag);

            return $this->json($response, [
                'success' => true,
                'data' => $data
            ]);

        } catch (\Throwable $e) {

            return $this->json($response, [
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ⚙️ Получить настройки WB
     * GET /api/wb/settings/{supplierId}
     */
    public function getSettings(Request $request, Response $response, array $args): Response
    {
        $supplierId = (int)$args['supplierId'];

        try {
            $supplier = $this->supplierService->getById($supplierId);

            if (!$supplier) {
                return $this->json($response, [
                    'success' => false,
                    'message' => 'Supplier not found'
                ], 404);
            }

            return $this->json($response, [
                'success' => true,
                'data' => [
                    'apiKey' => $supplier['wbApiKey'] ?? null,
                    'proxy'  => $supplier['proxy'] ?? null,
                ]
            ]);

        } catch (\Throwable $e) {
            return $this->json($response, [
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 🧰 Универсальный JSON ответ
     */
    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}
