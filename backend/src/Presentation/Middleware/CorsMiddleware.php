<?php

declare(strict_types=1);

namespace App\Presentation\Middleware;

use App\Infrastructure\Config\Config;

/**
 * Configura los headers CORS según los orígenes permitidos en .env.
 * En producción, CORS_ALLOWED_ORIGINS debe listar dominios específicos, no "*".
 */
final class CorsMiddleware
{
    public static function apply(): void
    {
        $allowedOrigins = Config::get('app.cors.allowed_origins', '*');

        header('Access-Control-Allow-Origin: ' . $allowedOrigins);
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        // Sin "Allow-Credentials: true" a propósito (auditoría de seguridad):
        // el sitio no usa cookies para nada (la sesión es un Bearer token en
        // localStorage, que el navegador nunca manda solo por sí mismo), así
        // que este header no protegía nada — y combinado con el "*" de arriba
        // era además una combinación que el propio navegador rechaza por spec.
    }
}
