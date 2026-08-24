<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

/**
 * Costo de envío por producto/servicio (sección nueva, /admin) — NULL =
 * sigue usando la tarifa general (SHIPPING_FLAT_RATE + umbral de envío
 * gratis, ver CartPricingCalculator). Si se carga un valor (incluido 0.00 =
 * envío gratis para ESE item puntual), ese valor reemplaza a la tarifa
 * general para ese item específico — algunos productos/servicios cuestan
 * más enviar que otros, y algunos no se cobran.
 */
return new class extends Migration {
    public function up(PDO $connection): void
    {
        $connection->exec('ALTER TABLE products ADD COLUMN shipping_cost DECIMAL(10,2) NULL AFTER price');
        $connection->exec('ALTER TABLE services ADD COLUMN shipping_cost DECIMAL(10,2) NULL AFTER price');
    }

    public function down(PDO $connection): void
    {
        $connection->exec('ALTER TABLE products DROP COLUMN shipping_cost');
        $connection->exec('ALTER TABLE services DROP COLUMN shipping_cost');
    }
};
