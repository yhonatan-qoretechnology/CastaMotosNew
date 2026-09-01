<?php

declare(strict_types=1);

namespace App\Presentation\Controllers;

use App\Application\Support\AccessChecker;
use App\Application\UseCases\Catalog\CreateProductUseCase;
use App\Application\UseCases\Catalog\SyncProductAttributesUseCase;
use App\Application\UseCases\Catalog\SyncProductVariantsUseCase;
use App\Application\UseCases\Catalog\UpdateProductUseCase;
use App\Application\UseCases\Catalog\UploadProductImageUseCase;
use App\Application\Validation\Validator;
use App\Domain\Entities\User;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Response;
use App\Infrastructure\Persistence\PdoBrandRepository;
use App\Infrastructure\Persistence\PdoCategoryRepository;
use App\Infrastructure\Persistence\PdoFavoriteRepository;
use App\Infrastructure\Persistence\PdoNotificationRepository;
use App\Infrastructure\Persistence\PdoProductRepository;
use App\Infrastructure\Persistence\PdoSupplierRepository;
use App\Infrastructure\Persistence\PdoUserRepository;

final class ProductController
{
    private PdoProductRepository $products;
    private PdoCategoryRepository $categories;
    private PdoBrandRepository $brands;
    private PdoSupplierRepository $suppliers;
    private PdoUserRepository $users;
    private PdoFavoriteRepository $favorites;
    private PdoNotificationRepository $notifications;

    private const RULES = [
        'name' => 'required|max:200',
        // Traducción al inglés (selector de idioma, opcional): si se deja vacío,
        // el sitio en inglés muestra el texto en español como fallback — ver
        // helpers.localized() en el frontend.
        'name_en' => 'max:200',
        'category_id' => 'required|integer',
        'brand_id' => 'integer',
        'supplier_id' => 'integer',
        'short_description' => 'max:500',
        'short_description_en' => 'max:500',
        // Opcional (sección 10): si se deja vacío al crear, se genera un SKU
        // único automáticamente (ver CreateProductUseCase/SkuGenerator).
        'sku' => 'max:100',
        'internal_code' => 'max:100',
        'price' => 'required|numeric|gte:0',
        'shipping_cost' => 'numeric|gte:0',
        'previous_price' => 'numeric|gte:0',
        'discount_percentage' => 'numeric|gte:0',
        'tax_rate' => 'numeric|gte:0',
        'stock' => 'required|integer|gte:0',
        'min_stock' => 'integer|gte:0',
        'weight' => 'numeric|gte:0',
        'dimensions' => 'max:100',
        'warranty' => 'max:150',
        // Mismo campo opcional que ya existe en servicios (ver ServiceController) —
        // productos que requieren un turno además de la compra (ej. instalación).
        'requires_scheduling' => 'boolean',
        // Horario de atención propio (opcional, mismo patrón que shipping_cost) —
        // vacío = usa el horario general del sitio (site_settings).
        'schedule_hours_start' => 'max:5',
        'schedule_hours_end' => 'max:5',
        'status' => 'in:draft,active,inactive,out_of_stock',
    ];

    public function __construct()
    {
        $connection = Connection::get();
        $this->products = new PdoProductRepository($connection);
        $this->categories = new PdoCategoryRepository($connection);
        $this->brands = new PdoBrandRepository($connection);
        $this->suppliers = new PdoSupplierRepository($connection);
        $this->users = new PdoUserRepository($connection);
        $this->favorites = new PdoFavoriteRepository($connection);
        $this->notifications = new PdoNotificationRepository($connection);
    }

    public function index(Request $request): void
    {
        $filters = $request->query();
        $result = $this->products->paginate($filters, $this->canManage($request));

        Response::success($result);
    }

    public function show(Request $request, string $slug): void
    {
        $product = $this->products->findBySlug($slug, $this->canManage($request));
        if ($product === null) {
            throw new NotFoundException('Producto no encontrado.');
        }

        $product['related'] = $this->products->relatedProducts((int) $product['id'], (int) $product['category_id']);

        // Compartir (sección 17): URL amigable y estable para armar enlaces de
        // WhatsApp/Facebook/X/Telegram/Web Share API desde el frontend.
        $product['canonical_url'] = rtrim((string) Config::get('app.url'), '/') . '/producto/' . $product['slug'];

        /** @var User|null $user */
        $user = $request->attribute('auth_user');
        $product['is_favorite'] = $user !== null
            ? $this->favorites->isFavorite($user->id, 'product', (int) $product['id'])
            : false;

        Response::success($product);
    }

