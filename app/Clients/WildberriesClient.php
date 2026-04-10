<?php

declare(strict_types=1);

namespace App\Clients;

use GuzzleHttp\Client;

class WildberriesClient
{
    private Client $client;

    public function __construct(string $apiKey, ?array $proxy = null)
    {
        $options = [
            'base_uri' => 'https://content-api.wildberries.ru/',
            'timeout' => 30,
            'connect_timeout' => 10,
            'headers' => [
                'Authorization' => $apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ];

        if ($proxy !== null) {
            $options['proxy'] = $proxy;
        }

        $this->client = new Client($options);
    }

    public function getProducts(array $cursor = []): array
    {
        $json = [
            'settings' => [
                "cursor" => $cursor,
                "filter" => ["withPhoto" => -1],
                "sort" => ["ascending"=> true]
                ],
        ];


        $response = $this->client->post('content/v2/get/cards/list', [
            'json' => $json

        ]);




       // print_r($response->getBody()->getContents());exit;

        return json_decode((string)$response->getBody(), true) ?? [];
    }
}