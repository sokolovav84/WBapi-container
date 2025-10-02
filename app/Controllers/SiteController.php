<?php
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\SiteService;

class SiteController
{
    private $siteService;

    public function __construct(SiteService $siteService)
    {
        $this->siteService = $siteService;
    }


    public function getTovars(Request $request, Response $response): Response
    {
        $result = $this->siteService->syncProducts(10);

        $response->getBody()->write(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }


}