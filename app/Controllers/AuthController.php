<?php
// app/Controllers/AuthController.php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Firebase\JWT\JWT;
use PDO;

class AuthController
{
    private $db;
    private $secretKey;

    public function __construct(PDO $db, string $secretKey)
    {
        $this->db = $db;
        $this->secretKey = $secretKey;
    }

    public function login(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        //var_dump($data);
        // Проверяем обязательные поля
        if (empty($data['username']) || empty($data['password'])) {
            return $this->createErrorResponse($response, 'Username and password are required');
        }

        // Ищем пользователя в базе данных
        $stmt = $this->db->prepare('SELECT * FROM users WHERE username = :username');
        $stmt->execute(['username' => $data['username']]);
        $user = $stmt->fetch();

       // echo '|||'.password_hash($data['password'],PASSWORD_DEFAULT ).'|||';

        if (!$user || !password_verify($data['password'], $user['password'])) {
            return $this->createErrorResponse($response, 'Invalid credentials');
        }

        // Генерируем JWT токен
        $payload = [
            'iss' => $_ENV['JWT_ISSUER'] ?? 'your-app',
            'aud' => $_ENV['JWT_AUDIENCE'] ?? 'your-app',
            'iat' => time(),
            'exp' => time() + ($_ENV['JWT_EXPIRATION'] ?? 3600),
            'sub' => $user['Id'],
            'role' => $user['role'],
            'username' => $user['username']
        ];

        $token = JWT::encode($payload, $this->secretKey, 'HS256');

        $response->getBody()->write(json_encode([
            'token' => $token,
            'expires' => $payload['exp'],
            'user' => [
                'id' => $user['Id'],
                'username' => $user['username'],
                'role' => $user['role']
            ]
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function createErrorResponse(Response $response, string $message): Response
    {
        $response->getBody()->write(json_encode([
            'error' => 'Authentication failed',
            'message' => $message
        ]));

        return $response
            ->withStatus(401)
            ->withHeader('Content-Type', 'application/json');
    }
}