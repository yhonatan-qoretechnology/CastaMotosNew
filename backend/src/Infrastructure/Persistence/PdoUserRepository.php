<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\User;
use App\Domain\Repositories\UserRepositoryInterface;
use PDO;

/**
 * Adaptador PDO del puerto UserRepositoryInterface. Toda sentencia usa
 * parámetros preparados (sección 6: nunca construir SQL con datos del usuario).
 */
final class PdoUserRepository implements UserRepositoryInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function findById(int $id): ?User
    {
        $stmt = $this->connection->prepare('SELECT * FROM users WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function findByEmail(string $email): ?User
    {
        $stmt = $this->connection->prepare('SELECT * FROM users WHERE email = :email AND deleted_at IS NULL');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function emailExists(string $email): bool
    {
        $stmt = $this->connection->prepare('SELECT 1 FROM users WHERE email = :email AND deleted_at IS NULL');
        $stmt->execute(['email' => $email]);

        return (bool) $stmt->fetchColumn();
    }

    public function create(array $data): int
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO users (name, last_name, email, phone, password, status, terms_accepted_at, terms_accepted_ip)
             VALUES (:name, :last_name, :email, :phone, :password, :status, :terms_accepted_at, :terms_accepted_ip)'
        );
        $stmt->execute([
            'name' => $data['name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            'status' => 'active',
            // Evidencia de que aceptó términos y condiciones al registrarse
            // (sección registro): fecha/hora + IP, no solo un booleano.
            'terms_accepted_at' => $data['terms_accepted_at'] ?? null,
            'terms_accepted_ip' => $data['terms_accepted_ip'] ?? null,
        ]);

        $userId = (int) $this->connection->lastInsertId();

        // Todo usuario nuevo entra con el rol "cliente" por defecto (sección 7).
        $roleStmt = $this->connection->prepare(
            'INSERT INTO user_roles (user_id, role_id) SELECT :user_id, id FROM roles WHERE name = :role'
        );
        $roleStmt->execute(['user_id' => $userId, 'role' => 'cliente']);

        return $userId;
    }

    public function updateProfile(int $userId, array $data): void
    {
        $stmt = $this->connection->prepare(
            'UPDATE users SET name = :name, last_name = :last_name, phone = :phone WHERE id = :id'
        );
        $stmt->execute([
            'name' => $data['name'],
            'last_name' => $data['last_name'],
            'phone' => $data['phone'] ?? null,
            'id' => $userId,
        ]);
    }

    public function updatePassword(int $userId, string $passwordHash): void
    {
        // Cambiar la contraseña también limpia el estado de bloqueo por fuerza bruta.
        $stmt = $this->connection->prepare(
            'UPDATE users
             SET password = :password, failed_login_attempts = 0, locked_until = NULL
             WHERE id = :id'
        );
        $stmt->execute(['password' => $passwordHash, 'id' => $userId]);
    }

    public function updateAvatar(int $userId, string $avatarPath): void
    {
        $stmt = $this->connection->prepare('UPDATE users SET avatar = :avatar WHERE id = :id');
        $stmt->execute(['avatar' => $avatarPath, 'id' => $userId]);
    }

    public function markEmailVerified(int $userId): void
    {
        $stmt = $this->connection->prepare(
            'UPDATE users
             SET email_verified_at = NOW(), email_verification_token = NULL, email_verification_expires_at = NULL
             WHERE id = :id'
        );
        $stmt->execute(['id' => $userId]);
    }

    public function setEmailVerificationToken(int $userId, string $tokenHash, string $expiresAt): void
    {
        $stmt = $this->connection->prepare(
            'UPDATE users SET email_verification_token = :token, email_verification_expires_at = :expires WHERE id = :id'
        );
        $stmt->execute(['token' => $tokenHash, 'expires' => $expiresAt, 'id' => $userId]);
    }

    public function findByEmailVerificationToken(string $tokenHash): ?User
    {
        $stmt = $this->connection->prepare(
            'SELECT * FROM users
             WHERE email_verification_token = :token AND email_verification_expires_at > NOW()
             AND deleted_at IS NULL'
        );
        $stmt->execute(['token' => $tokenHash]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function registerLoginSuccess(int $userId): void
    {
        $stmt = $this->connection->prepare(
            'UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $userId]);
    }

    public function registerLoginFailure(int $userId, int $maxAttempts, int $lockoutMinutes): void
    {
        // Se calcula todo en una sola sentencia atómica para evitar condiciones de carrera
        // entre lecturas y escrituras concurrentes del contador de intentos.
        $stmt = $this->connection->prepare(
            'UPDATE users
             SET failed_login_attempts = failed_login_attempts + 1,
                 locked_until = CASE
                     WHEN failed_login_attempts + 1 >= :max THEN DATE_ADD(NOW(), INTERVAL :lockout MINUTE)
                     ELSE locked_until
                 END
             WHERE id = :id'
        );
        $stmt->execute(['max' => $maxAttempts, 'lockout' => $lockoutMinutes, 'id' => $userId]);
    }

    public function permissionsForUser(int $userId): array
    {
        $stmt = $this->connection->prepare(
            'SELECT DISTINCT p.name
             FROM permissions p
             INNER JOIN role_permission rp ON rp.permission_id = p.id
             INNER JOIN user_roles ur ON ur.role_id = rp.role_id
             WHERE ur.user_id = :id'
        );
        $stmt->execute(['id' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function paginateForAdmin(array $filters): array
    {
        $conditions = ['u.deleted_at IS NULL'];
        $params = [];

        if (!empty($filters['search'])) {
            $conditions[] = '(u.name LIKE :search_name OR u.last_name LIKE :search_last OR u.email LIKE :search_email)';
            $term = '%' . $filters['search'] . '%';
            $params['search_name'] = $term;
            $params['search_last'] = $term;
            $params['search_email'] = $term;
        }

        // Por defecto solo clientes (sección 28): no tiene sentido mezclar
        // cuentas de staff en un listado pensado para ver a los compradores.
        if (empty($filters['include_staff'])) {
            $conditions[] = "NOT EXISTS (
                SELECT 1 FROM user_roles ur2 INNER JOIN roles r2 ON r2.id = ur2.role_id
                WHERE ur2.user_id = u.id AND r2.name IN ('administrador', 'superadministrador')
            )";
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 30)));
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->connection->prepare("SELECT COUNT(*) FROM users u {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT u.id, u.name, u.last_name, u.email, u.phone, u.created_at, u.email_verified_at,
                    GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ',') AS roles,
                    COUNT(DISTINCT o.id) AS orders_count,
                    COALESCE(SUM(CASE WHEN o.status NOT IN ('CANCELADO', 'DEVUELTO') THEN o.total ELSE 0 END), 0) AS total_spent
                FROM users u
                LEFT JOIN user_roles ur ON ur.user_id = u.id
                LEFT JOIN roles r ON r.id = ur.role_id
                LEFT JOIN orders o ON o.user_id = u.id AND o.deleted_at IS NULL
                {$where}
                GROUP BY u.id
                ORDER BY u.created_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->connection->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $customers = array_map(static function (array $row): array {
            $row['roles'] = $row['roles'] !== null ? explode(',', $row['roles']) : [];
            $row['orders_count'] = (int) $row['orders_count'];
            $row['total_spent'] = (float) $row['total_spent'];

            return $row;
        }, $stmt->fetchAll());

        return ['data' => $customers, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public function setRole(int $userId, string $roleName): void
    {
        $this->connection->beginTransaction();
        try {
            $this->connection->prepare('DELETE FROM user_roles WHERE user_id = :user_id')->execute(['user_id' => $userId]);

            $stmt = $this->connection->prepare(
                'INSERT INTO user_roles (user_id, role_id) SELECT :user_id, id FROM roles WHERE name = :role'
            );
            $stmt->execute(['user_id' => $userId, 'role' => $roleName]);

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    private function rolesForUser(int $userId): array
    {
        $stmt = $this->connection->prepare(
            'SELECT r.name FROM roles r INNER JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = :id'
        );
        $stmt->execute(['id' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function hydrate(array $row): User
    {
        return new User(
            id: (int) $row['id'],
            name: $row['name'],
            lastName: $row['last_name'],
            email: $row['email'],
            phone: $row['phone'],
            avatar: $row['avatar'] ?? null,
            passwordHash: $row['password'],
            status: $row['status'],
            emailVerifiedAt: $row['email_verified_at'],
            failedLoginAttempts: (int) $row['failed_login_attempts'],
            lockedUntil: $row['locked_until'],
            roles: $this->rolesForUser((int) $row['id']),
        );
    }
}
