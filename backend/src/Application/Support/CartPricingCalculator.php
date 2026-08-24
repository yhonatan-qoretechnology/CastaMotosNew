<?php

declare(strict_types=1);

namespace App\Application\Support;

/**
 * Única fuente de verdad para calcular subtotal/descuento/impuestos/envío/
 * total (sección 18). La usan tanto GET /api/cart (vista previa) como
 * CheckoutUseCase (cálculo autoritativo) para no tener el total calculado
 * de dos formas distintas que puedan desincronizarse.
 *
 * Es una función pura: recibe los ítems ya resueltos con precio/descuento/
 * impuesto EN VIVO (el repositorio de carrito los arma), no toca la BD.
 */
final class CartPricingCalculator
{
    /**
     * @param array $items Cada uno con: unit_price, quantity, discount_percentage, tax_rate.
     * @param ?array $coupon Fila de coupons ya validada (sección 30) o null si no hay ninguno aplicado.
     */
    public static function calculate(
        array $items,
        string $deliveryMethod,
        float $shippingFlatRate,
        float $shippingFreeThreshold,
        ?array $coupon = null
    ): array {
        if (empty($items)) {
            return ['subtotal' => 0.0, 'discount_total' => 0.0, 'tax_total' => 0.0, 'shipping_total' => 0.0, 'total' => 0.0, 'coupon_discount' => 0.0];
        }

        $subtotal = 0.0;
        $discountTotal = 0.0;
        $taxTotal = 0.0;

        foreach ($items as $item) {
            $lineSubtotal = $item['unit_price'] * $item['quantity'];
            $lineDiscount = $lineSubtotal * ($item['discount_percentage'] / 100);
            $taxableBase = $lineSubtotal - $lineDiscount;
            $lineTax = $taxableBase * ($item['tax_rate'] / 100);

            $subtotal += $lineSubtotal;
            $discountTotal += $lineDiscount;
            $taxTotal += $lineTax;
        }

        // El cupón (sección 30) descuenta sobre lo que queda DESPUÉS de los
        // descuentos por producto (previous_price/discount_percentage,
        // Fase 3) — nunca se calcula sobre el subtotal bruto, evita doble
        // descuento sobre la misma porción del precio.
        $amountAfterItemDiscounts = $subtotal - $discountTotal;
        $couponDiscount = $coupon !== null ? CouponValidator::discountAmount($coupon, $amountAfterItemDiscounts) : 0.0;
        $discountTotal += $couponDiscount;

        $amountAfterDiscount = $amountAfterItemDiscounts - $couponDiscount;
        $shippingTotal = self::shipping($items, $deliveryMethod, $shippingFlatRate, $shippingFreeThreshold);
        $total = $amountAfterDiscount + $taxTotal + $shippingTotal;

        return [
            'subtotal' => round($subtotal, 2),
            'discount_total' => round($discountTotal, 2),
            'tax_total' => round($taxTotal, 2),
            'shipping_total' => round($shippingTotal, 2),
            'total' => round($total, 2),
            'coupon_discount' => round($couponDiscount, 2),
        ];
    }

    /**
     * Envío por ítem (sección nueva, /admin → Productos/Servicios): un item
     * con "shipping_cost" cargado (aunque sea 0.00 = gratis a propósito)
     * cobra ESE valor fijo en vez de entrar en el cálculo general — cada uno
     * puede costar distinto, o no cobrarse. Los items SIN ese campo cargado
     * siguen la tarifa plana de siempre (con su umbral de envío gratis),
     * calculada solo sobre lo que aportan esos items sin override.
     *
     * @param array $items Cada uno con: unit_price, quantity, y opcionalmente shipping_cost.
     */
    private static function shipping(
        array $items,
        string $deliveryMethod,
        float $flatRate,
        float $freeThreshold
    ): float {
        if ($deliveryMethod === 'recogida_tienda') {
            return 0.0;
        }

        $overrideTotal = 0.0;
        $generalSubtotal = 0.0;
        $hasGeneralItem = false;

        foreach ($items as $item) {
            $lineSubtotal = $item['unit_price'] * $item['quantity'];

            if (array_key_exists('shipping_cost', $item) && $item['shipping_cost'] !== null) {
                $overrideTotal += (float) $item['shipping_cost'] * $item['quantity'];
            } else {
                $generalSubtotal += $lineSubtotal;
                $hasGeneralItem = true;
            }
        }

        $generalShipping = ($hasGeneralItem && $generalSubtotal < $freeThreshold) ? $flatRate : 0.0;

        return $overrideTotal + $generalShipping;
    }
}
