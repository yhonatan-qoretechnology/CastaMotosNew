<?php

declare(strict_types=1);

namespace App\Presentation\Middleware;

use App\Domain\Repositories\UserRepositoryInterface;
use App\Exceptions\UnauthorizedException;
use App\Infrastructure\Auth\JwtService;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Http\Middleware;
use App\Infrastructure\Http\Request;
use App\Infrastructure\Persistence\PdoUserRepository;

/**
 * Exige un JWT válido en "Authorization: Bearer <token>" y adjunta el
 * usuario autenticado al Request bajo el atributo "auth_user".
 */
final class AuthMiddleware implements Middleware
{
    private UserRepositoryInterface $users;

    public function __construct(?UserRepositoryInterface $users = null)
    {
        $this->users = $users ?? new PdoUserRepository(Connection::get());
    }

    public function handle(Request $request): Request
    {
        $header = (string) $request->header('Authorization', '');

        if (!str_starts_with($header, 'Bearer ')) {
            throw new UnauthorizedException('Debes iniciar sesión para continuar.');
        }

        $token = substr($header, 7);
        $claims = JwtService::verify($token);

        $user = $this->users->findById($claims['sub']);
        if ($user === null || $user->status !== 'active') {
            throw new UnauthorizedException('Sesión inválida.');
        }

        // Revocación de sesión al cambiar/resetear la contraseña (sección
        // seguridad): un JWT emitido ANTES del último cambio de contraseña ya
        // no sirve, aunque todavía no haya expirado por su cuenta — evita que
        // un token robado/filtrado siga funcionando después de que el dueño
        // de la cuenta cambia la clave creyendo que así queda a salvo.
        if ($user->passwordChangedAt !== null && ($claims['iat'] ?? 0) < strtotime($user->passwordChangedAt)) {
            throw new UnauthorizedException('La sesión expiró. Inicia sesión nuevamente.');
        }

        return $request->withAttribute('auth_user', $user);
    }
}
