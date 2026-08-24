<?php

declare(strict_types=1);

namespace Tests;

use App\Application\Support\CartPricingCalculator;
use PHPUnit\Framework\TestCase;

final class CartPricingCalculatorTest extends TestCase
{
    public function test_calcula_subtotal_descuento_impuestos_y_envio(): void
    {
        $items = [
            ['unit_price' => 100000.0, 'quantity' => 2, 'discount_percentage' => 10.0, 'tax_rate' => 19.0],
        ];

        // subtotal = 200000; descuento 10% = 20000; base gravable = 180000; iva 19% = 34200
        $result = CartPricingCalculator::calculate($items, 'domicilio', 12000.0, 300000.0);

        $this->assertSame(200000.0, $result['subtotal']);
        $this->assertSame(20000.0, $result['discount_total']);
        $this->assertSame(34200.0, $result['tax_total']);
        $this->assertSame(12000.0, $result['shipping_total']);
        $this->assertSame(226200.0, $result['total']); // 180000 + 34200 + 12000
    }

    public function test_envio_gratis_sobre_el_umbral(): void
    {
        $items = [
            ['unit_price' => 350000.0, 'quantity' => 1, 'discount_percentage' => 0.0, 'tax_rate' => 0.0],
        ];

        $result = CartPricingCalculator::calculate($items, 'domicilio', 12000.0, 300000.0);

        $this->assertSame(0.0, $result['shipping_total']);
    }

    public function test_recogida_en_tienda_no_cobra_envio_aunque_no_llegue_al_umbral(): void
    {
        $items = [
            ['unit_price' => 10000.0, 'quantity' => 1, 'discount_percentage' => 0.0, 'tax_rate' => 0.0],
        ];

        $result = CartPricingCalculator::calculate($items, 'recogida_tienda', 12000.0, 300000.0);

        $this->assertSame(0.0, $result['shipping_total']);
    }

    public function test_carrito_vacio_no_cobra_envio(): void
    {
        $result = CartPricingCalculator::calculate([], 'domicilio', 12000.0, 300000.0);

        $this->assertSame(0.0, $result['shipping_total']);
        $this->assertSame(0.0, $result['total']);
    }

    public function test_shipping_cost_por_item_reemplaza_la_tarifa_general_para_ese_item(): void
    {
        $items = [
            ['unit_price' => 10000.0, 'quantity' => 1, 'discount_percentage' => 0.0, 'tax_rate' => 0.0, 'shipping_cost' => 5000.0],
        ];

        // Sin la tarifa general (no llega al umbral, pero el item trae su propio costo).
        $result = CartPricingCalculator::calculate($items, 'domicilio', 12000.0, 300000.0);

        $this->assertSame(5000.0, $result['shipping_total']);
    }

    public function test_shipping_cost_en_cero_es_envio_gratis_para_ese_item_a_proposito(): void
    {
        $items = [
            ['unit_price' => 10000.0, 'quantity' => 3, 'discount_percentage' => 0.0, 'tax_rate' => 0.0, 'shipping_cost' => 0.0],
        ];

        $result = CartPricingCalculator::calculate($items, 'domicilio', 12000.0, 300000.0);

        $this->assertSame(0.0, $result['shipping_total']);
    }

    public function test_items_con_y_sin_shipping_cost_se_combinan(): void
    {
        $items = [
            // Con override: 2 unidades × 3000 = 6000, sin importar el umbral.
            ['unit_price' => 10000.0, 'quantity' => 2, 'discount_percentage' => 0.0, 'tax_rate' => 0.0, 'shipping_cost' => 3000.0],
            // Sin override: entra en la tarifa general (no llega al umbral de 300000).
            ['unit_price' => 20000.0, 'quantity' => 1, 'discount_percentage' => 0.0, 'tax_rate' => 0.0],
        ];

        $result = CartPricingCalculator::calculate($items, 'domicilio', 12000.0, 300000.0);

        $this->assertSame(18000.0, $result['shipping_total']); // 6000 (override) + 12000 (tarifa general)
    }

    public function test_recogida_en_tienda_ignora_shipping_cost_por_item(): void
    {
        $items = [
            ['unit_price' => 10000.0, 'quantity' => 1, 'discount_percentage' => 0.0, 'tax_rate' => 0.0, 'shipping_cost' => 5000.0],
        ];

        $result = CartPricingCalculator::calculate($items, 'recogida_tienda', 12000.0, 300000.0);

        $this->assertSame(0.0, $result['shipping_total']);
    }
}
