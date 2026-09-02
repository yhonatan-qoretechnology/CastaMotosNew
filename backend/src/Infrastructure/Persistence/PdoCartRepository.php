<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Repositories\CartRepositoryInterface;
use App\Infrastructure\Config\Config;
use PDO;

final class PdoCartRepository implements CartRepositoryInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function resolveActiveCart(?int $userId, ?string $token): array
    {
        if ($userId !== null) {
            $stmt = $this->connection->prepare(
                "SELECT * FROM carts WHERE user_id = :user_id AND status = 'active' ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute(['user_id' => $userId]);
            $cart = $stmt->fetch();

            if ($cart === false) {
                $cart = $this->createCart($userId, null);
            }

            $cart['token'] = null;

            return $cart;
        }

        if ($token !== null) {
            $stmt = $this->connection->prepare(
                "SELECT * FROM carts WHERE session_token = :token AND status = 'active' LIMIT 1"
            );
            $stmt->execute(['token' => $token]);
            $cart = $stmt->fetch();

            if ($cart !== false) {
                $cart['token'] = $token;

                return $cart;
            }
        }

        $newToken = bin2hex(random_bytes((int) Config::get('app.cart.guest_token_bytes', 20)));
        $cart = $this->createCart(null, $newToken);
        $cart['token'] = $newToken;

        return $cart;
    }

    private function createCart(?int $userId, ?string $token): array
    {
        $stmt = $this->connection->prepare(
            "INSERT INTO carts (user_id, session_token, status) VALUES (:user_id, :token, 'active')"
        );
        $stmt->execute(['user_id' => $userId, 'token' => $token]);
        $id = (int) $this->connection->lastInsertId();

        return $this->find($id);
    }

    public function find(int $cartId): ?array
    {
        $stmt = $this->connection->prepare('SELECT * FROM carts WHERE id = :id');
        $stmt->execute(['id' => $cartId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function itemsWithLiveData(int $cartId): array
    {
        $stmt = $this->connection->prepare(
            'SELECT ci.id, ci.product_id, ci.service_id, ci.scheduled_at, ci.variant_ids, ci.quantity, ci.unit_price_snapshot,
                    p.name AS product_name, p.slug AS product_slug, p.price AS product_price, p.sku AS product_sku,
                    p.stock AS product_stock, p.status AS product_status, p.shipping_cost AS product_shipping_cost,
                    p.discount_percentage AS product_discount, p.tax_rate AS product_tax,
                    p.requires_scheduling AS product_requires_scheduling,
                    (SELECT url FROM product_images pi WHERE pi.product_id = p.id
                        ORDER BY pi.is_primary DESC, pi.sort_order ASC LIMIT 1) AS product_image,
                    s.name AS service_name, s.slug AS service_slug, s.price AS service_price, s.status AS service_status,
                    s.requires_scheduling AS service_requires_scheduling, s.shipping_cost AS service_shipping_cost,
                    (SELECT url FROM service_images si WHERE si.service_id = s.id ORDER BY si.sort_order ASC LIMIT 1) AS service_image
             FROM cart_items ci
             LEFT JOIN products p ON p.id = ci.product_id AND p.deleted_at IS NULL
             LEFT JOIN services s ON s.id = ci.service_id AND s.deleted_at IS NULL
             WHERE ci.cart_id = :cart_id
             ORDER BY ci.id ASC'
        );
        $stmt->execute(['cart_id' => $cartId]);
        $rows = $stmt->fetchAll();

        // Nombre/ajuste de precio de cada variante elegida, en UNA sola consulta
        // para todo el carrito (no una por línea) — ver resolveVariantsByIds().
        $variantIds = [];
        foreach ($rows as $row) {
            foreach (json_decode($row['variant_ids'] ?? '', true) ?: [] as $id) {
                $variantIds[(int) $id] = true;
            }
        }
        $variantsById = $variantIds ? $this->resolveVariantsByIds(array_keys($variantIds)) : [];

        return array_map(fn (array $row) => $this->normalizeItem($row, $variantsById), $rows);
    }

    /** @return array<int, array{name: string, price_modifier: float, color_hex: ?string}> */
    private function resolveVariantsByIds(array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->connection->prepare(
            "SELECT id, name, price_modifier, color_hex FROM product_variants WHERE id IN ({$placeholders})"
        );
        $stmt->execute($ids);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['id']] = [
                'name' => $row['name'],
                'price_modifier' => (float) $row['price_modifier'],
                'color_hex' => $row['color_hex'] ?: null,
            ];
        }
        return $result;
    }

    /** @param array<int, array{name: string, price_modifier: float, color_hex: ?string}> $variantsById */
    private function normalizeItem(array $row, array $variantsById = []): array
    {
        $isProduct = $row['product_id'] !== null;
        $quantity = (int) $row['quantity'];

        $available = $isProduct ? $row['product_status'] === 'active' : $row['service_status'] === 'active';
        $name = $isProduct ? $row['product_name'] : $row['service_name'];

        // Talla/color elegidos para esta línea (solo aplica a productos, ver
        // AddCartItemUseCase::addProduct()) — el ajuste de precio de cada
        // variante se suma al precio base, y el nombre se junta en una sola
        // etiqueta ("Talla M, Rojo") para mostrar en carrito/checkout. Si
        // alguna de las elegidas tiene círculo de color cargado (/admin), se
        // expone aparte (variant_color/variant_color_name) para poder pintar
        // el círculo real en vez de solo el texto.
        $selectedVariantIds = json_decode($row['variant_ids'] ?? '', true) ?: [];
        $variantAdjustment = 0.0;
        $variantNames = [];
        $variantColor = null;
        $variantColorName = null;
        foreach ($selectedVariantIds as $variantId) {
            if (isset($variantsById[(int) $variantId])) {
                $variantAdjustment += $variantsById[(int) $variantId]['price_modifier'];
                $variantNames[] = $variantsById[(int) $variantId]['name'];
                if ($variantColor === null && $variantsById[(int) $variantId]['color_hex'] !== null) {
                    $variantColor = $variantsById[(int) $variantId]['color_hex'];
                    $variantColorName = $variantsById[(int) $variantId]['name'];
                }
            }
        }

        $unitPrice = $available ? (float) ($isProduct ? $row['product_price'] : $row['service_price']) + $variantAdjustment : 0.0;
        $availableStock = $isProduct && $available ? (int) $row['product_stock'] : null;

        return [
            'id' => (int) $row['id'],
            'type' => $isProduct ? 'product' : 'service',
            'reference_id' => (int) ($row['product_id'] ?? $row['service_id']),
            'name' => $available ? $name : ($name ?? 'Producto/servicio ya no disponible'),
            'slug' => $available ? ($isProduct ? $row['product_slug'] : $row['service_slug']) : null,
            'sku' => $isProduct ? $row['product_sku'] : null,
            'image' => $isProduct ? $row['product_image'] : $row['service_image'],
            'unit_price' => $unitPrice,
            'unit_price_snapshot' => (float) $row['unit_price_snapshot'],
            'discount_percentage' => $isProduct ? (float) ($row['product_discount'] ?? 0) : 0.0,
            'tax_rate' => $isProduct ? (float) ($row['product_tax'] ?? 0) : 0.0,
            'quantity' => $quantity,
            'available_stock' => $availableStock,
            'is_available' => $available,
            'quantity_exceeds_stock' => $availableStock !== null && $quantity > $availableStock,
            // Reserva (sección 12) o, ahora también, producto agendado (mismo mecanismo) — null si no aplica.
            'scheduled_at' => $row['scheduled_at'] ?? null,
            'variant_ids' => $selectedVariantIds ?: null,
            'variant_label' => $variantNames ? implode(', ', $variantNames) : null,
            'variant_color' => $variantColor,
            'variant_color_name' => $variantColorName,
            'requires_scheduling' => $isProduct
                ? (int) ($row['product_requires_scheduling'] ?? 0) === 1
                : (int) ($row['service_requires_scheduling'] ?? 1) === 1,
            // NULL = sin override, usa la tarifa general de envío (ver CartPricingCalculator::shipping()).
            'shipping_cost' => $available
                ? ($isProduct ? $row['product_shipping_cost'] : $row['service_shipping_cost'])
                : null,
        ];
    }

    public function findItem(int $itemId): ?array
    {
        $stmt = $this->connection->prepare('SELECT * FROM cart_items WHERE id = :id');
        $stmt->execute(['id' => $itemId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function itemBelongsToCart(int $itemId, int $cartId): bool
    {
        $stmt = $this->connection->prepare('SELECT 1 FROM cart_items WHERE id = :id AND cart_id = :cart_id');
        $stmt->execute(['id' => $itemId, 'cart_id' => $cartId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Para un producto con variantes, "ya existe" significa MISMO producto Y
     * MISMA combinación de variantes elegidas — agregar "S, Verde" dos veces
     * debe sumar cantidad en la misma fila (como cualquier producto sin
     * variantes), no crear una fila duplicada; agregar "S, Verde" y después
     * "M, Rojo" sí son líneas distintas. Como puede haber varias filas del
     * mismo product_id con distintas variantes, se filtra en PHP comparando
     * el conjunto de ids (sin importar el orden) en vez de en el SQL — JSON
     * no se compara fácil como igualdad de conjunto en una consulta.
     */
    public function findExistingItem(int $cartId, ?int $productId, ?int $serviceId, ?array $variantIds = null): ?array
    {
        if ($productId !== null) {
            $stmt = $this->connection->prepare(
                'SELECT * FROM cart_items WHERE cart_id = :cart_id AND product_id = :product_id'
            );
            $stmt->execute(['cart_id' => $cartId, 'product_id' => $productId]);

            $target = $this->normalizedVariantKey($variantIds);
            foreach ($stmt->fetchAll() as $row) {
                $rowVariantIds = json_decode($row['variant_ids'] ?? '', true) ?: null;
                if ($this->normalizedVariantKey($rowVariantIds) === $target) {
                    return $row;
                }
            }
            return null;
        }

        $stmt = $this->connection->prepare(
            'SELECT * FROM cart_items WHERE cart_id = :cart_id AND service_id = :service_id'
        );
        $stmt->execute(['cart_id' => $cartId, 'service_id' => $serviceId]);

        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Clave comparable para "mismo conjunto de variantes elegidas", sin importar el orden. NULL/[] siempre dan la misma clave ("sin variantes"). */
    private function normalizedVariantKey(?array $variantIds): string
    {
        if (empty($variantIds)) {
            return '';
        }
        $sorted = array_unique(array_map('intval', $variantIds));
        sort($sorted);
        return implode(',', $sorted);
    }

    public function addItem(int $cartId, ?int $productId, ?int $serviceId, int $quantity, float $unitPriceSnapshot, ?string $scheduledAt = null, ?array $variantIds = null): int
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO cart_items (cart_id, product_id, service_id, scheduled_at, variant_ids, quantity, unit_price_snapshot)
             VALUES (:cart_id, :product_id, :service_id, :scheduled_at, :variant_ids, :quantity, :unit_price_snapshot)'
        );
        $stmt->execute([
            'cart_id' => $cartId,
            'product_id' => $productId,
            'service_id' => $serviceId,
            'scheduled_at' => $scheduledAt,
            'variant_ids' => $variantIds ? json_encode(array_values($variantIds)) : null,
            'quantity' => $quantity,
            'unit_price_snapshot' => $unitPriceSnapshot,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function updateItemQuantity(int $itemId, int $quantity): void
    {
        $stmt = $this->connection->prepare('UPDATE cart_items SET quantity = :quantity WHERE id = :id');
        $stmt->execute(['quantity' => $quantity, 'id' => $itemId]);
    }

    public function removeItem(int $itemId): void
    {
        $stmt = $this->connection->prepare('DELETE FROM cart_items WHERE id = :id');
        $stmt->execute(['id' => $itemId]);
    }

    public function clear(int $cartId): void
    {
        $stmt = $this->connection->prepare('DELETE FROM cart_items WHERE cart_id = :cart_id');
        $stmt->execute(['cart_id' => $cartId]);
    }

    public function setCoupon(int $cartId, ?int $couponId): void
    {
        $stmt = $this->connection->prepare('UPDATE carts SET coupon_id = :coupon_id WHERE id = :id');
        $stmt->execute(['coupon_id' => $couponId, 'id' => $cartId]);
    }

    public function markConverted(int $cartId): void
    {
        $stmt = $this->connection->prepare("UPDATE carts SET status = 'converted' WHERE id = :id");
        $stmt->execute(['id' => $cartId]);
    }

    public function mergeGuestCartIntoUser(int $userId, string $token): void
    {
        $guestStmt = $this->connection->prepare(
            "SELECT * FROM carts WHERE session_token = :token AND status = 'active' LIMIT 1"
        );
        $guestStmt->execute(['token' => $token]);
        $guestCart = $guestStmt->fetch();

        if ($guestCart === false) {
            return;
        }

        $userCart = $this->resolveActiveCart($userId, null);

        if ((int) $guestCart['id'] === (int) $userCart['id']) {
            return;
        }

        $this->connection->beginTransaction();
        try {
            $guestItems = $this->connection->prepare('SELECT * FROM cart_items WHERE cart_id = :cart_id');
            $guestItems->execute(['cart_id' => $guestCart['id']]);

            foreach ($guestItems->fetchAll() as $item) {
                $variantIds = json_decode($item['variant_ids'] ?? '', true) ?: null;
                // Mismo criterio que AddCartItemUseCase: una reserva (scheduled_at)
                // nunca se suma a una fila existente (cada una es su propia línea).
                // Una variante SÍ se suma, pero solo contra una fila con la MISMA
                // combinación exacta — findExistingItem() ya hace esa comparación.
                // Sin esto, fusionar el carrito de invitado con el de la cuenta
                // podía mezclar dos elecciones distintas en una sola fila y perder
                // una de las dos en silencio.
                $existing = $item['scheduled_at'] === null
                    ? $this->findExistingItem(
                        (int) $userCart['id'],
                        $item['product_id'] !== null ? (int) $item['product_id'] : null,
                        $item['service_id'] !== null ? (int) $item['service_id'] : null,
                        $variantIds
                    )
                    : null;

                if ($existing !== null) {
                    $this->updateItemQuantity((int) $existing['id'], (int) $existing['quantity'] + (int) $item['quantity']);
                } else {
                    $this->addItem(
                        (int) $userCart['id'],
                        $item['product_id'] !== null ? (int) $item['product_id'] : null,
                        $item['service_id'] !== null ? (int) $item['service_id'] : null,
                        (int) $item['quantity'],
                        (float) $item['unit_price_snapshot'],
                        $item['scheduled_at'],
                        $variantIds
                    );
                }
            }

            $delete = $this->connection->prepare('DELETE FROM cart_items WHERE cart_id = :cart_id');
            $delete->execute(['cart_id' => $guestCart['id']]);

            $deleteCart = $this->connection->prepare('DELETE FROM carts WHERE id = :id');
            $deleteCart->execute(['id' => $guestCart['id']]);

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }
}
