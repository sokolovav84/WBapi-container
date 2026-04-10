<?php

declare(strict_types=1);

namespace App\Middleware;

use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

class JwtMiddleware implements MiddlewareInterface
{
    public function __construct(private string $secretKey)
    {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if ($authHeader === '') {
            return $this->createErrorResponse('Authorization header is required');
        }

        if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return $this->createErrorResponse('Invalid authorization header format');
        }

        try {
            $decoded = JWT::decode($matches[1], new Key($this->secretKey, 'HS256'));
            return $handler->handle($request->withAttribute('user', $decoded));
        } catch (Exception $e) {
            error_log('JWT error: ' . $e->getMessage());
            return $this->createErrorResponse('Invalid or expired token');
        }
    }

    private function createErrorResponse(string $message): Response
    {
        $response = new SlimResponse();
        $response->getBody()->write(json_encode([
            'error' => 'Unauthorized',
            'message' => $message
        ], JSON_UNESCAPED_UNICODE));

        return $response
            ->withStatus(401)
            ->withHeader('Content-Type', 'application/json');
    }
}