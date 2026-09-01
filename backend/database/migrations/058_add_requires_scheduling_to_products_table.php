<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

/**
 * Mismo toggle que ya existe para servicios (ver 055_add_requires_scheduling_to_services_table):
 * "¿Este producto se agenda con fecha y hora?" — pensado para productos que
 * requieren un turno además de la compra (ej. algo que se instala en el
 * taller). Default FALSE (0), al revés del default de servicios (1): acá no
 * hay comportamiento previo que preservar, y la mayoría de los productos no
 * se agendan, así que el toggle es opt-in en vez de opt-out.
 */
return new class extends Migration {
    public function up(PDO $connection): void
    {
        $connection->exec(
            'ALTER TABLE products ADD COLUMN requires_scheduling TINYINT(1) NOT NULL DEFAULT 0 AFTER stock'
        );
    }

    public function down(PDO $connection): void
    {
        $connection->exec('ALTER TABLE products DROP COLUMN requires_scheduling');
    }
};
