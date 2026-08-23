<?php

declare(strict_types=1);

use App\Infrastructure\Database\Seeder;

/**
 * Subcategoría "Lavado" (bajo "Servicios") — a partir de acá el wizard de
 * lavado (frontend/js/pages/lavado.js) ya NO busca servicios por una lista
 * fija de slugs: muestra TODOS los servicios activos de esta categoría. Así,
 * cualquier variante nueva que se cree desde /admin → Servicios asignada a
 * "Lavado" (una tercera opción, una cuarta, lo que sea) aparece sola en el
 * paso 1 del wizard, sin tocar código cada vez.
 *
 * Debe ejecutarse ANTES que 008/009/010 (que ahora asignan sus servicios acá
 * en vez de a "Servicios" a secas) — el orden alfabético de los archivos ya
 * lo garantiza si esta corre como parte del mismo db:seed inicial, pero como
 * salvaguarda también se ejecuta el upsert de categoría si hiciera falta.
 */
return new class extends Seeder {
    public function run(PDO $connection): void
    {
        $serviciosId = $this->findCategoryId($connection, 'servicios');
        $lavadoId = $this->upsertCategory($connection, $serviciosId, 'Lavado', 'Wash', 'lavado');

        // Servicios ya sembrados por 008/009/010 (instalaciones que corrieron
        // esos seeders antes de que existiera esta categoría) — se reasignan acá.
        $stmt = $connection->prepare(
            "UPDATE services SET category_id = :new_category_id
             WHERE slug IN ('lavado-de-moto', 'lavado-de-casco', 'lavada-premium-de-moto', 'lavada-premium-de-casco')
             AND (category_id IS NULL OR category_id != :current_category_id)"
        );
        $stmt->execute(['new_category_id' => $lavadoId, 'current_category_id' => $lavadoId]);
    }

    private function findCategoryId(PDO $connection, string $slug): ?int
    {
        $stmt = $connection->prepare('SELECT id FROM categories WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    private function upsertCategory(PDO $connection, ?int $parentId, string $name, string $nameEn, string $slug): int
    {
        $existing = $connection->prepare('SELECT id FROM categories WHERE slug = :slug');
        $existing->execute(['slug' => $slug]);
        $id = $existing->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $stmt = $connection->prepare(
            'INSERT INTO categories (parent_id, name, name_en, slug, status, sort_order)
             VALUES (:parent_id, :name, :name_en, :slug, :status, 1)'
        );
        $stmt->execute([
            'parent_id' => $parentId,
            'name' => $name,
            'name_en' => $nameEn,
            'slug' => $slug,
            'status' => 'active',
        ]);

        return (int) $connection->lastInsertId();
    }
};
