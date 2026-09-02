<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

/**
 * El selector de variante en la ficha del producto (talla, color) existía
 * solo como adorno visual: no cambiaba el precio ni se mandaba al carrito
 * (sección nueva, reporte de que "falta el ajuste" de color/talla al comprar).
 *
 * Esta migración agrega lo necesario para que la elección real quede
 * guardada en cada línea del carrito y del pedido:
 *  - product_variants.type: agrupa variantes por dimensión ("Talla", "Color")
 *    para poder mostrar un selector por cada una en vez de una sola lista
 *    plana mezclando todo — NULL sigue funcionando como una sola lista (igual
 *    que antes) para productos con una sola dimensión de variante.
 *  - cart_items.variant_ids: qué variante(s) eligió el cliente para esa línea
 *    (JSON con los ids de product_variants, ej. [3,7] = "Talla M" + "Rojo").
 *  - order_items.variant_ids / variant_label_snapshot: mismo dato, pero
 *    "congelado" al confirmar el pedido (mismo criterio que name_snapshot/
 *    sku_snapshot — el pedido no debe cambiar si después se edita o borra la
 *    variante en el catálogo).
 */
return new class extends Migration {
    public function up(PDO $connection): void
    {
        $connection->exec(
            'ALTER TABLE product_variants ADD COLUMN type VARCHAR(80) NULL AFTER name'
        );
        $connection->exec(
            'ALTER TABLE cart_items ADD COLUMN variant_ids JSON NULL AFTER scheduled_at'
        );
        $connection->exec(
            'ALTER TABLE order_items
                ADD COLUMN variant_ids JSON NULL AFTER scheduled_at,
                ADD COLUMN variant_label_snapshot VARCHAR(255) NULL AFTER sku_snapshot'
        );
    }

    public function down(PDO $connection): void
    {
        $connection->exec('ALTER TABLE product_variants DROP COLUMN type');
        $connection->exec('ALTER TABLE cart_items DROP COLUMN variant_ids');
        $connection->exec('ALTER TABLE order_items DROP COLUMN variant_ids, DROP COLUMN variant_label_snapshot');
    }
};
