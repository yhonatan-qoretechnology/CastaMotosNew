<?php

declare(strict_types=1);

use App\Infrastructure\Database\Seeder;

/**
 * Variantes "Premium" del lavado (sección 52, wizard de lavado) — igual que
 * 008 (Lavado de Moto / Lavado de Casco, las variantes "normales"), el
 * wizard (frontend/js/pages/lavado.js, WASH_CATALOG) las busca por slug
 * exacto ("lavada-premium-de-moto" / "lavada-premium-de-casco"). A
 * diferencia de las normales, estas SÍ son opcionales para el wizard: si
 * alguna no existe todavía, simplemente no aparece como opción (no rompe
 * nada), a diferencia de las normales que si faltan muestran el error de
 * "servicios no creados en el catálogo".
 *
 * Precio y duración son un punto de partida razonable; se pueden ajustar
 * después desde el admin (Servicios) sin romper el wizard.
 */
return new class extends Seeder {
    public function run(PDO $connection): void
    {
        $categoryId = $this->findCategoryId($connection, 'servicios');

        $this->upsertService($connection, [
            'category_id' => $categoryId,
            'name' => 'Lavada Premium de Moto',
            'slug' => 'lavada-premium-de-moto',
            'description' => 'Lavado premium de tu motocicleta: carrocería, llantas, cadena, motor y encerado. Reservá el día y la hora que prefieras.',
            'price' => '35000.00',
            'duration_minutes' => 60,
        ]);

        $this->upsertService($connection, [
            'category_id' => $categoryId,
            'name' => 'Lavada Premium de Casco',
            'slug' => 'lavada-premium-de-casco',
            'description' => 'Limpieza y desinfección profunda de tu casco con productos premium: exterior, interior, correas y ventilación. Reservá el día y la hora que prefieras.',
            'price' => '20000.00',
            'duration_minutes' => 180,
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
            'INSERT INTO services (category_id, name, slug, description, price, duration_minutes, status)
             VALUES (:category_id, :name, :slug, :description, :price, :duration_minutes, :status)'
        );
        $stmt->execute([
            'category_id' => $data['category_id'],
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'],
            'price' => $data['price'],
            'duration_minutes' => $data['duration_minutes'],
            'status' => 'active',
        ]);
    }
};
