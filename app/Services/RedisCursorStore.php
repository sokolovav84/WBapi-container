<?php


declare(strict_types=1);

namespace App\Services;

use Redis;
use RuntimeException;

class RedisCursorStore
{
    private Redis $redis;
    private int $ttl;

    public function __construct(
        string $host = 'redis',
        int $port = 6379,
        int $ttl = 86400
    ) {
        $this->ttl = $ttl;
        $this->redis = new Redis();

        $connected = $this->redis->connect($host, $port, 5);

        if (!$connected) {
            throw new RuntimeException('Redis connection failed');
        }
    }

    private function getKey(int $supplierId): string
    {
        return "wb:import:cursor:supplier:{$supplierId}";
    }

    public function getState(int $supplierId): ?array
    {
        $raw = $this->redis->get($this->getKey($supplierId));

        if ($raw === false || $raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function getCursor(int $supplierId): array
    {
        $state = $this->getState($supplierId);

        if (!$state || empty($state['cursor']) || !is_array($state['cursor'])) {
            return [];
        }

        return $state['cursor'];
    }

    public function saveProgress(
        int $supplierId,
        array $cursor,
        int $imported,
        string $status = 'running'
    ): void {
        $payload = [
            'cursor' => $cursor,
            'imported' => $imported,
            'status' => $status,
            'updatedAt' => date('Y-m-d H:i:s'),
        ];

        $this->redis->setex(
            $this->getKey($supplierId),
            $this->ttl,
            json_encode($payload, JSON_UNESCAPED_UNICODE)
        );
    }

    public function markFailed(int $supplierId, int $imported, ?array $cursor = null, string $error = ''): void
    {
        $state = $this->getState($supplierId) ?? [];

        $payload = [
            'cursor' => $cursor ?? ($state['cursor'] ?? []),
            'imported' => $imported,
            'status' => 'failed',
            'error' => $error,
            'updatedAt' => date('Y-m-d H:i:s'),
        ];

        $this->redis->setex(
            $this->getKey($supplierId),
            $this->ttl,
            json_encode($payload, JSON_UNESCAPED_UNICODE)
        );
    }

    public function markFinished(int $supplierId, int $imported): void
    {
        $payload = [
            'cursor' => null,
            'imported' => $imported,
            'status' => 'finished',
            'updatedAt' => date('Y-m-d H:i:s'),
        ];

        $this->redis->setex(
            $this->getKey($supplierId),
            $this->ttl,
            json_encode($payload, JSON_UNESCAPED_UNICODE)
        );
    }

    public function clear(int $supplierId): void
    {
        $this->redis->del($this->getKey($supplierId));
    }
}