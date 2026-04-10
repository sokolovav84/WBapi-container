<?php
declare(strict_types=1);

namespace App\Services;

use App\Factories\WildberriesClientFactory;
use App\Repositories\ProductRepository;
use RuntimeException;
use Throwable;

class WildberriesService
{
    public function __construct(
        private WildberriesClientFactory $clientFactory,
        private ProductRepository $productRepository,
        private RedisCursorStore $cursorStore
    ) {
    }

    public function importProductsWithResume(array $supplier, int $batchSize = 100, int $maxBatchesPerRun = 50): array
    {
        $supplierId = (int)$supplier['Id'];

        $client = $this->clientFactory->make($supplier);

        $state = $this->cursorStore->getState($supplierId);
        $cursor = $state['cursor'] ?? [];
        $importedTotal = (int)($state['imported'] ?? 0);

        if (empty($cursor)) {
            $cursor = [
                'limit' => $batchSize,
            ];
        } else {
            $cursor['limit'] = $batchSize;
        }

        $processedBatches = 0;
        $lastCursor = $cursor;

        try {
            while ($processedBatches < $maxBatchesPerRun) {
                $result = $client->getProducts($cursor);

                $cards = $result['cards']
                    ?? $result['data']['cards']
                    ?? [];

                $nextCursor = $result['cursor']
                    ?? $result['data']['cursor']
                    ?? null;

                if (empty($cards)) {
                    $this->cursorStore->markFinished($supplierId, $importedTotal);

                    return [
                        'success' => true,
                        'status' => 'finished',
                        'imported' => $importedTotal,
                        'batches' => $processedBatches,
                        'nextCursor' => null,
                    ];
                }

                foreach ($cards as $product) {
                    $this->productRepository->upsert(
                        $this->mapProduct($product, $supplier)
                    );
                    $importedTotal++;
                }

                $processedBatches++;
                $lastCursor = is_array($nextCursor) ? $nextCursor : [];

                if (!empty($lastCursor)) {
                    $lastCursor['limit'] = $batchSize;

                    $this->cursorStore->saveProgress(
                        $supplierId,
                        $lastCursor,
                        $importedTotal,
                        'running'
                    );
                }

                if (empty($nextCursor)) {
                    $this->cursorStore->markFinished($supplierId, $importedTotal);

                    return [
                        'success' => true,
                        'status' => 'finished',
                        'imported' => $importedTotal,
                        'batches' => $processedBatches,
                        'nextCursor' => null,
                    ];
                }

                $cursor = $lastCursor;
            }

            return [
                'success' => true,
                'status' => 'partial',
                'imported' => $importedTotal,
                'batches' => $processedBatches,
                'nextCursor' => $lastCursor,
                'message' => 'Batch limit per run reached, continue with next request',
            ];
        } catch (Throwable $e) {
            $this->cursorStore->markFailed(
                $supplierId,
                $importedTotal,
                $lastCursor,
                $e->getMessage()
            );

            throw $e;
        }
    }

    private function mapProduct(array $product, array $supplier): array
    {
        if (empty($product['nmID'])) {
            throw new RuntimeException('nmID is required');
        }

        return [
            ':supplier_id' => (int)$supplier['Id'],
            ':mainId' => $product['imtID'] ?? $product['nmID'] ?? null,
            ':vendorCode' => $product['vendorCode'] ?? '',
            ':specificationsWB' => json_encode($product, JSON_UNESCAPED_UNICODE),
            ':nm_id' => $product['nmID'],
            ':imt_id' => $product['imtID'] ?? null,
            ':chrt_id' => $product['sizes'][0]['chrtID']
                ?? $product['dimensions'][0]['chrtID']
                    ?? null,
        ];
    }
}