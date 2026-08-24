<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

/**
 * "Es solo informativo (sin reserva ni compra)" — /admin → Servicios. Para
 * servicios como "Parqueadero", que no se reservan ni se pagan online: la
 * ficha muestra "Cómo llegar" como acción principal en vez del box de
 * compra/reserva. Default FALSE (0): ningún servicio existente cambia de
 * comportamiento por esta migración.
 */
return new class extends Migration {
    public function up(PDO $connection): void
    {
        $connection->exec(
            'ALTER TABLE services ADD COLUMN is_informational TINYINT(1) NOT NULL DEFAULT 0 AFTER requires_scheduling'
        );
    }

    public function down(PDO $connection): void
    {
        $connection->exec('ALTER TABLE services DROP COLUMN is_informational');
    }
};
