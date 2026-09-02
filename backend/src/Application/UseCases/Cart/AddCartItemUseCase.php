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

    public function handle(int $cartId, ?int $productId, ?int $serviceId, int $quantity, ?string $scheduledAt = null, ?array $variantIds = null): void
    {
        if ($productId !== null) {
            $this->addProduct($cartId, $productId, $quantity, $scheduledAt, $variantIds);
            return;
        }

        $this->addService($cartId, $serviceId, $quantity, $scheduledAt);
    }

    private function addProduct(int $cartId, int $productId, int $quantity, ?string $scheduledAt, ?array $variantIds): void
    {
        $product = $this->products->find($productId);
        if ($product === null || $product['status'] !== 'active') {
            throw new NotFoundException('Producto no encontrado.');
        }

        // Nunca se confía en el price_modifier que pudiera mandar el cliente:
        // se valida que cada id sea de verdad una variante de ESTE producto
        // (product.variants sale de PdoProductRepository::find(), en vivo) y
        // el ajuste de precio se recalcula acá con el price_modifier real.
        $variantIds = $this->resolveVariantSelection($product, $variantIds);
        $priceModifier = $this->variantPriceModifier($product, $variantIds);
        $unitPrice = (float) $product['price'] + $priceModifier;

        // Mismo toggle opcional que ya existe en servicios (ver addService()
        // más abajo) — un producto que requiere agendar (ej. algo que se
        // instala en el taller) queda como su propia fila con fecha/hora,
        // nunca sumado a una fila existente.
        if ((int) ($product['requires_scheduling'] ?? 0) === 1) {
            if ($scheduledAt === null || $scheduledAt === '') {
                throw new ValidationException('No fue posible agendar el producto.', [
                    'scheduled_at' => ['Debes elegir una fecha y hora para este producto.'],
                ]);
            }

            $timestamp = strtotime($scheduledAt);
            if ($timestamp === false || $timestamp <= time()) {
                throw new ValidationException('No fue posible agendar el producto.', [
                    'scheduled_at' => ['La fecha y hora deben ser válidas y futuras.'],
                ]);
            }

            if ($quantity > (int) $product['stock']) {
                throw new ValidationException('No fue posible agregar el producto.', [
                    'quantity' => ['La cantidad solicitada supera el stock disponible.'],
                ]);
            }

            $this->carts->addItem($cartId, $productId, null, $quantity, $unitPrice, date('Y-m-d H:i:s', $timestamp), $variantIds);
            return;
        }

        // "Ya está en el carrito" exige el MISMO producto Y la MISMA
        // combinación de variantes — agregar "Talla M" dos veces suma
        // cantidad en la misma fila (como cualquier producto sin variantes);
        // agregar "Talla M" y después "Talla L" son líneas distintas (ver
        // findExistingItem, que compara el conjunto de ids elegidos).
        $existing = $this->carts->findExistingItem($cartId, $productId, null, $variantIds);
        $newQuantity = $quantity + ($existing !== null ? (int) $existing['quantity'] : 0);

        if ($newQuantity > (int) $product['stock']) {
            throw new ValidationException('No fue posible agregar el producto.', [
                'quantity' => ['La cantidad solicitada supera el stock disponible.'],
            ]);
        }

        if ($existing !== null) {
            $this->carts->updateItemQuantity((int) $existing['id'], $newQuantity);
        } else {
            $this->carts->addItem($cartId, $productId, null, $quantity, $unitPrice, null, $variantIds);
        }
    }

    /**
     * @param int[]|null $variantIds
     * @return int[]|null null si no se eligió ninguna variante.
     */
    private function resolveVariantSelection(array $product, ?array $variantIds): ?array
    {
        if ($variantIds === null || $variantIds === []) {
            return null;
        }

        $validIds = array_map('intval', array_column($product['variants'] ?? [], 'id'));
        $normalized = array_values(array_unique(array_map('intval', $variantIds)));

        foreach ($normalized as $id) {
            if (!in_array($id, $validIds, true)) {
                throw new ValidationException('No fue posible agregar el producto.', [
                    'variant_ids' => ['Una de las variantes elegidas no es válida para este producto.'],
                ]);
            }
        }

        return $normalized;
    }

    /** @param int[]|null $variantIds */
    private function variantPriceModifier(array $product, ?array $variantIds): float
    {
        if ($variantIds === null) {
            return 0.0;
        }

        $modifier = 0.0;
        foreach ($product['variants'] ?? [] as $variant) {
            if (in_array((int) $variant['id'], $variantIds, true)) {
                $modifier += (float) $variant['price_modifier'];
            }
        }
        return $modifier;
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
