<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

/**
 * Representación gráfica para variantes de color (opcional, /admin →
 * Productos → variantes): un código hex ("#E53935") en vez de solo texto
 * ("Rojo"). Cuando TODAS las variantes de un grupo (mismo "type") tienen
 * color cargado, la ficha del producto muestra círculos de color en vez del
 * desplegable de texto — si falta en alguna, ese grupo sigue mostrando el
 * desplegable normal (ver variantOptionsMarkup() en producto.js).
 */
return new class extends Migration {
    public function up(PDO $connection): void
    {
        $connection->exec(
            'ALTER TABLE product_variants ADD COLUMN color_hex VARCHAR(7) NULL AFTER type'
        );
    }

    public function down(PDO $connection): void
    {
        $connection->exec('ALTER TABLE product_variants DROP COLUMN color_hex');
    }
};
