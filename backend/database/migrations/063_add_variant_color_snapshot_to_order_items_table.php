<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

/**
 * "Que en el detalle aparezca el color y title (nombre del color)" — el
 * pedido ya guardaba "L, Negro" como texto plano (variant_label_snapshot,
 * ver 061), pero no el código hex para poder pintar el círculo de color en
 * el detalle del pedido. Mismo criterio "snapshot" que el resto de
 * order_items: si después se borra o recolorea esa variante en el catálogo,
 * el pedido ya confirmado no debe cambiar.
 */
return new class extends Migration {
    public function up(PDO $connection): void
    {
        $connection->exec(
            'ALTER TABLE order_items
                ADD COLUMN variant_color_snapshot VARCHAR(7) NULL AFTER variant_label_snapshot,
                ADD COLUMN variant_color_name_snapshot VARCHAR(150) NULL AFTER variant_color_snapshot'
        );
    }

    public function down(PDO $connection): void
    {
        $connection->exec('ALTER TABLE order_items DROP COLUMN variant_color_snapshot, DROP COLUMN variant_color_name_snapshot');
    }
};