    /** Horas ya ocupadas de este producto en una fecha (mismo mecanismo que servicios) — público. */
    public function bookedTimes(Request $request, string $id): void
    {
        $data = Validator::make($request->query(), ['date' => 'required'])->validate();

        Response::success($this->products->bookedTimesForDate((int) $id, $data['date']));
    }

    public function store(Request $request): void
    {
        $data = Validator::make($request->input(), self::RULES)->validate();

        $id = (new CreateProductUseCase($this->products, $this->categories, $this->brands, $this->suppliers))->handle($data);
        $product = $this->products->find($id);

        // Notifica a TODOS los usuarios (campanita) solo si el producto ya nace
        // publicado — uno cargado en "draft" no debería avisarle a nadie todavía.
        if (($product['status'] ?? null) === 'active') {
            $this->notifications->notifyAllUsers(
                'new_product',
                'Nuevo producto',
                $product['name'] . ' ya está disponible en CASTAMOTO.',
                ['product_id' => $id, 'slug' => $product['slug']]
            );
        }

        Response::success($product, 'Producto creado correctamente.', 201);
    }

    public function update(Request $request, string $id): void
    {
        $data = Validator::make($request->input(), self::RULES)->validate();

        $before = $this->products->find((int) $id);
        (new UpdateProductUseCase($this->products, $this->categories, $this->brands, $this->suppliers))->handle((int) $id, $data);
        $after = $this->products->find((int) $id);

        // Promoción (campanita): recién ENTRÓ en descuento (antes 0/nulo, ahora > 0).
        // No se repite en cada edición del producto una vez que ya está en oferta.
        $hadDiscount = (float) ($before['discount_percentage'] ?? 0) > 0;
        $hasDiscount = (float) ($after['discount_percentage'] ?? 0) > 0;
        if (!$hadDiscount && $hasDiscount && $after['status'] === 'active') {
            $this->notifications->notifyAllUsers(
                'promotion',
                '¡Nueva oferta!',
                $after['name'] . ' ahora tiene ' . (int) $after['discount_percentage'] . '% de descuento.',
                ['product_id' => (int) $id, 'slug' => $after['slug']]
            );
        }

        Response::success($after, 'Producto actualizado correctamente.');
    }

    public function destroy(Request $request, string $id): void
    {
        if (!$this->products->exists((int) $id)) {
            throw new NotFoundException('Producto no encontrado.');
        }

        $this->products->delete((int) $id);

        Response::success(null, 'Producto eliminado correctamente.');
    }

    public function uploadImage(Request $request, string $id): void
    {
        $file = $request->file('image');
        if ($file === null) {
            throw new ValidationException('No fue posible subir la imagen.', [
                'image' => ['Debes adjuntar un archivo con el campo "image".'],
            ]);
        }

        $isPrimary = (bool) $request->input('is_primary', false);
        $image = (new UploadProductImageUseCase($this->products))->handle((int) $id, $file, $isPrimary);

        Response::success($image, 'Imagen agregada correctamente.', 201);
    }

    public function deleteImage(Request $request, string $id, string $imageId): void
    {
        if (!$this->products->imageBelongsToProduct((int) $imageId, (int) $id)) {
            throw new NotFoundException('Imagen no encontrada.');
        }

        $this->products->deleteImage((int) $imageId);

        Response::success(null, 'Imagen eliminada correctamente.');
    }

    public function setPrimaryImage(Request $request, string $id, string $imageId): void
    {
        if (!$this->products->imageBelongsToProduct((int) $imageId, (int) $id)) {
            throw new NotFoundException('Imagen no encontrada.');
        }

        $this->products->setPrimaryImage((int) $id, (int) $imageId);

        Response::success(null, 'Imagen marcada como principal.');
    }

    public function syncVariants(Request $request, string $id): void
    {
        $variants = $request->input('variants', []);

        (new SyncProductVariantsUseCase($this->products))->handle((int) $id, is_array($variants) ? $variants : []);

        Response::success($this->products->find((int) $id), 'Variantes actualizadas correctamente.');
    }

    public function syncAttributes(Request $request, string $id): void
    {
        $attributes = $request->input('attributes', []);

        (new SyncProductAttributesUseCase($this->products))->handle((int) $id, is_array($attributes) ? $attributes : []);

        Response::success($this->products->find((int) $id), 'Atributos actualizados correctamente.');
    }

    private function canManage(Request $request): bool
    {
        /** @var User|null $user */
        $user = $request->attribute('auth_user');

        return AccessChecker::can($user, 'manage-products', $this->users);
    }
}
