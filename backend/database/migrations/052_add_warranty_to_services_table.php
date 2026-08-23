<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

/**
 * Garantía para servicios — products.warranty ya existía (010), pero
 * services no tenía equivalente. Mismo tipo/tamaño que en products para
 * mantenerlo consistente; el texto es libre porque la garantía de un
 * producto ("12 meses de fábrica") y la de un servicio ("satisfacción
 * garantizada / repetimos el lavado sin costo") no siguen el mismo formato.
 */
return new class extends Migration {
    public function up(PDO $connection): void
    {
        $connection->exec('ALTER TABLE services ADD COLUMN warranty VARCHAR(150) NULL AFTER cancellation_policy');
    }

    public function down(PDO $connection): void
    {
        $connection->exec('ALTER TABLE services DROP COLUMN warranty');
    }
};
