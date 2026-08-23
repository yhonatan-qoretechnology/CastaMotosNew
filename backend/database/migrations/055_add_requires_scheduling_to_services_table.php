<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

/**
 * "¿Este servicio se agenda con fecha y hora?" (sección nueva, /admin →
 * Servicios) — hasta ahora TODO servicio exigía elegir fecha/hora para
 * agregarlo al carrito (AddCartItemUseCase::addService), pero no todos los
 * servicios son así (ej. algo que se cobra fijo, sin cita puntual). Default
 * TRUE (1): mantiene el comportamiento de siempre para los servicios que ya
 * existen — nadie pierde su flujo de reserva por esta migración.
 */
return new class extends Migration {
    public function up(PDO $connection): void
    {
        $connection->exec(
            'ALTER TABLE services ADD COLUMN requires_scheduling TINYINT(1) NOT NULL DEFAULT 1 AFTER duration_minutes'
        );
    }

    public function down(PDO $connection): void
    {
        $connection->exec('ALTER TABLE services DROP COLUMN requires_scheduling');
    }
};
