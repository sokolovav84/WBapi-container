<?php
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\WildberriesService;

class WildberriesController
{
    private $wbService;

    public function __construct(WildberriesService $wbService)
    {
        $this->wbService = $wbService;
    }

    /**
     * Получить список товаров
     */
    public function getProducts(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $limit = $queryParams['limit'] ?? 100;
        $offset = $queryParams['offset'] ?? 0;

        try {
            $result = $this->wbService->getProducts((int)$limit, (int)$offset);
            $response->getBody()->write(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'error' => true,
                'message' => $e->getMessage()
            ]));
            return $response->withStatus(400);
        }

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Получить остатки товаров
     */
    public function getStocks(Request $request, Response $response): Response
    {
        try {
            $result = $this->wbService->getStocks();
            $response->getBody()->write(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'error' => true,
                'message' => $e->getMessage()
            ]));
            return $response->withStatus(400);
        }

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Получить заказы
     */
    public function getOrders(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $dateFrom = $queryParams['dateFrom'] ?? date('Y-m-d', strtotime('-7 days'));
        $flag = $queryParams['flag'] ?? 0;

        try {
            $result = $this->wbService->getOrders($dateFrom, (int)$flag);
            $response->getBody()->write(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'error' => true,
                'message' => $e->getMessage()
            ]));
            return $response->withStatus(400);
        }

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Получить текущие настройки (только для админов)
     */
    public function getSettings(Request $request, Response $response): Response
    {
        try {
            $result = $this->wbService->getCurrentSettings();
            $response->getBody()->write(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'error' => true,
                'message' => $e->getMessage()
            ]));
            return $response->withStatus(400);
        }

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Обновить настройки (только для админов)
     */
    public function updateSettings(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        if (empty($data['accessToken'])) {
            $response->getBody()->write(json_encode([
                'error' => true,
                'message' => 'Access token is required'
            ]));
            return $response->withStatus(400);
        }

        try {
            $result = $this->wbService->updateSettings($data);

            $response->getBody()->write(json_encode([
                'success' => $result,
                'message' => 'Settings updated successfully'
            ]));

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'error' => true,
                'message' => $e->getMessage()
            ]));
            return $response->withStatus(500);
        }

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function importProducts(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $batchSize = $queryParams['batchSize'] ?? 100;
        $method = $queryParams['method'] ?? 'batch'; // batch или single

        try {
            if ($method === 'single') {
                $result = $this->wbService->importProductsSingle((int)$batchSize);
            } else {
                $result = $this->wbService->importProductsToDatabase((int)$batchSize);
            }

            $response->getBody()->write(json_encode([
                'success' => $result['success'],
                'imported' => $result['imported'] ?? 0,
                'updated' => $result['updated'] ?? 0,
                'errors' => $result['errors'] ?? 0,
                'total' => $result['total'] ?? 0,
                'method' => $method,
                'message' => $result['success'] ?
                    "Successfully imported {$result['imported']} new products and updated {$result['updated']} existing products" :
                    "Import failed: {$result['error']}"
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $response->withHeader('Content-Type', 'application/json');

        } catch (Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage(),
                'imported' => 0,
                'updated' => 0,
                'errors' => 0,
                'total' => 0
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }





}