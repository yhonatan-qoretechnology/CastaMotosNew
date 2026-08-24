<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Repositories\BrandRepositoryInterface;
use PDO;

final class PdoBrandRepository implements BrandRepositoryInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function list(bool $includeInactive = false): array
    {
        $sql = 'SELECT * FROM brands WHERE deleted_at IS NULL';
        if (!$includeInactive) {
            $sql .= " AND status = 'active'";
        }
        $sql .= ' ORDER BY name ASC';

        return $this->connection->query($sql)->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->connection->prepare('SELECT * FROM brands WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function exists(int $id): bool
    {
        $stmt = $this->connection->prepare('SELECT 1 FROM brands WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $id]);

        return (bool) $stmt->fetchColumn();
    }

    public function existsBySlug(string $slug, ?int $excludeId = null): bool
    {
        $sql = 'SELECT 1 FROM brands WHERE slug = :slug AND deleted_at IS NULL';
        $params = ['slug' => $slug];

        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }

    public function create(array $data): int
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO brands (name, slug, logo, status) VALUES (:name, :slug, :logo, :status)'
        );
        $stmt->execute([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'logo' => $data['logo'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        // "logo" queda AFUERA a propósito (igual que categories.image) — tiene
        // su propio endpoint de subida (updateLogo()); si este UPDATE genérico
        // también lo tocara, editar nombre/estado desde el formulario normal
        // borraría el logo ya subido (el form no manda ese campo).
        $stmt = $this->connection->prepare(
            'UPDATE brands SET name = :name, slug = :slug, status = :status WHERE id = :id'
        );
        $stmt->execute([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'status' => $data['status'] ?? 'active',
            'id' => $id,
        ]);
    }

    public function updateLogo(int $id, string $filename): void
    {
        $stmt = $this->connection->prepare('UPDATE brands SET logo = :logo WHERE id = :id');
        $stmt->execute(['logo' => $filename, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->prepare('UPDATE brands SET deleted_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
