<?php

declare(strict_types=1);

namespace App\Domain\Repositories;

use App\Domain\Entities\User;

/**
 * Puerto (hexagonal) para la persistencia de usuarios. La implementación
 * concreta (PDO) vive en Infrastructure/Persistence/PdoUserRepository.
 */
interface UserRepositoryInterface
{
    public function findById(int $id): ?User;

    public function findByEmail(string $email): ?User;

    /** "Continuar con Google": vincula la cuenta (por correo) a su ID de Google. */
    public function linkGoogleId(int $userId, string $googleId): void;

    public function emailExists(string $email): bool;

    /**
     * Crea el usuario y le asigna el rol "cliente" por defecto. Devuelve el id creado.
     */
    public function create(array $data): int;

    public function updateProfile(int $userId, array $data): void;

    public function updatePassword(int $userId, string $passwordHash): void;

    public function updateAvatar(int $userId, string $avatarPath): void;

    public function markEmailVerified(int $userId): void;

    public function setEmailVerificationToken(int $userId, string $tokenHash, string $expiresAt): void;

    public function findByEmailVerificationToken(string $tokenHash): ?User;

    public function registerLoginSuccess(int $userId): void;

    public function registerLoginFailure(int $userId, int $maxAttempts, int $lockoutMinutes): void;

    /**
     * @return string[] Nombres de los permisos que tiene el usuario a través de sus roles.
     */
    public function permissionsForUser(int $userId): array;

    /**
     * Listado de clientes para el panel admin (sección 28: "dónde se ven los
     * clientes"), con roles, cantidad de pedidos y total gastado. Por
     * defecto excluye cuentas de staff (administrador/superadministrador) —
     * ver filtro "include_staff".
     *
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function paginateForAdmin(array $filters): array;

    /**
     * Reemplaza el/los rol(es) de un usuario por uno solo (sección 28: gestión
     * de roles) — mismo modelo de "un rol por cuenta" que ya usa el registro
     * (sección 7), aunque la tabla user_roles admita varios por diseño.
     */
    public function setRole(int $userId, string $roleName): void;
}
