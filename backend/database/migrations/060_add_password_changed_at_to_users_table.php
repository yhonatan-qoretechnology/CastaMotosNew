<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

/**
 * Revocación de sesión al cambiar/resetear la contraseña (auditoría de
 * seguridad): hasta ahora un JWT ya emitido seguía siendo válido hasta su
 * expiración natural (hasta 30 días con "recordarme") aunque el dueño de la
 * cuenta cambiara la contraseña creyendo que así cerraba cualquier sesión
 * filtrada/robada. AuthMiddleware compara el "iat" del token contra esta
 * columna — un token emitido ANTES del último cambio de contraseña deja de
 * ser válido. NULL (cuentas existentes antes de esta migración) = sin
 * restricción, no rompe sesiones activas de nadie al desplegar esto.
 */
return new class extends Migration {
    public function up(PDO $connection): void
    {
        $connection->exec(
            'ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL AFTER password'
        );
    }

    public function down(PDO $connection): void
    {
        $connection->exec('ALTER TABLE users DROP COLUMN password_changed_at');
    }
};
