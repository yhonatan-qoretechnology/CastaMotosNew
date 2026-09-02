<?php

declare(strict_types=1);

namespace App\Presentation\Controllers;

use App\Application\Support\CartPricingCalculator;
use App\Application\Support\CouponValidator;
use App\Application\UseCases\Cart\AddCartItemUseCase;
use App\Application\UseCases\Cart\RemoveCartItemUseCase;
use App\Application\UseCases\Cart\UpdateCartItemUseCase;
use App\Application\Validation\Validator;
use App\Domain\Entities\User;
use App\Exceptions\ValidationException;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Response;
use App\Infrastructure\Persistence\PdoCartRepository;
use App\Infrastructure\Persistence\PdoCouponRepository;
use App\Infrastructure\Persistence\PdoProductRepository;
use App\Infrastructure\Persistence\PdoServiceRepository;

final class CartController
{
    private PdoCartRepository $carts;
    private PdoProductRepository $products;
    private PdoServiceRepository $services;
    private PdoCouponRepository $coupons;

    public function __construct()
    {
        $connection = Connection::get();
        $this->carts = new PdoCartRepository($connection);
        $this->products = new PdoProductRepository($connection);
        $this->services = new PdoServiceRepository($connection);
        $this->coupons = new PdoCouponRepository($connection);
    }

    public function show(Request $request): void
    {
        $cart = $this->resolveCart($request);

        Response::success($this->buildCartResponse($cart));
    }

    public function addItem(Request $request): void
    {
        $data = Validator::make($request->input(), [
            'product_id' => 'integer',
            'service_id' => 'integer',
            'quantity' => 'required|integer|gte:1',
        ])->validate();

        [$productId, $serviceId] = $this->assertExactlyOneReference($data);

        // Talla/color elegidos (solo aplica a productos) — sin regla propia
        // en el Validator de arriba (igual que "scheduled_at": el Validator
        // igual devuelve todo el input sin filtrar, ver Validator::validate()),
        // se sanea acá a una lista de enteros antes de que AddCartItemUseCase
        // vuelva a validar que cada uno sea de verdad una variante del producto.
        $variantIds = is_array($data['variant_ids'] ?? null)
            ? array_values(array_filter(array_map('intval', $data['variant_ids']), static fn (int $id) => $id > 0))
            : null;

        $cart = $this->resolveCart($request);

        (new AddCartItemUseCase($this->carts, $this->products, $this->services))
            ->handle((int) $cart['id'], $productId, $serviceId, (int) $data['quantity'], $data['scheduled_at'] ?? null, $variantIds ?: null);

        Response::success($this->buildCartResponse($cart), 'Producto agregado al carrito.', 201);
    }

    public function updateItem(Request $request, string $itemId): void
    {
        $data = Validator::make($request->input(), ['quantity' => 'required|integer|gte:1'])->validate();

        $cart = $this->resolveCart($request);

        (new UpdateCartItemUseCase($this->carts, $this->products))
            ->handle((int) $cart['id'], (int) $itemId, (int) $data['quantity']);

        Response::success($this->buildCartResponse($cart), 'Cantidad actualizada.');
    }

    public function removeItem(Request $request, string $itemId): void
    {
        $cart = $this->resolveCart($request);

        (new RemoveCartItemUseCase($this->carts))->handle((int) $cart['id'], (int) $itemId);

        Response::success($this->buildCartResponse($cart), 'Producto eliminado del carrito.');
    }

    public function clear(Request $request): void
    {
        $cart = $this->resolveCart($request);

        $this->carts->clear((int) $cart['id']);

        Response::success($this->buildCartResponse($cart), 'Carrito vaciado.');
    }

