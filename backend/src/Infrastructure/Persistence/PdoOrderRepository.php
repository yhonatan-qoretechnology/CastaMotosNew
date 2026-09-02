<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Support\OrderStatusTransitions;
use App\Domain\Repositories\OrderRepositoryInterface;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use PDO;

final class PdoOrderRepository implements OrderRepositoryInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function existsByOrderNumber(string $orderNumber): bool
    {
        $stmt = $this->connection->prepare('SELECT 1 FROM orders WHERE order_number = :number');
        $stmt->execute(['number' => $orderNumber]);

        return (bool) $stmt->fetchColumn();
    }

    public function findByOrderNumberForUser(string $orderNumber, int $userId): ?array
    {
        $stmt = $this->connection->prepare(
            'SELECT * FROM orders WHERE order_number = :number AND user_id = :user_id AND deleted_at IS NULL'
        );
        $stmt->execute(['number' => $orderNumber, 'user_id' => $userId]);
        $order = $stmt->fetch();

        if ($order === false) {
            return null;
        }

        $items = $this->connection->prepare('SELECT * FROM order_items WHERE order_id = :order_id');
        $items->execute(['order_id' => $order['id']]);
        $order['items'] = $items->fetchAll();

        $history = $this->connection->prepare(
            'SELECT status, comment, created_at FROM order_status_history WHERE order_id = :order_id ORDER BY id ASC'
        );
        $history->execute(['order_id' => $order['id']]);
        $order['status_history'] = $history->fetchAll();

        $payments = $this->connection->prepare('SELECT * FROM payments WHERE order_id = :order_id');
        $payments->execute(['order_id' => $order['id']]);
        $order['payments'] = $payments->fetchAll();

        return $order;
    }

    public function listForUser(int $userId): array
    {
        $stmt = $this->connection->prepare(
            'SELECT id, order_number, status, total, delivery_method, created_at
             FROM orders
             WHERE user_id = :user_id AND deleted_at IS NULL
             ORDER BY created_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);
        $orders = $stmt->fetchAll();

        // Miniatura representativa por pedido (el primer ítem) para la
        // lista "Mis pedidos" — los order_items no guardan una foto propia
        // (solo snapshot de nombre/precio/sku), así que se busca la imagen
        // ACTUAL del producto/servicio; si ya no existe (borrado), queda sin
        // miniatura en vez de romper el listado.
        $thumbnailStmt = $this->connection->prepare(
            'SELECT oi.product_id, oi.service_id,
                    (SELECT url FROM product_images WHERE product_id = oi.product_id
                        ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS product_image,
                    (SELECT url FROM service_images WHERE service_id = oi.service_id
                        ORDER BY sort_order ASC LIMIT 1) AS service_image
             FROM order_items oi WHERE oi.order_id = :order_id ORDER BY oi.id ASC LIMIT 1'
        );

        foreach ($orders as &$order) {
            $thumbnailStmt->execute(['order_id' => $order['id']]);
            $item = $thumbnailStmt->fetch();

            $order['thumbnail'] = $item !== false ? ($item['product_image'] ?? $item['service_image']) : null;
            $order['thumbnail_type'] = ($item !== false && $item['product_id'] !== null) ? 'products' : 'services';
        }
        unset($order);

        return $orders;
    }

    public function createFromCheckout(array $order): array
    {
        $this->connection->beginTransaction();

        try {
            // Re-verificación autoritativa (sección 35/54): se bloquea cada fila de
            // producto con FOR UPDATE y se comprueba el stock real en este instante,
            // sin confiar en lo que se haya calculado antes de entrar a la transacción
            // (pudo cambiar por otra compra concurrente).
            foreach ($order['items'] as $item) {
                if ($item['product_id'] === null) {
                    continue;
                }

                $lock = $this->connection->prepare('SELECT stock FROM products WHERE id = :id FOR UPDATE');
                $lock->execute(['id' => $item['product_id']]);
                $currentStock = $lock->fetchColumn();

                if ($currentStock === false || (int) $currentStock < $item['quantity']) {
                    throw new ValidationException('No fue posible completar el pedido.', [
                        'items' => ["El producto \"{$item['name_snapshot']}\" ya no tiene stock suficiente."],
                    ]);
                }
            }

            // Re-verificación autoritativa de disponibilidad del horario (sección 12,
            // mismo criterio que el stock arriba): otra compra concurrente pudo haber
            // tomado el mismo horario después de que este carrito lo agendó. Se
            // excluyen los pedidos CANCELADO — cancelar libera el horario.
            foreach ($order['items'] as $item) {
                if ($item['service_id'] === null || empty($item['scheduled_at'])) {
                    continue;
                }

                $slotTaken = $this->connection->prepare(
                    "SELECT 1 FROM order_items oi
                        INNER JOIN orders o ON o.id = oi.order_id
                     WHERE oi.service_id = :service_id AND oi.scheduled_at = :scheduled_at
                        AND o.status != 'CANCELADO'
                     LIMIT 1 FOR UPDATE"
                );
                $slotTaken->execute(['service_id' => $item['service_id'], 'scheduled_at' => $item['scheduled_at']]);

                if ($slotTaken->fetchColumn() !== false) {
                    throw new ValidationException('No fue posible completar el pedido.', [
                        'items' => ["El horario elegido para \"{$item['name_snapshot']}\" ya no está disponible."],
                    ]);
                }
            }

            $orderStmt = $this->connection->prepare(
                'INSERT INTO orders (
                    order_number, user_id, address_id, store_id, delivery_method, payment_method_id,
                    status, subtotal, discount_total, coupon_id, coupon_code, shipping_total, tax_total, total, notes
                ) VALUES (
                    :order_number, :user_id, :address_id, :store_id, :delivery_method, :payment_method_id,
                    :status, :subtotal, :discount_total, :coupon_id, :coupon_code, :shipping_total, :tax_total, :total, :notes
                )'
            );
            $orderStmt->execute([
                'order_number' => $order['order_number'],
                'user_id' => $order['user_id'],
                'address_id' => $order['address_id'],
                'store_id' => $order['store_id'] ?? null,
                'delivery_method' => $order['delivery_method'],
                'payment_method_id' => $order['payment_method_id'],
                'status' => 'PENDIENTE',
                'subtotal' => $order['subtotal'],
                'discount_total' => $order['discount_total'],
                'coupon_id' => $order['coupon_id'] ?? null,
                'coupon_code' => $order['coupon_code'] ?? null,
                'shipping_total' => $order['shipping_total'],
                'tax_total' => $order['tax_total'],
                'total' => $order['total'],
                'notes' => $order['notes'] ?? null,
            ]);
            $orderId = (int) $this->connection->lastInsertId();

            $itemStmt = $this->connection->prepare(
                'INSERT INTO order_items (order_id, product_id, service_id, scheduled_at, variant_ids, name_snapshot, sku_snapshot, variant_label_snapshot, variant_color_snapshot, variant_color_name_snapshot, quantity, unit_price, subtotal)
                 VALUES (:order_id, :product_id, :service_id, :scheduled_at, :variant_ids, :name_snapshot, :sku_snapshot, :variant_label_snapshot, :variant_color_snapshot, :variant_color_name_snapshot, :quantity, :unit_price, :subtotal)'
            );
            $decrementStmt = $this->connection->prepare(
                'UPDATE products SET stock = stock - :quantity WHERE id = :id'
            );
            $movementStmt = $this->connection->prepare(
                "INSERT INTO inventory_movements (product_id, type, quantity, reason, reference_type, reference_id, created_by_user_id)
                 VALUES (:product_id, 'out', :quantity, :reason, 'order', :order_id, :user_id)"
            );
            // Reserva real (sección 25): se libera/restituye en updateStatus() cuando el
            // pedido llega a un estado terminal (ver OrderStatusTransitions). El "ON
            // DUPLICATE KEY" es un respaldo defensivo por si el producto no tuviera fila
            // en inventory todavía (siempre debería tenerla desde su creación).
            $reserveStmt = $this->connection->prepare(
                'INSERT INTO inventory (product_id, stock_current, stock_reserved, stock_minimum)
                 VALUES (:product_id, 0, :quantity, 0)
                 ON DUPLICATE KEY UPDATE stock_reserved = stock_reserved + VALUES(stock_reserved)'
            );
            $reservationMovementStmt = $this->connection->prepare(
                "INSERT INTO inventory_movements (product_id, type, quantity, reason, reference_type, reference_id, created_by_user_id)
                 VALUES (:product_id, 'reservation', :quantity, :reason, 'order', :order_id, :user_id)"
            );

            foreach ($order['items'] as $item) {
                $itemStmt->execute([
                    'order_id' => $orderId,
                    'product_id' => $item['product_id'],
                    'service_id' => $item['service_id'],
                    'scheduled_at' => $item['scheduled_at'] ?? null,
                    'variant_ids' => !empty($item['variant_ids']) ? json_encode($item['variant_ids']) : null,
                    'name_snapshot' => $item['name_snapshot'],
                    'sku_snapshot' => $item['sku_snapshot'] ?? null,
                    'variant_label_snapshot' => $item['variant_label_snapshot'] ?? null,
                    'variant_color_snapshot' => $item['variant_color_snapshot'] ?? null,
                    'variant_color_name_snapshot' => $item['variant_color_name_snapshot'] ?? null,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'subtotal' => $item['subtotal'],
                ]);

                if ($item['product_id'] !== null) {
                    $decrementStmt->execute(['quantity' => $item['quantity'], 'id' => $item['product_id']]);
                    $movementStmt->execute([
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'reason' => "Venta — pedido {$order['order_number']}",
                        'order_id' => $orderId,
                        'user_id' => $order['user_id'],
                    ]);
                    $reserveStmt->execute(['product_id' => $item['product_id'], 'quantity' => $item['quantity']]);
                    $reservationMovementStmt->execute([
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'reason' => "Reserva — pedido {$order['order_number']}",
                        'order_id' => $orderId,
                        'user_id' => $order['user_id'],
                    ]);
                }
            }

            $historyStmt = $this->connection->prepare(
                "INSERT INTO order_status_history (order_id, status, comment, changed_by_user_id)
                 VALUES (:order_id, 'PENDIENTE', 'Pedido creado', :user_id)"
            );
            $historyStmt->execute(['order_id' => $orderId, 'user_id' => $order['user_id']]);

            $paymentStmt = $this->connection->prepare(
                "INSERT INTO payments (order_id, payment_method_id, amount, status)
                 VALUES (:order_id, :payment_method_id, :amount, 'pending')"
            );
            $paymentStmt->execute([
                'order_id' => $orderId,
                'payment_method_id' => $order['payment_method_id'],
                'amount' => $order['total'],
            ]);

            $clearItems = $this->connection->prepare('DELETE FROM cart_items WHERE cart_id = :cart_id');
            $clearItems->execute(['cart_id' => $order['cart_id']]);

            $convertCart = $this->connection->prepare("UPDATE carts SET status = 'converted' WHERE id = :cart_id");
            $convertCart->execute(['cart_id' => $order['cart_id']]);

            $this->connection->commit();

            return ['id' => $orderId, 'order_number' => $order['order_number']];
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    public function paginateForAdmin(array $filters): array
    {
        $conditions = ['o.deleted_at IS NULL'];
        $params = [];

        if (!empty($filters['status'])) {
            $conditions[] = 'o.status = :status';
            $params['status'] = $filters['status'];
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->connection->prepare("SELECT COUNT(*) FROM orders o {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT o.id, o.order_number, o.status, o.total, o.delivery_method, o.created_at,
                    u.name AS customer_name, u.last_name AS customer_last_name, u.email AS customer_email
                FROM orders o
                INNER JOIN users u ON u.id = o.user_id
                {$where}
                ORDER BY o.created_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->connection->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['data' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * Módulo de reservas (sección 12): cada item de pedido con servicio agendado
     * ES una reserva — se listan directamente desde order_items/orders, sin
     * duplicar el estado del pedido en una tabla aparte.
     */
    public function paginateReservationsForAdmin(array $filters): array
    {
        $conditions = ['oi.service_id IS NOT NULL', 'oi.scheduled_at IS NOT NULL', 'o.deleted_at IS NULL'];
        $params = [];

        if (!empty($filters['status'])) {
            $conditions[] = 'o.status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['date'])) {
            $conditions[] = 'DATE(oi.scheduled_at) = :date';
            $params['date'] = $filters['date'];
        }

        if (!empty($filters['upcoming_only'])) {
            $conditions[] = 'oi.scheduled_at >= NOW()';
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 30)));
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->connection->prepare(
            "SELECT COUNT(*) FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id {$where}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT oi.id, oi.scheduled_at, oi.name_snapshot AS service_name, oi.quantity, oi.service_id,
                    o.order_number, o.status,
                    u.name AS customer_name, u.last_name AS customer_last_name,
                    u.email AS customer_email, u.phone AS customer_phone
                FROM order_items oi
                INNER JOIN orders o ON o.id = oi.order_id
                INNER JOIN users u ON u.id = o.user_id
                {$where}
                ORDER BY oi.scheduled_at ASC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->connection->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $reservations = array_map(function (array $row) {
            $row['next_statuses'] = OrderStatusTransitions::nextStates($row['status']);

            return $row;
        }, $stmt->fetchAll());

        return ['data' => $reservations, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public function findByOrderNumberForAdmin(string $orderNumber): ?array
    {
        $stmt = $this->connection->prepare(
            'SELECT o.*, u.name AS customer_name, u.last_name AS customer_last_name, u.email AS customer_email
             FROM orders o
             INNER JOIN users u ON u.id = o.user_id
             WHERE o.order_number = :number AND o.deleted_at IS NULL'
        );
        $stmt->execute(['number' => $orderNumber]);
        $order = $stmt->fetch();

        if ($order === false) {
            return null;
        }

        $items = $this->connection->prepare('SELECT * FROM order_items WHERE order_id = :order_id');
        $items->execute(['order_id' => $order['id']]);
        $order['items'] = $items->fetchAll();

        $history = $this->connection->prepare(
            'SELECT status, comment, created_at FROM order_status_history WHERE order_id = :order_id ORDER BY id ASC'
        );
        $history->execute(['order_id' => $order['id']]);
        $order['status_history'] = $history->fetchAll();

        $payments = $this->connection->prepare('SELECT * FROM payments WHERE order_id = :order_id');
        $payments->execute(['order_id' => $order['id']]);
        $order['payments'] = $payments->fetchAll();

        return $order;
    }

    public function findStatusById(int $orderId): ?string
    {
        $stmt = $this->connection->prepare('SELECT status FROM orders WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $orderId]);
        $status = $stmt->fetchColumn();

        return $status !== false ? $status : null;
    }

    public function updateStatus(int $orderId, string $newStatus, ?string $comment, int $changedByUserId): void
    {
        $this->connection->beginTransaction();

        try {
            // Re-verificación autoritativa dentro de la transacción (mismo criterio que
            // createFromCheckout): el estado pudo cambiar entre que el UseCase lo validó
            // y que esta llamada realmente se ejecuta.
            $lock = $this->connection->prepare('SELECT status FROM orders WHERE id = :id AND deleted_at IS NULL FOR UPDATE');
            $lock->execute(['id' => $orderId]);
            $currentStatus = $lock->fetchColumn();

            if ($currentStatus === false) {
                throw new NotFoundException('Pedido no encontrado.');
            }

            if (!OrderStatusTransitions::isAllowed((string) $currentStatus, $newStatus)) {
                throw new ValidationException('No fue posible cambiar el estado del pedido.', [
                    'status' => ["No se puede pasar de \"{$currentStatus}\" a \"{$newStatus}\"."],
                ]);
            }

            $this->connection->prepare('UPDATE orders SET status = :status WHERE id = :id')
                ->execute(['status' => $newStatus, 'id' => $orderId]);

            $this->connection->prepare(
                'INSERT INTO order_status_history (order_id, status, comment, changed_by_user_id)
                 VALUES (:order_id, :status, :comment, :user_id)'
            )->execute([
                'order_id' => $orderId,
                'status' => $newStatus,
                'comment' => $comment,
                'user_id' => $changedByUserId,
            ]);

            if (OrderStatusTransitions::isTerminal($newStatus)) {
                $this->releaseReservation($orderId, $newStatus, $changedByUserId);
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    /**
     * Libera la reserva de stock del pedido (sección 25) y, si la venta no se
     * concretó (CANCELADO/DEVUELTO), restituye products.stock. Se llama desde
     * dentro de la transacción de updateStatus(), nunca de forma independiente.
     */
    private function releaseReservation(int $orderId, string $newStatus, int $userId): void
    {
        $items = $this->connection->prepare(
            'SELECT product_id, quantity, name_snapshot FROM order_items WHERE order_id = :order_id AND product_id IS NOT NULL'
        );
        $items->execute(['order_id' => $orderId]);

        $release = $this->connection->prepare(
            'UPDATE inventory SET stock_reserved = GREATEST(0, stock_reserved - :quantity) WHERE product_id = :product_id'
        );
        $releaseMovement = $this->connection->prepare(
            "INSERT INTO inventory_movements (product_id, type, quantity, reason, reference_type, reference_id, created_by_user_id)
             VALUES (:product_id, 'release', :quantity, :reason, 'order', :order_id, :user_id)"
        );
        $restore = $this->connection->prepare('UPDATE products SET stock = stock + :quantity WHERE id = :id');
        $restoreMovement = $this->connection->prepare(
            "INSERT INTO inventory_movements (product_id, type, quantity, reason, reference_type, reference_id, created_by_user_id)
             VALUES (:product_id, 'in', :quantity, :reason, 'order', :order_id, :user_id)"
        );

        $restores = OrderStatusTransitions::restoresStock($newStatus);

        foreach ($items->fetchAll() as $item) {
            $release->execute(['quantity' => $item['quantity'], 'product_id' => $item['product_id']]);
            $releaseMovement->execute([
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'reason' => "Liberación de reserva — pedido pasó a {$newStatus}",
                'order_id' => $orderId,
                'user_id' => $userId,
            ]);

            if ($restores) {
                $restore->execute(['quantity' => $item['quantity'], 'id' => $item['product_id']]);
                $restoreMovement->execute([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'reason' => "Stock restituido — pedido pasó a {$newStatus}",
                    'order_id' => $orderId,
                    'user_id' => $userId,
                ]);
            }
        }
    }
}
