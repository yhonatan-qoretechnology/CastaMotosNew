<?php

declare(strict_types=1);

namespace App\Domain\Repositories;

interface ProductRepositoryInterface
{
    /**
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function paginate(array $filters, bool $includeAllStatuses = false): array;

    public function findBySlug(string $slug, bool $includeAllStatuses = false): ?array;

    public function find(int $id): ?array;

    public function exists(int $id): bool;

    public function existsBySlug(string $slug, ?int $excludeId = null): bool;

    public function existsBySku(string $sku, ?int $excludeId = null): bool;

    public function create(array $data): int;

    public function update(int $id, array $data): void;

    public function delete(int $id): void;

    public function relatedProducts(int $productId, int $categoryId, int $limit = 8): array;

    // --- Imágenes ---
    public function addImage(int $productId, string $path, bool $isPrimary): int;

    public function countImages(int $productId): int;

    public function deleteImage(int $imageId): void;

    public function imageBelongsToProduct(int $imageId, int $productId): bool;

    public function setPrimaryImage(int $productId, int $imageId): void;

    // --- Variantes y atributos (reemplazo total del conjunto) ---
    public function replaceVariants(int $productId, array $variants): void;

    public function replaceAttributes(int $productId, array $attributes): void;

    // --- Inventario (sección 25, se activa por completo en la Fase 6) ---
    public function initializeInventory(int $productId, int $stock, int $minStock): void;

    // --- Agendamiento opcional (mismo mecanismo que ServiceRepositoryInterface) ---
    public function bookedTimesForDate(int $productId, string $date): array;
}
