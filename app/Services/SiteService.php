<?php
// app/Services/SiteService.php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use PDO;
use Exception;

class SiteService
{

    private $client;
    private $db;
    private $siteUrl;

    private $vendorName;

    public function __construct(PDO $db)
    {
        $this->db = $db;



        $this->client = new Client([
            'base_uri' => $this->getsiteUrl(),
            'timeout' => 120,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => "Bearer {$this->getsiteToken()}"
            ],
            // настройки для избежания DNS проблем
            'curl' => [
                CURLOPT_DNS_CACHE_TIMEOUT => 3600,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ]
        ]);

       // $this->wbApi = new WBSeller\API(["keys"=>["content"=>$this->getApiKey()]]);
    }



    private function getSettings(): array
    {
        $stmt = $this->db->prepare("
            SELECT * 
            FROM wbSettings 
            WHERE active = 1 
            ORDER BY Id DESC 
            LIMIT 1
        ");

        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result || empty($result['siteToken'])) {
            throw new \Exception('Site settings not found or inactive');
        }

        return $result;
    }


    public function getEmptyTovars($banch=100)
    {
        $stmt = $this->db->prepare("
            SELECT vendorCode 
            FROM products 
            WHERE specifications is null 
            LIMIT {$banch}
        ");

        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$result ) {
            throw new \Exception('Site settings not found or inactive');
        }

        return $result;
    }

    public function syncProducts(int $limit = 10): array
    {
        // 1. Берём товары без specifications
        $vendorCodes = $this->getEmptyTovars($limit);
        if (empty($vendorCodes)) {
            return [];
        }

        $codesToUpd = array_column($vendorCodes, 'vendorCode');

        // 2. Запрашиваем карточки с сайта
        $result = $this->getCards($codesToUpd);

        // 3. Обновляем их в базе
        $this->updateTovarsFromSite($result);

        return $result;
    }
    public function updateTovarsFromSite(array $tovars)
    {
        $sql = 'update products set specifications=:specifications, nomType=:nomType,url=:url,barcode=:barcode,stocks=:stocks,price=:price,lastUpdateStocks=NOW() where vendorCode=:vendorCode';
        $stmt = $this->db->prepare($sql);

        if(count($tovars)>0) {
            foreach ($tovars as $key => $tovar) {
               try{
                    $res = $stmt->execute([
                        ':specifications' => json_encode($tovar, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                        ':nomType' => $tovar['type'],
                        ':url' => $tovar['url'],
                        ':barcode' => $tovar['barcode'],
                        ':stocks' => $tovar['stocks'],
                        ':price' => $tovar['price'],
                        ':vendorCode' => $key,
                    ]);
               } catch (Exception $e) {

                   error_log("{$key} processing failed: " . $e->getMessage());
               }


            }
        }
        return true;
    }

    private function getsiteToken(): string
    {
        $settings = $this->getSettings();
        return $settings['siteToken'];
    }

    private function getVendorName(): string
    {
        $settings = $this->getSettings();
        return $settings['name'];
    }

    private function getsiteUrl(): string
    {
        $settings = $this->getSettings();
        return $settings['siteUrl'];
    }



    public function getCards($cardsIds)
    {

        $response = $this->client->post('/mp_api_client/getTovars/', [
            'json' => [
                'vendor' => $this->getVendorName(),
                'main_id' => $cardsIds
            ]
        ]);

        $body = $response->getBody()->getContents();
        $data = json_decode($body, true);

        return $data;
    }


}