<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Support\FileStorage;
use App\Domain\Repositories\ProductRepositoryInterface;
use App\Infrastructure\Config\Config;
use PDO;

final class PdoProductRepository implements ProductRepositoryInterface
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private PDO $connection)
    {
    }

    public function paginate(array $filters, bool $includeAllStatuses = false): array
    {
        [$where, $params] = $this->buildWhere($filters, $includeAllStatuses);

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($filters['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->connection->prepare("SELECT COUNT(*) FROM products p {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $hasSearch = !empty($filters['search']);
        $sortKey = (string) ($filters['sort'] ?? ($hasSearch ? 'relevancia' : 'newest'));
        $orderBy = $this->resolveSort($sortKey, $hasSearch);

        // MATCH...AGAINST (sección 14: "búsqueda por relevancia") solo se calcula
        // cuando hay término de búsqueda; el filtrado en sí sigue usando LIKE en
        // buildWhere() para no perder resultados con términos cortos (FULLTEXT en
        // MariaDB ignora palabras de menos de 3 caracteres).
        $relevanceSelect = $hasSearch
            ? ', MATCH(p.name, p.short_description, p.description) AGAINST (:search_relevance IN NATURAL LANGUAGE MODE) AS relevance'
            : '';

        $sql = "SELECT p.*, c.name AS category_name, b.name AS brand_name,
                    (SELECT url FROM product_images pi WHERE pi.product_id = p.id
                        ORDER BY pi.is_primary DESC, pi.sort_order ASC LIMIT 1) AS primary_image
                    {$relevanceSelect}
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN brands b ON b.id = p.brand_id
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

        $rows = array_map([$this, 'withStockStatus'], $stmt->fetchAll());

        return ['data' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * @return array{0: string, 1: array}
     */
    private function buildWhere(array $filters, bool $includeAllStatuses): array
    {
        $conditions = ['p.deleted_at IS NULL'];
        $params = [];

        if (!$includeAllStatuses) {
            $conditions[] = "p.status = 'active'";
        } elseif (!empty($filters['status'])) {
            $conditions[] = 'p.status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['category_id'])) {
            $conditions[] = 'p.category_id = :category_id';
            $params['category_id'] = (int) $filters['category_id'];
        }

        if (!empty($filters['brand_id'])) {
            $conditions[] = 'p.brand_id = :brand_id';
            $params['brand_id'] = (int) $filters['brand_id'];
        }

        if (isset($filters['min_price']) && $filters['min_price'] !== '') {
            $conditions[] = 'p.price >= :min_price';
            $params['min_price'] = (float) $filters['min_price'];
        }

        if (isset($filters['max_price']) && $filters['max_price'] !== '') {
            $conditions[] = 'p.price <= :max_price';
            $params['max_price'] = (float) $filters['max_price'];
        }

        if (!empty($filters['search'])) {
            [$searchCondition, $searchParams] = $this->buildSearchCondition('p', $filters['search']);
            if ($searchCondition !== null) {
                $conditions[] = $searchCondition;
                $params += $searchParams;
            }
        }

        if (!empty($filters['store_id'])) {
            $conditions[] = 'p.store_id = :store_id';
            $params['store_id'] = (int) $filters['store_id'];
        }

        if (isset($filters['rating_min']) && $filters['rating_min'] !== '') {
            $conditions[] = 'p.rating_avg >= :rating_min';
            $params['rating_min'] = (float) $filters['rating_min'];
        }

        // "Ofertas" (sección de Home tipo marketplace): solo productos con
        // descuento real ya cargado, nunca una cuenta regresiva ni datos inventados.
        if (!empty($filters['on_sale'])) {
            $conditions[] = 'p.discount_percentage > 0';
        }

        // Disponibilidad (sección 14), sobre las mismas reglas de withStockStatus().
        if (!empty($filters['availability'])) {
            $conditions[] = match ($filters['availability']) {
                'agotado' => 'p.stock <= 0',
                'ultimas_unidades' => 'p.stock > 0 AND p.stock <= p.min_stock',
                'disponible' => 'p.stock > p.min_stock',
                default => '1 = 1',
            };
        }

        return ['WHERE ' . implode(' AND ', $conditions), $params];
    }

    /**
     * Divide el término en palabras y exige que CUALQUIERA aparezca en
     * nombre/SKU/descripción corta (en vez de exigir la frase completa y
     * contigua): "casco deportivo" así encuentra "Casco Integral Deportivo".
     * El orden real por relevancia lo da MATCH...AGAINST en el SELECT
     * (ver paginate()); esto solo decide qué filas entran al resultado.
     * Cada palabra usa nombres de parámetro únicos: con
     * PDO::ATTR_EMULATE_PREPARES=false (Connection.php) MySQL usa prepared
     * statements nativos, que no permiten repetir el mismo parámetro nombrado
     * más de una vez en la misma consulta ("Invalid parameter number").
     *
     * @return array{0: ?string, 1: array}
     */
    private function buildSearchCondition(string $alias, string $term): array
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
            $skuParam = "search_{$index}_sku";
            $shortParam = "search_{$index}_short";

            $groups[] = "({$alias}.name LIKE :{$nameParam} OR {$alias}.sku LIKE :{$skuParam} OR {$alias}.short_description LIKE :{$shortParam})";
            $params[$nameParam] = $like;
            $params[$skuParam] = $like;
            $params[$shortParam] = $like;
        }

        return ['(' . implode(' OR ', $groups) . ')', $params];
    }

    private function resolveSort(string $sort, bool $hasSearch): string
    {
        return match ($sort) {
            'price_asc' => 'p.price ASC',
            'price_desc' => 'p.price DESC',
            'name' => 'p.name ASC',
            'rating' => 'p.rating_avg DESC',
            // "Más vendidos" (sección 14): no hay datos de ventas hasta que exista el
            // módulo de pedidos (Fase 6). Se acepta el parámetro para no romper al
            // frontend y cae a los más recientes mientras tanto.
            'best_selling' => 'p.created_at DESC',
            'relevancia' => $hasSearch ? 'relevance DESC, p.created_at DESC' : 'p.created_at DESC',
            default => 'p.created_at DESC',
        };
    }

    public function findBySlug(string $slug, bool $includeAllStatuses = false): ?array
    {
        $sql = "SELECT p.*, c.name AS category_name, c.slug AS category_slug, b.name AS brand_name, s.name AS store_name
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN brands b ON b.id = p.brand_id
                LEFT JOIN stores s ON s.id = p.store_id
                WHERE p.slug = :slug AND p.deleted_at IS NULL";
        if (!$includeAllStatuses) {
            $sql .= " AND p.status = 'active'";
        }

        $stmt = $this->connection->prepare($sql);
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();

        return $row ? $this->withDetails($row) : null;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->connection->prepare('SELECT * FROM products WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->withDetails($row) : null;
    }

    private function withDetails(array $row): array
    {
        $row = $this->withStockStatus($row);
        $row['images'] = $this->imagesOf((int) $row['id']);
        $row['variants'] = $this->variantsOf((int) $row['id']);
        $row['attributes'] = $this->attributesOf((int) $row['id']);

        return $row;
    }

    private function withStockStatus(array $row): array
    {
        $stock = (int) $row['stock'];
        $minStock = (int) $row['min_stock'];

        $row['stock_status'] = match (true) {
            $stock <= 0 => 'agotado',
            $stock <= $minStock => 'ultimas_unidades',
            default => 'disponible',
        };

        return $row;
    }

    private function imagesOf(int $productId): array
    {
        $stmt = $this->connection->prepare(
            'SELECT * FROM product_images WHERE product_id = :id ORDER BY is_primary DESC, sort_order ASC'
        );
        $stmt->execute(['id' => $productId]);

        return $stmt->fetchAll();
    }

    private function variantsOf(int $productId): array
    {
        $stmt = $this->connection->prepare('SELECT * FROM product_variants WHERE product_id = :id ORDER BY id ASC');
        $stmt->execute(['id' => $productId]);

        return $stmt->fetchAll();
    }

    private function attributesOf(int $productId): array
    {
        $stmt = $this->connection->prepare('SELECT * FROM product_attributes WHERE product_id = :id ORDER BY id ASC');
        $stmt->execute(['id' => $productId]);

        return $stmt->fetchAll();
    }

    public function exists(int $id): bool
    {
        $stmt = $this->connection->prepare('SELECT 1 FROM products WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $id]);

        return (bool) $stmt->fetchColumn();
    }

    public function existsBySlug(string $slug, ?int $excludeId = null): bool
    {
        $sql = 'SELECT 1 FROM products WHERE slug = :slug AND deleted_at IS NULL';
        $params = ['slug' => $slug];

        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }

    public function existsBySku(string $sku, ?int $excludeId = null): bool
    {
        $sql = 'SELECT 1 FROM products WHERE sku = :sku AND deleted_at IS NULL';
        $params = ['sku' => $sku];

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
            'INSERT INTO products (
                store_id, category_id, brand_id, supplier_id, name, name_en, slug, description, short_description, short_description_en,
                sku, internal_code, price, shipping_cost, previous_price, discount_percentage, tax_rate,
                stock, min_stock, weight, dimensions, warranty, additional_info, status
            ) VALUES (
                :store_id, :category_id, :brand_id, :supplier_id, :name, :name_en, :slug, :description, :short_description, :short_description_en,
                :sku, :internal_code, :price, :shipping_cost, :previous_price, :discount_percentage, :tax_rate,
                :stock, :min_stock, :weight, :dimensions, :warranty, :additional_info, :status
            )'
        );
        $stmt->execute($this->bindings($data));

        return (int) $this->connection->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->connection->prepare(
            'UPDATE products SET
                store_id = :store_id, category_id = :category_id, brand_id = :brand_id, supplier_id = :supplier_id,
                name = :name, name_en = :name_en, slug = :slug, description = :description,
                short_description = :short_description, short_description_en = :short_description_en,
                sku = :sku, internal_code = :internal_code, price = :price, shipping_cost = :shipping_cost,
                previous_price = :previous_price,
                discount_percentage = :discount_percentage, tax_rate = :tax_rate, stock = :stock,
                min_stock = :min_stock, weight = :weight, dimensions = :dimensions, warranty = :warranty,
                additional_info = :additional_info, status = :status
             WHERE id = :id'
        );
        $stmt->execute($this->bindings($data) + ['id' => $id]);
    }

    private function bindings(array $data): array
    {
        return [
            'store_id' => $data['store_id'] ?? null,
            'category_id' => $data['category_id'],
            'brand_id' => $data['brand_id'] ?? null,
            'supplier_id' => $data['supplier_id'] ?? null,
            'name' => $data['name'],
            'name_en' => $data['name_en'] ?? null,
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'short_description' => $data['short_description'] ?? null,
            'short_description_en' => $data['short_description_en'] ?? null,
            'sku' => $data['sku'],
            'internal_code' => $data['internal_code'] ?? null,
            'price' => $data['price'],
            'shipping_cost' => $data['shipping_cost'] ?? null,
            'previous_price' => $data['previous_price'] ?? null,
            'discount_percentage' => $data['discount_percentage'] ?? 0,
            'tax_rate' => $data['tax_rate'] ?? 0,
            'stock' => $data['stock'] ?? 0,
            'min_stock' => $data['min_stock'] ?? 0,
            'weight' => $data['weight'] ?? null,
            'dimensions' => $data['dimensions'] ?? null,
            'warranty' => $data['warranty'] ?? null,
            'additional_info' => $data['additional_info'] ?? null,
            'status' => $data['status'] ?? 'draft',
        ];
    }

    public function delete(int $id): void
    {
        // Mismo criterio que PdoServiceRepository::delete(): las fotos se
        // borran de verdad (archivo + fila) antes del soft-delete del
        // producto, para no dejarlas huérfanas en storage/uploads/products.
        $images = $this->imagesOf($id);
        foreach ($images as $image) {
            FileStorage::delete((string) Config::get('app.base_path') . '/storage/uploads/products', (string) $image['url']);
        }
        $this->connection->prepare('DELETE FROM product_images WHERE product_id = :id')->execute(['id' => $id]);

        $stmt = $this->connection->prepare('UPDATE products SET deleted_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function relatedProducts(int $productId, int $categoryId, int $limit = 8): array
    {
        $stmt = $this->connection->prepare(
            "SELECT p.*, (SELECT url FROM product_images pi WHERE pi.product_id = p.id
                ORDER BY pi.is_primary DESC, pi.sort_order ASC LIMIT 1) AS primary_image
             FROM products p
             WHERE p.category_id = :category_id AND p.id != :product_id
                AND p.status = 'active' AND p.deleted_at IS NULL
             ORDER BY p.created_at DESC
             LIMIT :limit"
        );
        $stmt->bindValue('category_id', $categoryId, PDO::PARAM_INT);
        $stmt->bindValue('product_id', $productId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'withStockStatus'], $stmt->fetchAll());
    }

    public function countImages(int $productId): int
    {
        $stmt = $this->connection->prepare('SELECT COUNT(*) FROM product_images WHERE product_id = :id');
        $stmt->execute(['id' => $productId]);

        return (int) $stmt->fetchColumn();
    }

    public function addImage(int $productId, string $path, bool $isPrimary): int
    {
        $isFirst = $this->countImages($productId) === 0;

        $makePrimary = $isPrimary || $isFirst;

        if ($makePrimary) {
            $unset = $this->connection->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = :id');
            $unset->execute(['id' => $productId]);
        }

        $stmt = $this->connection->prepare(
            'INSERT INTO product_images (product_id, url, is_primary) VALUES (:product_id, :url, :is_primary)'
        );
        $stmt->execute(['product_id' => $productId, 'url' => $path, 'is_primary' => $makePrimary ? 1 : 0]);

        return (int) $this->connection->lastInsertId();
    }

    public function deleteImage(int $imageId): void
    {
        $stmt = $this->connection->prepare('SELECT product_id, is_primary, url FROM product_images WHERE id = :id');
        $stmt->execute(['id' => $imageId]);
        $image = $stmt->fetch();

        $delete = $this->connection->prepare('DELETE FROM product_images WHERE id = :id');
        $delete->execute(['id' => $imageId]);

        // Se borra el archivo físico DESPUÉS de confirmar el borrado en BD (mismo
        // criterio que PdoServiceRepository::deleteImage) — evita huérfanos en disco.
        if ($image) {
            FileStorage::delete((string) Config::get('app.base_path') . '/storage/uploads/products', (string) $image['url']);
        }

        if ($image && (int) $image['is_primary'] === 1) {
            // Si se borró la imagen principal, la siguiente por orden pasa a serlo.
            $next = $this->connection->prepare(
                'SELECT id FROM product_images WHERE product_id = :product_id ORDER BY sort_order ASC LIMIT 1'
            );
            $next->execute(['product_id' => $image['product_id']]);
            $nextId = $next->fetchColumn();

            if ($nextId !== false) {
                $setPrimary = $this->connection->prepare('UPDATE product_images SET is_primary = 1 WHERE id = :id');
                $setPrimary->execute(['id' => $nextId]);
            }
        }
    }

    public function imageBelongsToProduct(int $imageId, int $productId): bool
    {
        $stmt = $this->connection->prepare(
            'SELECT 1 FROM product_images WHERE id = :id AND product_id = :product_id'
        );
        $stmt->execute(['id' => $imageId, 'product_id' => $productId]);

        return (bool) $stmt->fetchColumn();
    }

    public function setPrimaryImage(int $productId, int $imageId): void
    {
        $this->connection->beginTransaction();
        try {
            $unset = $this->connection->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = :id');
            $unset->execute(['id' => $productId]);

            $set = $this->connection->prepare(
                'UPDATE product_images SET is_primary = 1 WHERE id = :id AND product_id = :product_id'
            );
            $set->execute(['id' => $imageId, 'product_id' => $productId]);

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    public function replaceVariants(int $productId, array $variants): void
    {
        $this->connection->beginTransaction();
        try {
            $delete = $this->connection->prepare('DELETE FROM product_variants WHERE product_id = :id');
            $delete->execute(['id' => $productId]);

            $insert = $this->connection->prepare(
                'INSERT INTO product_variants (product_id, name, sku, price_modifier, stock, attributes)
                 VALUES (:product_id, :name, :sku, :price_modifier, :stock, :attributes)'
            );

            foreach ($variants as $variant) {
                $insert->execute([
                    'product_id' => $productId,
                    'name' => $variant['name'],
                    'sku' => $variant['sku'] ?? null,
                    'price_modifier' => $variant['price_modifier'] ?? 0,
                    'stock' => $variant['stock'] ?? 0,
                    'attributes' => isset($variant['attributes']) ? json_encode($variant['attributes']) : null,
                ]);
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    public function replaceAttributes(int $productId, array $attributes): void
    {
        $this->connection->beginTransaction();
        try {
            $delete = $this->connection->prepare('DELETE FROM product_attributes WHERE product_id = :id');
            $delete->execute(['id' => $productId]);

            $insert = $this->connection->prepare(
                'INSERT INTO product_attributes (product_id, name, value) VALUES (:product_id, :name, :value)'
            );

            foreach ($attributes as $attribute) {
                $insert->execute([
                    'product_id' => $productId,
                    'name' => $attribute['name'],
                    'value' => $attribute['value'],
                ]);
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    public function initializeInventory(int $productId, int $stock, int $minStock): void
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO inventory (product_id, stock_current, stock_reserved, stock_minimum)
             VALUES (:product_id, :stock, 0, :min_stock)
             ON DUPLICATE KEY UPDATE stock_current = VALUES(stock_current), stock_minimum = VALUES(stock_minimum)'
        );
        $stmt->execute(['product_id' => $productId, 'stock' => $stock, 'min_stock' => $minStock]);
    }
}
