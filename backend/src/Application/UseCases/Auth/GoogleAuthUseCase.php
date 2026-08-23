<?php

declare(strict_types=1);

namespace App\Application\UseCases\Auth;

use App\Application\Auth\GoogleTokenVerifier;
use App\Domain\Repositories\LoginHistoryRepositoryInterface;
use App\Domain\Repositories\UserRepositoryInterface;
use App\Exceptions\UnauthorizedException;
use App\Infrastructure\Auth\JwtService;
use App\Infrastructure\Config\Config;

/**
 * "Continuar con Google" — un solo click cubre login Y registro:
 *  - Si ya existe una cuenta con ese correo (se haya registrado por Google
 *    antes o con contraseña normal), inicia sesión ahí y la vincula
 *    (google_id) si todavía no lo estaba.
 *  - Si no existe, crea la cuenta — Google ya verificó el correo, así que
 *    se salta el paso de "revisa tu correo para verificarla" (sección 7) y
 *    la contraseña queda con un hash aleatorio inutilizable (nunca hace
 *    falta: siempre entra por Google, o pide "olvidé mi contraseña" si
 *    algún día quiere entrar también con contraseña).
 *
 * "terms_accepted" (sección de registro) se considera aceptado con el clic
 * en "Continuar con Google" — el botón vive junto al mismo texto/enlace de
 * términos que ya muestra el formulario de registro común.
 */
final class GoogleAuthUseCase
{
    public function __construct(
        private UserRepositoryInterface $users,
        private LoginHistoryRepositoryInterface $loginHistory
    ) {
    }

    public function handle(string $idToken, string $ipAddress, string $userAgent): array
    {
        $clientId = (string) Config::get('app.auth.google_client_id', '');
        $google = GoogleTokenVerifier::verify($idToken, $clientId);

        $user = $this->users->findByEmail($google['email']);

        if ($user === null) {
            $userId = $this->users->create([
                'name' => $google['name'],
                'last_name' => $google['last_name'] !== '' ? $google['last_name'] : '—',
                'email' => $google['email'],
                'phone' => null,
                'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                'terms_accepted_at' => date('Y-m-d H:i:s'),
                'terms_accepted_ip' => $ipAddress,
            ]);
            $this->users->markEmailVerified($userId);
            $this->users->linkGoogleId($userId, $google['google_id']);
            $user = $this->users->findById($userId);
        } else {
            if ($user->isLocked()) {
                $this->loginHistory->record($user->id, $google['email'], $ipAddress, $userAgent, 'locked');
                throw new UnauthorizedException('Cuenta bloqueada temporalmente por intentos fallidos. Intenta más tarde.');
            }
            if ($user->status !== 'active') {
                throw new UnauthorizedException('Esta cuenta no está activa. Contacta a soporte.');
            }

            $this->users->linkGoogleId($user->id, $google['google_id']);
            $user = $this->users->findById($user->id); // roles/estado frescos para el JWT
        }

        $this->users->registerLoginSuccess($user->id);
        $this->loginHistory->record($user->id, $google['email'], $ipAddress, $userAgent, 'success');

        $token = JwtService::issue($user->id, $user->roles);

        return ['user' => $user, 'token' => $token];
    }
}
