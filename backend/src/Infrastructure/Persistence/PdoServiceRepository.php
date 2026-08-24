<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Support\FileStorage;
use App\Domain\Repositories\ServiceRepositoryInterface;
use App\Infrastructure\Config\Config;
use PDO;

final class PdoServiceRepository implements ServiceRepositoryInterface
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private PDO $connection)
    {
    }

    public function paginate(array $filters, bool $includeAllStatuses = false): array
    {
        $conditions = ['s.deleted_at IS NULL'];
        $params = [];

        if (!$includeAllStatuses) {
            $conditions[] = "s.status = 'active'";
        } elseif (!empty($filters['status'])) {
            $conditions[] = 's.status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['category_id'])) {
            $conditions[] = 's.category_id = :category_id';
            $params['category_id'] = (int) $filters['category_id'];
        }

        if (!empty($filters['search'])) {
            [$searchCondition, $searchParams] = $this->buildSearchCondition($filters['search']);
            if ($searchCondition !== null) {
                $conditions[] = $searchCondition;
                $params += $searchParams;
            }
        }

        if (!empty($filters['location'])) {
            $conditions[] = 's.location LIKE :location';
            $params['location'] = '%' . $filters['location'] . '%';
        }

        // No hay filtro por calificación aquí: a diferencia de "products", "services"
        // no tiene un rating_avg cacheado (se agrega cuando exista el módulo de reseñas).

        $where = 'WHERE ' . implode(' AND ', $conditions);

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($filters['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->connection->prepare("SELECT COUNT(*) FROM services s {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $hasSearch = !empty($filters['search']);
        $sortKey = (string) ($filters['sort'] ?? ($hasSearch ? 'relevancia' : 'newest'));
        $orderBy = $this->resolveSort($sortKey, $hasSearch);

        $relevanceSelect = $hasSearch
            ? ', MATCH(s.name, s.description) AGAINST (:search_relevance IN NATURAL LANGUAGE MODE) AS relevance'
            : '';

        $sql = "SELECT s.*, c.name AS category_name,
                    (SELECT url FROM service_images si WHERE si.service_id = s.id
                        ORDER BY si.sort_order ASC LIMIT 1) AS primary_image
                    {$relevanceSelect}
                FROM services s
                LEFT JOIN categories c ON c.id = s.category_id
                {$where}
                ORDER BY {$orderBy}
                LIMIT :limit OFFSET :offset";

        $stmt = $this->connection->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        if ($hasSearch) {
            $stmt->bindValue(':search_relevance', $filters['search']);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['data' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    private function resolveSort(string $sort, bool $hasSearch): string
    {
        return match ($sort) {
            'price_asc' => 's.price ASC',
            'price_desc' => 's.price DESC',
            'name' => 's.name ASC',
            'relevancia' => $hasSearch ? 'relevance DESC, s.created_at DESC' : 's.created_at DESC',
            default => 's.created_at DESC',
        };
    }

    /**
     * Mismo criterio que PdoProductRepository::buildSearchCondition(): cualquier
     * palabra puede aparecer en nombre/descripción, en vez de exigir la frase
     * completa y contigua.
     *
     * @return array{0: ?string, 1: array}
     */
    private function buildSearchCondition(string $term): array
    {
        $words = array_filter(preg_split('/\s+/', trim($term)) ?: []);
        if (empty($words)) {
            return [null, []];
        }

        $groups = [];
        $params = [];

        foreach (array_values($words) as $index => $word) {
            $like = '%' . $word . '%';
            $nameParam = "search_{$index}_name";
            $descParam = "search_{$index}_desc";

            $groups[] = "(s.name LIKE :{$nameParam} OR s.description LIKE :{$descParam})";
            $params[$nameParam] = $like;
            $params[$descParam] = $like;
        }

        return ['(' . implode(' OR ', $groups) . ')', $params];
    }

    public function findBySlug(string $slug, bool $includeAllStatuses = false): ?array
    {
        $sql = 'SELECT s.*, c.name AS category_name, c.slug AS category_slug
                FROM services s
                LEFT JOIN categories c ON c.id = s.category_id
                WHERE s.slug = :slug AND s.deleted_at IS NULL';
        if (!$includeAllStatuses) {
            $sql .= " AND s.status = 'active'";
        }

        $stmt = $this->connection->prepare($sql);
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $row['images'] = $this->imagesOf((int) $row['id']);

        return $row;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->connection->prepare('SELECT * FROM services WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $row['images'] = $this->imagesOf($id);

        return $row;
    }

    private function imagesOf(int $serviceId): array
    {
        $stmt = $this->connection->prepare(
            'SELECT * FROM service_images WHERE service_id = :id ORDER BY sort_order ASC'
        );
        $stmt->execute(['id' => $serviceId]);

        return $stmt->fetchAll();
    }

    public function exists(int $id): bool
    {
        $stmt = $this->connection->prepare('SELECT 1 FROM services WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $id]);

        return (bool) $stmt->fetchColumn();
    }

    public function existsBySlug(string $slug, ?int $excludeId = null): bool
    {
        $sql = 'SELECT 1 FROM services WHERE slug = :slug AND deleted_at IS NULL';
        $params = ['slug' => $slug];

        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }

    public function create(array $data): int
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO services (
                store_id, category_id, professional_user_id, name, name_en, slug, description, description_en, price,
                shipping_cost, duration_minutes, requires_scheduling, is_informational, location, latitude, longitude,
                schedule, availability, cancellation_policy, warranty, facebook_url, status
            ) VALUES (
                :store_id, :category_id, :professional_user_id, :name, :name_en, :slug, :description, :description_en, :price,
                :shipping_cost, :duration_minutes, :requires_scheduling, :is_informational, :location, :latitude, :longitude,
                :schedule, :availability, :cancellation_policy, :warranty, :facebook_url, :status
            )'
        );
        $stmt->execute($this->bindings($data));

        return (int) $this->connection->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->connection->prepare(
            'UPDATE services SET
                store_id = :store_id, category_id = :category_id, professional_user_id = :professional_user_id,
                name = :name, name_en = :name_en, slug = :slug, description = :description, description_en = :description_en, price = :price,
                shipping_cost = :shipping_cost, duration_minutes = :duration_minutes,
                requires_scheduling = :requires_scheduling, is_informational = :is_informational, location = :location,
                latitude = :latitude, longitude = :longitude, schedule = :schedule, availability = :availability,
                cancellation_policy = :cancellation_policy, warranty = :warranty, facebook_url = :facebook_url, status = :status
             WHERE id = :id'
        );
        $stmt->execute($this->bindings($data) + ['id' => $id]);
    }

    private function bindings(array $data): array
    {
        return [
            'store_id' => $data['store_id'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'professional_user_id' => $data['professional_user_id'] ?? null,
            'name' => $data['name'],
            'name_en' => $data['name_en'] ?? null,
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'description_en' => $data['description_en'] ?? null,
            'price' => $data['price'],
            'shipping_cost' => $data['shipping_cost'] ?? null,
            'duration_minutes' => $data['duration_minutes'] ?? null,
            // Default 1 (sí exige fecha/hora) si no viene — mismo default que la
            // columna en BD (migración 055), preserva el comportamiento de siempre.
            'requires_scheduling' => array_key_exists('requires_scheduling', $data) ? (int) $data['requires_scheduling'] : 1,
            'is_informational' => array_key_exists('is_informational', $data) ? (int) $data['is_informational'] : 0,
            'location' => $data['location'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'schedule' => isset($data['schedule']) ? json_encode($data['schedule']) : null,
            'availability' => isset($data['availability']) ? json_encode($data['availability']) : null,
            'cancellation_policy' => $data['cancellation_policy'] ?? null,
            'warranty' => $data['warranty'] ?? null,
            'facebook_url' => $data['facebook_url'] ?? null,
            'status' => $data['status'] ?? 'draft',
        ];
    }

    public function delete(int $id): void
    {
        // Las fotos no quedan huérfanas: se borran sus archivos físicos y sus
        // filas ANTES del soft-delete del servicio (mismo criterio que
        // deleteImage() — sección 44). El servicio en sí solo se marca
        // deleted_at (recuperable en BD si hiciera falta), pero sus imágenes
        // no tendrían ningún lugar donde volver a mostrarse.
        $images = $this->imagesOf($id);
        foreach ($images as $image) {
            FileStorage::delete((string) Config::get('app.base_path') . '/storage/uploads/services', (string) $image['url']);
        }
        $this->connection->prepare('DELETE FROM service_images WHERE service_id = :id')->execute(['id' => $id]);

        $stmt = $this->connection->prepare('UPDATE services SET deleted_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function countImages(int $serviceId): int
    {
        $stmt = $this->connection->prepare('SELECT COUNT(*) FROM service_images WHERE service_id = :id');
        $stmt->execute(['id' => $serviceId]);

        return (int) $stmt->fetchColumn();
    }

    public function addImage(int $serviceId, string $path): int
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO service_images (service_id, url) VALUES (:service_id, :url)'
        );
        $stmt->execute(['service_id' => $serviceId, 'url' => $path]);

        return (int) $this->connection->lastInsertId();
    }

    public function deleteImage(int $imageId): void
    {
        $select = $this->connection->prepare('SELECT url FROM service_images WHERE id = :id');
        $select->execute(['id' => $imageId]);
        $url = $select->fetchColumn();

        $stmt = $this->connection->prepare('DELETE FROM service_images WHERE id = :id');
        $stmt->execute(['id' => $imageId]);

        // Se borra el archivo físico DESPUÉS de confirmar el borrado en BD, y solo
        // si de verdad existía la fila (evita intentar borrar con un path vacío).
        if ($url !== false) {
            FileStorage::delete((string) Config::get('app.base_path') . '/storage/uploads/services', (string) $url);
        }
    }

    public function imageBelongsToService(int $imageId, int $serviceId): bool
    {
        $stmt = $this->connection->prepare(
            'SELECT 1 FROM service_images WHERE id = :id AND service_id = :service_id'
        );
        $stmt->execute(['id' => $imageId, 'service_id' => $serviceId]);

        return (bool) $stmt->fetchColumn();
    }

    public function bookedTimesForDate(int $serviceId, string $date): array
    {
        $stmt = $this->connection->prepare(
            "SELECT oi.scheduled_at FROM order_items oi
                INNER JOIN orders o ON o.id = oi.order_id
             WHERE oi.service_id = :service_id AND DATE(oi.scheduled_at) = :date
                AND o.status != 'CANCELADO'"
        );
        $stmt->execute(['service_id' => $serviceId, 'date' => $date]);

        return array_map(
            static fn (string $scheduledAt) => date('H:i', strtotime($scheduledAt)),
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        );
    }
}
