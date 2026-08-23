<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

/**
 * "Continuar con Google" (inicio de sesión) — vincula la cuenta local a su
 * ID de cuenta de Google ("sub" del token verificado), no solo al correo
 * (que en teoría podría cambiar del lado de Google). NULL para cuentas que
 * nunca usaron Google — la enorme mayoría, las que se registraron con
 * correo y contraseña como siempre.
 */
return new class extends Migration {
    public function up(PDO $connection): void
    {
        $connection->exec(
            'ALTER TABLE users ADD COLUMN google_id VARCHAR(64) NULL UNIQUE AFTER terms_accepted_ip'
        );
    }

    public function down(PDO $connection): void
    {
        $connection->exec('ALTER TABLE users DROP COLUMN google_id');
    }
};
