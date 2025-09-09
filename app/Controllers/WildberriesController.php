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
}