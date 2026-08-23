<?php

declare(strict_types=1);

namespace App\Application\UseCases\Auth;

use App\Domain\Repositories\UserRepositoryInterface;
use App\Exceptions\ValidationException;
use App\Infrastructure\Auth\JwtService;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Mail\EmailTemplates;
use App\Infrastructure\Mail\Mailer;

/**
 * Registro de usuario (sección 7). Crea la cuenta con rol "cliente", envía
 * el correo de verificación y devuelve una sesión ya iniciada (JWT), para
 * no obligar al usuario a loguearse inmediatamente después de registrarse.
 */
final class RegisterUserUseCase
{
    public function __construct(private UserRepositoryInterface $users)
    {
    }

    public function handle(array $input, string $ip = '0.0.0.0'): array
    {
        if ($this->users->emailExists($input['email'])) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'email' => ['Ya existe una cuenta registrada con este correo.'],
            ]);
        }

        $userId = $this->users->create([
            'name' => $input['name'],
            'last_name' => $input['last_name'],
            'email' => $input['email'],
            'phone' => $input['phone'] ?? null,
            'password' => password_hash($input['password'], PASSWORD_DEFAULT),
            // "terms_accepted" ya fue validado como obligatorio (AuthController)
            // antes de llegar acá; esto es la evidencia de esa aceptación.
            'terms_accepted_at' => date('Y-m-d H:i:s'),
            'terms_accepted_ip' => $ip,
        ]);

        $this->sendVerificationEmail($userId, $input['name'], $input['email']);

        $user = $this->users->findById($userId);
        $token = JwtService::issue($user->id, $user->roles);

        return ['user' => $user, 'token' => $token];
    }

    private function sendVerificationEmail(int $userId, string $name, string $email): void
    {
        $rawToken = bin2hex(random_bytes(32));
        $ttlHours = (int) Config::get('app.auth.email_verification_ttl_hours', 24);
        $expiresAt = date('Y-m-d H:i:s', time() + $ttlHours * 3600);

        $this->users->setEmailVerificationToken($userId, hash('sha256', $rawToken), $expiresAt);

        $verifyUrl = rtrim((string) Config::get('app.url'), '/') . '/api/auth/verify-email?token=' . $rawToken;
        $content = EmailTemplates::verificationEmail($name, $verifyUrl);

        Mailer::send($email, $content['subject'], $content['html'], 'email_verification', $userId);
    }
}
