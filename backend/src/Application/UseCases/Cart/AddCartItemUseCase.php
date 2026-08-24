<?php

declare(strict_types=1);

namespace App\Application\UseCases\Cart;

use App\Domain\Repositories\CartRepositoryInterface;
use App\Domain\Repositories\ProductRepositoryInterface;
use App\Domain\Repositories\ServiceRepositoryInterface;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;

final class AddCartItemUseCase
{
    public function __construct(
        private CartRepositoryInterface $carts,
        private ProductRepositoryInterface $products,
        private ServiceRepositoryInterface $services
    ) {
    }

    public function handle(int $cartId, ?int $productId, ?int $serviceId, int $quantity, ?string $scheduledAt = null): void
    {
        if ($productId !== null) {
            $this->addProduct($cartId, $productId, $quantity);
            return;
        }

        $this->addService($cartId, $serviceId, $quantity, $scheduledAt);
    }

    private function addProduct(int $cartId, int $productId, int $quantity): void
    {
        $product = $this->products->find($productId);
        if ($product === null || $product['status'] !== 'active') {
            throw new NotFoundException('Producto no encontrado.');
        }

        $existing = $this->carts->findExistingItem($cartId, $productId, null);
        $newQuantity = $quantity + ($existing !== null ? (int) $existing['quantity'] : 0);

        if ($newQuantity > (int) $product['stock']) {
            throw new ValidationException('No fue posible agregar el producto.', [
                'quantity' => ['La cantidad solicitada supera el stock disponible.'],
            ]);
        }

        if ($existing !== null) {
            $this->carts->updateItemQuantity((int) $existing['id'], $newQuantity);
        } else {
            $this->carts->addItem($cartId, $productId, null, $quantity, (float) $product['price']);
        }
    }

    /**
     * A diferencia de un producto, cada servicio agregado es una RESERVA para
     * una fecha/hora concreta (sección 12) — nunca se combina con un item
     * existente sumando cantidades (agendar "2x corte de cabello" en una sola
     * fila no tiene sentido), cada uno queda como su propia fila de carrito.
     */
    private function addService(int $cartId, int $serviceId, int $quantity, ?string $scheduledAt): void
    {
        $service = $this->services->find($serviceId);
        if ($service === null || $service['status'] !== 'active') {
            throw new NotFoundException('Servicio no encontrado.');
        }

        // No todos los servicios se agendan con fecha/hora (sección nueva,
        // /admin → Servicios → "Requiere agendar fecha y hora") — los que no,
        // se agregan al carrito como un item normal (mismo criterio que un
        // producto: si ya estaba en el carrito, suma cantidad en la MISMA
        // fila en vez de duplicarla — a diferencia de un servicio agendado,
        // acá no hay fecha/hora que los distinga entre sí).
        if ((int) ($service['requires_scheduling'] ?? 1) === 0) {
            $existing = $this->carts->findExistingItem($cartId, null, $serviceId);
            if ($existing !== null) {
                $this->carts->updateItemQuantity((int) $existing['id'], (int) $existing['quantity'] + $quantity);
            } else {
                $this->carts->addItem($cartId, null, $serviceId, $quantity, (float) $service['price'], null);
            }
            return;
        }

        if ($scheduledAt === null || $scheduledAt === '') {
            throw new ValidationException('No fue posible agendar el servicio.', [
                'scheduled_at' => ['Debes elegir una fecha y hora para el servicio.'],
            ]);
        }

        $timestamp = strtotime($scheduledAt);
        if ($timestamp === false || $timestamp <= time()) {
            throw new ValidationException('No fue posible agendar el servicio.', [
                'scheduled_at' => ['La fecha y hora deben ser válidas y futuras.'],
            ]);
        }

        $this->carts->addItem($cartId, null, $serviceId, $quantity, (float) $service['price'], date('Y-m-d H:i:s', $timestamp));
    }
}
