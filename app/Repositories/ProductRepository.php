<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class ProductRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function upsert(array $product): void
    {
        $sql = "
            INSERT INTO products (
                supplier_id,
                mainId,
                vendorCode,
                specificationsWB,
                nm_id,
                imt_id,
                chrt_id,
                createdAt,
                updatedAt
            )
            VALUES (
                :supplier_id,
                :mainId,
                :vendorCode,
                :specificationsWB,
                :nm_id,
                :imt_id,
                :chrt_id,
                NOW(),
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                mainId = VALUES(mainId),
                vendorCode = VALUES(vendorCode),
                specificationsWB = VALUES(specificationsWB),
                imt_id = VALUES(imt_id),
                chrt_id = VALUES(chrt_id),
                updatedAt = NOW()
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($product);
    }
}