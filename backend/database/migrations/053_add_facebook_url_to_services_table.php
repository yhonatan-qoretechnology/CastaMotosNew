<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

/**
 * Enlace a la página de Facebook del servicio (ej. "Parqueadero" → página
 * real del negocio en Facebook) — opcional, se muestra en la ficha del
 * servicio junto al resto de la info de contacto/ubicación.
 */
return new class extends Migration {
    public function up(PDO $connection): void
    {
        $connection->exec('ALTER TABLE services ADD COLUMN facebook_url VARCHAR(255) NULL AFTER warranty');
    }

    public function down(PDO $connection): void
    {
        $connection->exec('ALTER TABLE services DROP COLUMN facebook_url');
    }
};
