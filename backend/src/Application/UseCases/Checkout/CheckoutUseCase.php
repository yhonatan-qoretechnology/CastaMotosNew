<?php

declare(strict_types=1);

namespace App\Application\UseCases\Checkout;

use App\Application\Notifications\PushNotificationFactory;
use App\Application\Payments\PaymentGatewayFactory;
use App\Application\Support\CartPricingCalculator;
use App\Application\Support\CouponValidator;
use App\Application\Support\OrderNumberGenerator;
use App\Domain\Repositories\AddressRepositoryInterface;
use App\Domain\Repositories\CartRepositoryInterface;
use App\Domain\Repositories\CouponRepositoryInterface;
use App\Domain\Repositories\NotificationRepositoryInterface;
use App\Domain\Repositories\OrderRepositoryInterface;
use App\Domain\Repositories\PaymentMethodRepositoryInterface;
use App\Domain\Repositories\PushSubscriptionRepositoryInterface;
use App\Exceptions\ValidationException;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Logging\Logger;
use App\Infrastructure\Mail\EmailTemplates;
use App\Infrastructure\Mail\Mailer;

/**
 * Pasos 2-6 del checkout (sección 19): valida dirección/método de pago,
 * recalcula todo el carrito con datos EN VIVO (sección 54) y delega en
 * OrderRepository::createFromCheckout() la transacción atómica que crea
 * el pedido y descuenta stock (sección 35).
 */
final class CheckoutUseCase
{
    public function __construct(
        private CartRepositoryInterface $carts,
        private OrderRepositoryInterface $orders,
        private AddressRepositoryInterface $addresses,
        private PaymentMethodRepositoryInterface $paymentMethods,
        private ?PushSubscriptionRepositoryInterface $pushSubscriptions = null,
        private ?CouponRepositoryInterface $coupons = null,
        private ?NotificationRepositoryInterface $notifications = null
    ) {
    }