    /** Aplicar cupón (sección 30) — valida contra el subtotal EN VIVO del carrito. */
    public function applyCoupon(Request $request): void
    {
        $data = Validator::make($request->input(), ['code' => 'required|max:50'])->validate();

        $cart = $this->resolveCart($request);
        $coupon = $this->coupons->findByCode(strtoupper(trim($data['code'])));

        if ($coupon === null) {
            throw new ValidationException('No fue posible aplicar el cupón.', [
                'code' => ['El código ingresado no existe.'],
            ]);
        }

        $items = $this->carts->itemsWithLiveData((int) $cart['id']);
        $pricingWithoutCoupon = CartPricingCalculator::calculate($items, 'domicilio', 0, 0);
        $subtotalAfterItemDiscounts = $pricingWithoutCoupon['subtotal'] - $pricingWithoutCoupon['discount_total'];

        // Carrito de invitado (sin user_id todavía): no hay forma de saber si
        // "ya lo usó" hasta que inicie sesión — el checkout (que sí exige
        // login) es quien re-valida esto de forma autoritativa igual.
        $alreadyUsed = $cart['user_id'] !== null
            && $this->coupons->hasBeenUsedByUser((int) $cart['user_id'], (int) $coupon['id']);

        CouponValidator::assertUsable($coupon, $subtotalAfterItemDiscounts, $alreadyUsed);

        $this->carts->setCoupon((int) $cart['id'], (int) $coupon['id']);
        // $cart es un array (por valor): setCoupon() ya actualizó la fila en BD,
        // pero esta copia local sigue con el coupon_id viejo si no se refleja acá.
        $cart['coupon_id'] = $coupon['id'];

        Response::success($this->buildCartResponse($cart), 'Cupón aplicado correctamente.');
    }

    public function removeCoupon(Request $request): void
    {
        $cart = $this->resolveCart($request);
        $this->carts->setCoupon((int) $cart['id'], null);
        $cart['coupon_id'] = null; // misma razón que en applyCoupon(): $cart es una copia por valor

        Response::success($this->buildCartResponse($cart), 'Cupón removido.');
    }

    /**
     * Resuelve el carrito activo por usuario autenticado o por X-Cart-Token
     * de invitado (sección 18). No requiere AuthMiddleware: el carrito
     * funciona para visitantes sin cuenta.
     */
    private function resolveCart(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->attribute('auth_user');
        $token = (string) $request->header('X-Cart-Token', '');

        return $this->carts->resolveActiveCart(
            $user?->id,
            $token !== '' ? $token : null
        );
    }

    private function buildCartResponse(array $cart): array
    {
        $items = $this->carts->itemsWithLiveData((int) $cart['id']);

        // Si el cupón aplicado dejó de ser válido entretanto (venció, se
        // desactivó, alcanzó su límite), se quita solo en vez de que el
        // carrito se quede mostrando un descuento que ya no aplicaría al
        // pagar (sección 54: nunca confiar en un estado leído de antes).
        $coupon = !empty($cart['coupon_id']) ? $this->coupons->find((int) $cart['coupon_id']) : null;
        if ($coupon !== null) {
            $subtotalPreview = CartPricingCalculator::calculate($items, 'domicilio', 0, 0);
            $subtotalAfterItemDiscounts = $subtotalPreview['subtotal'] - $subtotalPreview['discount_total'];

            try {
                $alreadyUsed = $cart['user_id'] !== null
                    && $this->coupons->hasBeenUsedByUser((int) $cart['user_id'], (int) $coupon['id']);

                CouponValidator::assertUsable($coupon, $subtotalAfterItemDiscounts, $alreadyUsed);
            } catch (ValidationException $e) {
                $this->carts->setCoupon((int) $cart['id'], null);
                $coupon = null;
            }
        }

        $pricing = CartPricingCalculator::calculate(
            $items,
            'domicilio', // vista previa; el envío real se confirma en el checkout con el método elegido
            (float) Config::get('app.shipping.flat_rate', 12000),
            (float) Config::get('app.shipping.free_threshold', 300000),
            $coupon
        );

        return array_merge([
            'cart_id' => (int) $cart['id'],
            'cart_token' => $cart['token'],
            'items' => $items,
            'coupon_code' => $coupon['code'] ?? null,
        ], $pricing);
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function assertExactlyOneReference(array $data): array
    {
        $productId = isset($data['product_id']) ? (int) $data['product_id'] : null;
        $serviceId = isset($data['service_id']) ? (int) $data['service_id'] : null;

        if (($productId === null) === ($serviceId === null)) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'product_id' => ['Debes indicar exactamente uno: "product_id" o "service_id".'],
            ]);
        }

        return [$productId, $serviceId];
    }
}
