<?php

declare(strict_types=1);

namespace App\Domain\Repositories;

interface BrandRepositoryInterface
{
    public function list(bool $includeInactive = false): array;

    public function find(int $id): ?array;

    public function exists(int $id): bool;

    public function existsBySlug(string $slug, ?int $excludeId = null): bool;

    public function create(array $data): int;

    public function update(int $id, array $data): void;

    /** Logo de la marca (sección nueva, /admin → Marcas) — reemplaza el que hubiera. */
    public function updateLogo(int $id, string $filename): void;

    public function delete(int $id): void;
}
