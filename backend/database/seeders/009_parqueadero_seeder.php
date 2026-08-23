<?php

declare(strict_types=1);

use App\Infrastructure\Database\Seeder;

/**
 * "Parqueadero" — servicio de catálogo simple (no depende de ningún wizard,
 * a diferencia de "Lavado de Moto" / "Lavado de Casco" en 008, que sí están
 * atados por slug al wizard de lavado). Vive como seeder solo para que
 * exista de entrada en instalaciones nuevas; precio y duración se pueden
 * ajustar después desde el admin (Servicios) sin romper nada.
 */
return new class extends Seeder {
    public function run(PDO $connection): void
    {
        $categoryId = $this->findCategoryId($connection, 'servicios');

        $this->upsertService($connection, [
            'category_id' => $categoryId,
            'name' => 'Parqueadero',
            'slug' => 'parqueadero',
            'description' => 'Parqueadero para tu vehículo, por hora.',
            'price' => '5000.00',
            'duration_minutes' => 60,
            'facebook_url' => 'https://www.facebook.com/parqueaderoLopera/',
        ]);
    }

    private function findCategoryId(PDO $connection, string $slug): ?int
    {
        $stmt = $connection->prepare('SELECT id FROM categories WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    private function upsertService(PDO $connection, array $data): void
    {
        $existing = $connection->prepare('SELECT id FROM services WHERE slug = :slug');
        $existing->execute(['slug' => $data['slug']]);
        if ($existing->fetchColumn() !== false) {
            return;
        }

        $stmt = $connection->prepare(
            'INSERT INTO services (category_id, name, slug, description, price, duration_minutes, facebook_url, status)
             VALUES (:category_id, :name, :slug, :description, :price, :duration_minutes, :facebook_url, :status)'
        );
        $stmt->execute([
            'category_id' => $data['category_id'],
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'],
            'price' => $data['price'],
            'duration_minutes' => $data['duration_minutes'],
            'facebook_url' => $data['facebook_url'] ?? null,
            'status' => 'active',
        ]);
    }
};
