<?php

declare(strict_types=1);

namespace App\Domain\Repositories;

interface CartRepositoryInterface
{
    /**
     * Resuelve el carrito activo del usuario (si está autenticado) o del
     * token de invitado; crea uno nuevo (y un token, si hacía falta) cuando
     * no existe. El array devuelto siempre incluye "token" (null si es de un
     * usuario autenticado, no aplica).
     */
    public function resolveActiveCart(?int $userId, ?string $token): array;

    public function find(int $cartId): ?array;

    /**
     * Ítems del carrito con precio/stock/nombre/imagen EN VIVO desde
     * products/services (sección 54: nunca confiar en datos guardados).
     */
    public function itemsWithLiveData(int $cartId): array;

    public function findItem(int $itemId): ?array;

    public function itemBelongsToCart(int $itemId, int $cartId): bool;

    /**
     * Si ya existe un ítem para ese producto/servicio en el carrito, sería
     * mejor sumarle cantidad que duplicar fila; el UseCase decide con esto.
     */
    public function findExistingItem(int $cartId, ?int $productId, ?int $serviceId): ?array;

    /** @param int[]|null $variantIds ids de product_variants elegidos para esta línea (talla/color/etc.) */
    public function addItem(int $cartId, ?int $productId, ?int $serviceId, int $quantity, float $unitPriceSnapshot, ?string $scheduledAt = null, ?array $variantIds = null): int;

    public function updateItemQuantity(int $itemId, int $quantity): void;

    public function removeItem(int $itemId): void;

    public function clear(int $cartId): void;

    public function markConverted(int $cartId): void;

    /**
     * Mueve los ítems del carrito de invitado (por token) al carrito del
     * usuario recién autenticado (sección 18: invitado → autenticado).
     */
    public function mergeGuestCartIntoUser(int $userId, string $token): void;

    /** Aplica (o quita, con null) un cupón al carrito (sección 30). */
    public function setCoupon(int $cartId, ?int $couponId): void;
}