    public function handle(
        int $userId,
        int $cartId,
        int $addressId,
        int $paymentMethodId,
        string $deliveryMethod,
        ?string $notes,
        string $customerName = '',
        string $customerEmail = ''
    ): array {
        if (!$this->addresses->belongsToUser($addressId, $userId)) {
            throw new ValidationException('No fue posible confirmar el pedido.', [
                'address_id' => ['La dirección seleccionada no es válida.'],
            ]);
        }

        $paymentMethod = $this->paymentMethods->find($paymentMethodId);
        if ($paymentMethod === null || (int) $paymentMethod['is_enabled'] !== 1) {
            throw new ValidationException('No fue posible confirmar el pedido.', [
                'payment_method_id' => ['El método de pago seleccionado no está disponible.'],
            ]);
        }

        // Sección 52: el método está "activado" en la lista, pero eso no
        // garantiza que ya pueda procesar un cobro de verdad (ej. una
        // pasarela activada sin sus llaves cargadas todavía) — se falla acá,
        // ANTES de crear el pedido, no después de que el cliente ya pagó.
        PaymentGatewayFactory::make($paymentMethod['code'])->assertConfigured($paymentMethod['config'] ?? []);

        $items = $this->carts->itemsWithLiveData($cartId);
        $this->assertItemsAreCheckoutable($items);

        // Re-verificación autoritativa del cupón (sección 30/54), mismo
        // criterio que el stock y las pasarelas de pago: lo que se validó al
        // aplicarlo en el carrito pudo cambiar (venció, alguien más agotó su
        // cupo) entre ese momento y este — nunca se confía en lo ya validado.
        $coupon = null;
        if ($this->coupons !== null) {
            $cart = $this->carts->find($cartId);
            if ($cart !== null && !empty($cart['coupon_id'])) {
                $coupon = $this->coupons->find((int) $cart['coupon_id']);
                if ($coupon !== null) {
                    $subtotalPreview = CartPricingCalculator::calculate($items, $deliveryMethod, 0, 0);
                    $subtotalAfterItemDiscounts = $subtotalPreview['subtotal'] - $subtotalPreview['discount_total'];
                    $alreadyUsed = $this->coupons->hasBeenUsedByUser($userId, (int) $coupon['id']);
                    CouponValidator::assertUsable($coupon, $subtotalAfterItemDiscounts, $alreadyUsed);
                }
            }
        }

        $pricing = CartPricingCalculator::calculate(
            $items,
            $deliveryMethod,
            (float) Config::get('app.shipping.flat_rate', 12000),
            (float) Config::get('app.shipping.free_threshold', 300000),
            $coupon
        );

        $orderNumber = OrderNumberGenerator::generate(fn (string $number) => $this->orders->existsByOrderNumber($number));

        $orderItems = array_map(static function (array $item) {
            return [
                'product_id' => $item['type'] === 'product' ? $item['reference_id'] : null,
                'service_id' => $item['type'] === 'service' ? $item['reference_id'] : null,
                'name_snapshot' => $item['name'],
                'sku_snapshot' => $item['sku'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'subtotal' => round($item['unit_price'] * $item['quantity'], 2),
                // Reserva del servicio (sección 12) — null en items de producto.
                'scheduled_at' => $item['scheduled_at'] ?? null,
                // Talla/color elegidos — se "congelan" acá igual que name_snapshot/
                // sku_snapshot: el pedido no debe cambiar si después se edita o
                // borra la variante en el catálogo (sección 54).
                'variant_ids' => $item['variant_ids'] ?? null,
                'variant_label_snapshot' => $item['variant_label'] ?? null,
                'variant_color_snapshot' => $item['variant_color'] ?? null,
                'variant_color_name_snapshot' => $item['variant_color_name'] ?? null,
            ];
        }, $items);

        // Nota: no se usa "...$pricing" (spread con claves string) porque requiere
        // PHP 8.1+; este proyecto se mantiene compatible con PHP 8.0 (ver README).
        $orderPayload = array_merge([
            'order_number' => $orderNumber,
            'user_id' => $userId,
            'address_id' => $addressId,
            'store_id' => null,
            'delivery_method' => $deliveryMethod,
            'payment_method_id' => $paymentMethodId,
            'notes' => $notes,
            'cart_id' => $cartId,
            'items' => $orderItems,
            'coupon_id' => $coupon['id'] ?? null,
            'coupon_code' => $coupon['code'] ?? null,
        ], $pricing);

        $result = $this->orders->createFromCheckout($orderPayload);

        if ($coupon !== null) {
            $this->coupons->incrementUsage((int) $coupon['id']);
        }

        // "Se crea pedido" (sección 23). Nunca debe tumbar el checkout si el
        // correo falla (SMTP caído, etc.) — el pedido ya quedó creado y
        // confirmado en la respuesta; el correo es un aviso adicional.
        if ($customerEmail !== '') {
            try {
                $orderUrl = rtrim((string) Config::get('app.url'), '/') . '/pedido/' . $orderNumber;
                $emailOrder = array_merge($orderPayload, [
                    'payment_method_name' => $paymentMethod['name'],
                    'address' => $this->addresses->find($addressId),
                ]);
                $content = EmailTemplates::orderCreatedEmail($customerName, $emailOrder, $orderUrl);
                Mailer::send($customerEmail, $content['subject'], $content['html'], 'order_created', $userId);
            } catch (\Throwable $e) {
                Logger::error('Fallo al enviar correo de pedido creado', ['order_number' => $orderNumber, 'error' => $e->getMessage()]);
            }
        }

        // "Nuevo pedido" (sección 24). Best-effort igual que el correo: sin
        // proveedor real configurado, LoggingPushProvider solo lo registra.
        if ($this->pushSubscriptions !== null) {
            try {
                $tokens = $this->pushSubscriptions->tokensForUser($userId);
                PushNotificationFactory::make()->send($tokens, 'Pedido recibido', "Tu pedido {$orderNumber} fue creado.", ['order_number' => $orderNumber]);
            } catch (\Throwable $e) {
                Logger::error('Fallo al enviar push de pedido creado', ['order_number' => $orderNumber, 'error' => $e->getMessage()]);
            }
        }

        // Campanita del admin: aviso de pedido nuevo (a diferencia del push de
        // arriba, que es para el cliente sobre SU pedido, este es para quien
        // tiene que atenderlo — administrador/superadministrador).
        if ($this->notifications !== null) {
            try {
                $this->notifications->notifyAdmins(
                    'new_order',
                    'Nuevo pedido',
                    "Se generó el pedido {$orderNumber} por " . number_format($orderPayload['total'], 0, ',', '.') . ' COP.',
                    ['order_number' => $orderNumber]
                );
            } catch (\Throwable $e) {
                Logger::error('Fallo al notificar pedido nuevo al admin', ['order_number' => $orderNumber, 'error' => $e->getMessage()]);
            }
        }

        return $result;
    }

    private function assertItemsAreCheckoutable(array $items): void
    {
        if (empty($items)) {
            throw new ValidationException('No fue posible confirmar el pedido.', [
                'cart' => ['El carrito está vacío.'],
            ]);
        }

        foreach ($items as $item) {
            if (!$item['is_available']) {
                throw new ValidationException('No fue posible confirmar el pedido.', [
                    'cart' => ["\"{$item['name']}\" ya no está disponible."],
                ]);
            }

            if ($item['quantity_exceeds_stock']) {
                throw new ValidationException('No fue posible confirmar el pedido.', [
                    'cart' => ["\"{$item['name']}\" ya no tiene stock suficiente."],
                ]);
            }

            // Antes solo se revisaba en servicios — ahora un producto también puede
            // requerir agendar (mismo toggle, ver AddCartItemUseCase::addProduct()),
            // así que la validación mira "requires_scheduling" sin importar el tipo.
            if (($item['requires_scheduling'] ?? false) && empty($item['scheduled_at'])) {
                throw new ValidationException('No fue posible confirmar el pedido.', [
                    'cart' => ["\"{$item['name']}\" no tiene fecha y hora agendada."],
                ]);
            }
        }
    }
}
