<?php

declare(strict_types=1);

namespace App\Factories;

use App\Clients\WildberriesClient;

class WildberriesClientFactory
{
    public function make(array $supplier): WildberriesClient
    {
        return $this->create($supplier);
    }

    public function create(array $supplier): WildberriesClient
    {
        $apiKey = (string)($supplier['wbApiKey'] ?? '');
        $proxy = $this->buildProxy($supplier);

        return new WildberriesClient($apiKey, $proxy);
    }

    private function buildProxy(array $supplier): ?array
    {
        $proxy = trim((string)($supplier['proxy'] ?? ''));
        $proxyAuth = trim((string)($supplier['proxy_auth'] ?? ''));

        if ($proxy === '') {
            return null;
        }

        if (!preg_match('#^http://#i', $proxy)) {
            $proxy = 'http://' . $proxy;
        }

        if ($proxyAuth !== '') {
            $proxy = preg_replace(
                '#^http://#i',
                'http://' . $proxyAuth . '@',
                $proxy,
                1
            );
        }

        return [
            'http' => $proxy,
            'https' => $proxy,
        ];
    }
}