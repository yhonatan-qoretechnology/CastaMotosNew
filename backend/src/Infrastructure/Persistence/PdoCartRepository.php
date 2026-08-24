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
            'SELECT ci.id, ci.product_id, ci.service_id, ci.scheduled_at, ci.quantity, ci.unit_price_snapshot,
                    p.name AS product_name, p.slug AS product_slug, p.price AS product_price, p.sku AS product_sku,
                    p.stock AS product_stock, p.status AS product_status, p.shipping_cost AS product_shipping_cost,
                    p.discount_percentage AS product_discount, p.tax_rate AS product_tax,
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

        return array_map([$this, 'normalizeItem'], $stmt->fetchAll());
    }

    private function normalizeItem(array $row): array
    {
        $isProduct = $row['product_id'] !== null;
        $quantity = (int) $row['quantity'];

        $available = $isProduct ? $row['product_status'] === 'active' : $row['service_status'] === 'active';
        $name = $isProduct ? $row['product_name'] : $row['service_name'];

        $unitPrice = $available ? (float) ($isProduct ? $row['product_price'] : $row['service_price']) : 0.0;
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
            // Solo aplica a servicios (sección 12: reservas) — siempre null en productos.
            'scheduled_at' => $row['scheduled_at'] ?? null,
            // Solo aplica a servicios — true en productos (nunca se usa ahí, ver
            // assertItemsAreCheckoutable en CheckoutUseCase, que solo mira esto para type==='service').
            'requires_scheduling' => $isProduct ? true : (int) ($row['service_requires_scheduling'] ?? 1) === 1,
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

    public function findExistingItem(int $cartId, ?int $productId, ?int $serviceId): ?array
    {
        if ($productId !== null) {
            $stmt = $this->connection->prepare(
                'SELECT * FROM cart_items WHERE cart_id = :cart_id AND product_id = :product_id'
            );
            $stmt->execute(['cart_id' => $cartId, 'product_id' => $productId]);
        } else {
            $stmt = $this->connection->prepare(
                'SELECT * FROM cart_items WHERE cart_id = :cart_id AND service_id = :service_id'
            );
            $stmt->execute(['cart_id' => $cartId, 'service_id' => $serviceId]);
        }

        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function addItem(int $cartId, ?int $productId, ?int $serviceId, int $quantity, float $unitPriceSnapshot, ?string $scheduledAt = null): int
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO cart_items (cart_id, product_id, service_id, scheduled_at, quantity, unit_price_snapshot)
             VALUES (:cart_id, :product_id, :service_id, :scheduled_at, :quantity, :unit_price_snapshot)'
        );
        $stmt->execute([
            'cart_id' => $cartId,
            'product_id' => $productId,
            'service_id' => $serviceId,
            'scheduled_at' => $scheduledAt,
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
                $existing = $this->findExistingItem(
                    (int) $userCart['id'],
                    $item['product_id'] !== null ? (int) $item['product_id'] : null,
                    $item['service_id'] !== null ? (int) $item['service_id'] : null
                );

                if ($existing !== null) {
                    $this->updateItemQuantity((int) $existing['id'], (int) $existing['quantity'] + (int) $item['quantity']);
                } else {
                    $this->addItem(
                        (int) $userCart['id'],
                        $item['product_id'] !== null ? (int) $item['product_id'] : null,
                        $item['service_id'] !== null ? (int) $item['service_id'] : null,
                        (int) $item['quantity'],
                        (float) $item['unit_price_snapshot']
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
