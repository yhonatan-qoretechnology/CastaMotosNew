<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Exceptions\UnauthorizedException;

/**
 * Verifica el token de identidad ("credential") que entrega Google Identity
 * Services en el navegador — sin SDK, un solo cURL contra el endpoint
 * oficial de Google para esto (tokeninfo), mismo criterio "sin dependencias
 * nuevas" que AnthropicAiProvider/OpenAiAiProvider. Google firma y valida
 * el token del lado suyo; acá solo hace falta confirmar que:
 *  1) Google diga que es válido (si no, responde con error HTTP).
 *  2) Fue emitido PARA esta app (aud === nuestro Client ID) — sin este
 *     chequeo, cualquier token válido de CUALQUIER app que use Google
 *     Identity Services serviría para entrar acá.
 *  3) El correo esté verificado por Google (siempre lo está en este flujo,
 *     pero se confirma explícitamente en vez de asumirlo).
 */
final class GoogleTokenVerifier
{
    private const ENDPOINT = 'https://oauth2.googleapis.com/tokeninfo';
    private const TIMEOUT_SECONDS = 10;

    /**
     * @return array{google_id: string, email: string, name: string, last_name: string}
     * @throws UnauthorizedException si el token es inválido, expiró, o no es para esta app.
     */
    public static function verify(string $idToken, string $clientId): array
    {
        if ($clientId === '') {
            throw new UnauthorizedException('El inicio de sesión con Google todavía no está disponible.');
        }

        $ch = curl_init(self::ENDPOINT . '?id_token=' . urlencode($idToken));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new UnauthorizedException("No fue posible verificar el inicio de sesión con Google: {$error}");
        }

        $payload = json_decode((string) $body, true);

        if ($status !== 200 || !is_array($payload)) {
            throw new UnauthorizedException('El token de Google no es válido o expiró. Intenta de nuevo.');
        }

        if (($payload['aud'] ?? null) !== $clientId) {
            throw new UnauthorizedException('El token de Google no corresponde a esta aplicación.');
        }

        if (($payload['email_verified'] ?? 'false') !== 'true') {
            throw new UnauthorizedException('Tu correo de Google no está verificado.');
        }

        $email = (string) ($payload['email'] ?? '');
        if ($email === '') {
            throw new UnauthorizedException('Google no devolvió un correo válido.');
        }

        return [
            'google_id' => (string) ($payload['sub'] ?? ''),
            'email' => $email,
            'name' => (string) ($payload['given_name'] ?? $payload['name'] ?? 'Usuario'),
            'last_name' => (string) ($payload['family_name'] ?? ''),
        ];
    }
}
