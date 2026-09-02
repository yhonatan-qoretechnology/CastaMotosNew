<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Exceptions\UnauthorizedException;
use App\Infrastructure\Config\Config;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use UnexpectedValueException;

/**
 * Emisión y verificación de JWT (sección 3/7). El payload solo lleva lo
 * mínimo (sub, roles, iat, exp): los permisos se resuelven en BD en cada
 * petición para que un cambio de rol/permiso no dependa de que expire el token.
 */
final class JwtService
{
    private const ALGORITHM = 'HS256';

    /**
     * @param string[] $roles
     */
    public static function issue(int $userId, array $roles, bool $remember = false): string
    {
        $secret = (string) Config::get('app.jwt.secret');
        $ttlSeconds = $remember
            ? ((int) Config::get('app.auth.remember_ttl_days', 30)) * 86400
            : (int) Config::get('app.jwt.ttl', 3600);

        $now = time();
        $payload = [
            'sub' => $userId,
            'roles' => $roles,
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
        ];

        return JWT::encode($payload, $secret, self::ALGORITHM);
    }

    /**
     * @return array{sub:int, roles:string[], iat:int}
     */
    public static function verify(string $token): array
    {
        $secret = (string) Config::get('app.jwt.secret');

        try {
            $decoded = JWT::decode($token, new Key($secret, self::ALGORITHM));
        } catch (ExpiredException $e) {
            throw new UnauthorizedException('La sesión expiró. Inicia sesión nuevamente.');
        } catch (SignatureInvalidException|UnexpectedValueException $e) {
            throw new UnauthorizedException('Token de autenticación inválido.');
        }

        return [
            'sub' => (int) $decoded->sub,
            'roles' => (array) $decoded->roles,
            // Necesario para que AuthMiddleware pueda invalidar tokens
            // emitidos antes de un cambio de contraseña (sección seguridad).
            'iat' => (int) $decoded->iat,
        ];
    }
}
