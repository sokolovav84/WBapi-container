<?php

namespace App\Services;
use PDO;
class SupplierService
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function getActiveSuppliers(): array
    {
        $stmt = $this->db->query("SELECT * FROM suppliers WHERE isActive = 1");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM suppliers WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }
}