<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

/**
 * Evidencia de aceptación de términos y condiciones en el registro: hasta
 * ahora "terms_accepted" solo se validaba (Validator::make en
 * AuthController::register, regla "accepted") pero nunca quedaba guardado en
 * ningún lado — si alguna vez hace falta demostrar que un usuario aceptó los
 * términos, no había cómo. Se guarda fecha/hora + IP desde la que se aceptó
 * (RegisterUserUseCase), no solo un booleano, para que sea evidencia real.
 */
return new class extends Migration {
    public function up(PDO $connection): void
    {
        $connection->exec(
            'ALTER TABLE users
                ADD COLUMN terms_accepted_at DATETIME NULL AFTER status,
                ADD COLUMN terms_accepted_ip VARCHAR(45) NULL AFTER terms_accepted_at'
        );
    }

    public function down(PDO $connection): void
    {
        $connection->exec('ALTER TABLE users DROP COLUMN terms_accepted_at, DROP COLUMN terms_accepted_ip');
    }
};
