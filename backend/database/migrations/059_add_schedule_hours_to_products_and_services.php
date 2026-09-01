<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

/**
 * Horario de atención propio por servicio/producto (mismo patrón que
 * shipping_cost, ver 056_add_shipping_cost_to_products_and_services) — NULL
 * = sigue usando el horario general del sitio (Configuración → Horario de
 * atención, site_settings.business_hours_start/end). Si se cargan los dos,
 * ese rango reemplaza al general SOLO para agendar ESE servicio/producto en
 * particular (ver loadServiceTimeSlots/loadProductTimeSlots/loadWashTimeSlots
 * en el frontend) — solo tiene efecto si "requires_scheduling" está activo.
 */
return new class extends Migration {
    public function up(PDO $connection): void
    {
        $connection->exec(
            'ALTER TABLE services
                ADD COLUMN schedule_hours_start VARCHAR(5) NULL AFTER requires_scheduling,
                ADD COLUMN schedule_hours_end VARCHAR(5) NULL AFTER schedule_hours_start'
        );
        $connection->exec(
            'ALTER TABLE products
                ADD COLUMN schedule_hours_start VARCHAR(5) NULL AFTER requires_scheduling,
                ADD COLUMN schedule_hours_end VARCHAR(5) NULL AFTER schedule_hours_start'
        );
    }

    public function down(PDO $connection): void
    {
        $connection->exec('ALTER TABLE services DROP COLUMN schedule_hours_start, DROP COLUMN schedule_hours_end');
        $connection->exec('ALTER TABLE products DROP COLUMN schedule_hours_start, DROP COLUMN schedule_hours_end');
    }
};
