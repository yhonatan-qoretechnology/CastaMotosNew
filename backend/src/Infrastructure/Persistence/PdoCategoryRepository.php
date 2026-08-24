<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Repositories\CategoryRepositoryInterface;
use PDO;

final class PdoCategoryRepository implements CategoryRepositoryInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function tree(bool $includeInactive = false): array
    {
        $sql = 'SELECT * FROM categories WHERE deleted_at IS NULL';
        if (!$includeInactive) {
            $sql .= " AND status = 'active'";
        }
        $sql .= ' ORDER BY sort_order ASC, name ASC';

        $rows = $this->connection->query($sql)->fetchAll();

        return $this->buildTree($rows, null);
    }

    private function buildTree(array $rows, ?int $parentId): array
    {
        $branch = [];

        foreach ($rows as $row) {
            $rowParentId = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
            if ($rowParentId !== $parentId) {
                continue;
            }

            $row['children'] = $this->buildTree($rows, (int) $row['id']);
            $branch[] = $row;
        }

        return $branch;
    }

    public function findBySlug(string $slug, bool $includeInactive = false): ?array
    {
        $sql = 'SELECT * FROM categories WHERE slug = :slug AND deleted_at IS NULL';
        if (!$includeInactive) {
            $sql .= " AND status = 'active'";
        }

        $stmt = $this->connection->prepare($sql);
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $row['children'] = $this->childrenOf((int) $row['id'], $includeInactive);

        return $row;
    }

    private function childrenOf(int $parentId, bool $includeInactive): array
    {
        $sql = 'SELECT * FROM categories WHERE parent_id = :parent_id AND deleted_at IS NULL';
        if (!$includeInactive) {
            $sql .= " AND status = 'active'";
        }
        $sql .= ' ORDER BY sort_order ASC, name ASC';

        $stmt = $this->connection->prepare($sql);
        $stmt->execute(['parent_id' => $parentId]);

        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->connection->prepare('SELECT * FROM categories WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function existsBySlug(string $slug, ?int $excludeId = null): bool
    {
        $sql = 'SELECT 1 FROM categories WHERE slug = :slug AND deleted_at IS NULL';
        $params = ['slug' => $slug];

        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }

    public function exists(int $id): bool
    {
        $stmt = $this->connection->prepare('SELECT 1 FROM categories WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $id]);

        return (bool) $stmt->fetchColumn();
    }

    public function wouldCreateCycle(int $categoryId, int $newParentId): bool
    {
        // Camina desde el padre propuesto hacia la raíz: si en el camino aparece
        // la propia categoría, asignarlo como padre crearía un ciclo.
        $currentId = $newParentId;
        $visited = [];

        while ($currentId !== null) {
            if ($currentId === $categoryId) {
                return true;
            }

            if (isset($visited[$currentId])) {
                break; // Ciclo preexistente detectado por otra vía: no seguir infinitamente.
            }
            $visited[$currentId] = true;

            $stmt = $this->connection->prepare('SELECT parent_id FROM categories WHERE id = :id');
            $stmt->execute(['id' => $currentId]);
            $parentId = $stmt->fetchColumn();

            $currentId = $parentId !== false && $parentId !== null ? (int) $parentId : null;
        }

        return false;
    }

    public function create(array $data): int
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO categories (parent_id, name, slug, description, image, status, sort_order)
             VALUES (:parent_id, :name, :slug, :description, :image, :status, :sort_order)'
        );
        $stmt->execute([
            'parent_id' => $data['parent_id'] ?? null,
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'image' => $data['image'] ?? null,
            'status' => $data['status'] ?? 'active',
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        // "image" queda AFUERA a propósito (a diferencia de create()): tiene su
        // propio endpoint/flujo de subida (updateImage(), como avatar/logo del
        // sitio) — si este UPDATE genérico también la tocara, cada vez que se
        // edita nombre/estado/orden desde el formulario normal se borraría el
        // ícono ya subido (el form no manda ese campo).
        $stmt = $this->connection->prepare(
            'UPDATE categories SET
                parent_id = :parent_id, name = :name, slug = :slug, description = :description,
                status = :status, sort_order = :sort_order
             WHERE id = :id'
        );
        $stmt->execute([
            'parent_id' => $data['parent_id'] ?? null,
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'active',
            'sort_order' => $data['sort_order'] ?? 0,
            'id' => $id,
        ]);
    }

    public function updateImage(int $id, string $filename): void
    {
        $stmt = $this->connection->prepare('UPDATE categories SET image = :image WHERE id = :id');
        $stmt->execute(['image' => $filename, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->prepare('UPDATE categories SET deleted_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
