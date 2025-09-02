<?php
// app/Middleware/RoleMiddleware.php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class RoleMiddleware implements MiddlewareInterface
{
    private $allowedRoles;

    public function __construct(array $allowedRoles)
    {
        $this->allowedRoles = $allowedRoles;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $user = $request->getAttribute('user');

        if (!$user || !isset($user->role)) {
            return $this->createErrorResponse('User role not found');
        }

        if (!in_array($user->role, $this->allowedRoles)) {
            return $this->createErrorResponse('Insufficient permissions');
        }

        return $handler->handle($request);
    }

    private function createErrorResponse(string $message): Response
    {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode([
            'error' => 'Forbidden',
            'message' => $message
        ]));

        return $response
            ->withStatus(403)
            ->withHeader('Content-Type', 'application/json');
    }
}