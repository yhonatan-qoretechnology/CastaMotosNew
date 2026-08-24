<?php

declare(strict_types=1);

namespace App\Domain\Repositories;

interface CategoryRepositoryInterface
{
    /**
     * @param bool $includeInactive Si es true, incluye inactivas/borradas (solo para gestión).
     */
    public function tree(bool $includeInactive = false): array;

    public function findBySlug(string $slug, bool $includeInactive = false): ?array;

    public function find(int $id): ?array;

    public function existsBySlug(string $slug, ?int $excludeId = null): bool;

    public function exists(int $id): bool;

    /**
     * True si asignarle a $categoryId el padre $newParentId crearía un ciclo
     * (porque $newParentId es la propia categoría o una descendiente suya).
     */
    public function wouldCreateCycle(int $categoryId, int $newParentId): bool;

    public function create(array $data): int;

    public function update(int $id, array $data): void;

    /** Ícono/imagen de la categoría (sección nueva, /admin → Categorías) — reemplaza el que hubiera. */
    public function updateImage(int $id, string $filename): void;

    public function delete(int $id): void;
}
