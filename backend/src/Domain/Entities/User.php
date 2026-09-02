<?php

declare(strict_types=1);

namespace App\Domain\Entities;

/**
 * Entidad de dominio del usuario. Es un objeto de datos puro (sin PDO ni
 * lógica de acceso a datos): la persistencia vive en Infrastructure/Persistence.
 */
final class User
{
    // Nota: se usan propiedades públicas promovidas (sin "readonly", que requiere
    // PHP 8.1+) para mantener compatibilidad con PHP 8.0. La entidad se sigue
    // tratando como inmutable por convención: no se modifica tras construirse.
    public function __construct(
        public int $id,
        public string $name,
        public string $lastName,
        public string $email,
        public ?string $phone,
        public ?string $avatar,
        public string $passwordHash,
        public string $status,
        public ?string $emailVerifiedAt,
        public int $failedLoginAttempts,
        public ?string $lockedUntil,
        // NULL en cuentas de antes de esta columna (sección seguridad) — sin
        // restricción para ellas. AuthMiddleware la usa para invalidar
        // cualquier JWT emitido antes del último cambio de contraseña.
        public ?string $passwordChangedAt = null,
        /** @var string[] Nombres de los roles asignados. */
        public array $roles = []
    ) {
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerifiedAt !== null;
    }

    public function isLocked(): bool
    {
        return $this->lockedUntil !== null && strtotime($this->lockedUntil) > time();
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /**
     * Representación segura para exponer vía API (nunca incluye passwordHash).
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'last_name' => $this->lastName,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar' => $this->avatar,
            'status' => $this->status,
            'email_verified' => $this->isEmailVerified(),
            'roles' => $this->roles,
        ];
    }
}
