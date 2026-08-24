<?php

declare(strict_types=1);

use App\Infrastructure\Http\Router;
use App\Presentation\Controllers\AddressController;
use App\Presentation\Controllers\AssistantController;
use App\Presentation\Controllers\AdminInventoryController;
use App\Presentation\Controllers\AdminCouponController;
use App\Presentation\Controllers\AdminCustomerController;
use App\Presentation\Controllers\AdminOrderController;
use App\Presentation\Controllers\AdminPaymentMethodController;
use App\Presentation\Controllers\AdminReservationController;
use App\Presentation\Controllers\AuthController;
use App\Presentation\Controllers\BrandController;
use App\Presentation\Controllers\CartController;
use App\Presentation\Controllers\CategoryController;
use App\Presentation\Controllers\CheckoutController;
use App\Presentation\Controllers\DashboardController;
use App\Presentation\Controllers\FavoriteController;
use App\Presentation\Controllers\HealthController;
use App\Presentation\Controllers\MediaController;
use App\Presentation\Controllers\NotificationController;
use App\Presentation\Controllers\PaymentMethodController;
use App\Presentation\Controllers\ProductController;
use App\Presentation\Controllers\ProfileController;
use App\Presentation\Controllers\SupplierController;
use App\Presentation\Controllers\PushSubscriptionController;
use App\Presentation\Controllers\ReviewController;
use App\Presentation\Controllers\SearchController;
use App\Presentation\Controllers\ServiceController;
use App\Presentation\Controllers\SettingsController;
use App\Presentation\Controllers\TranslateController;
use App\Presentation\Middleware\AuthMiddleware;
use App\Presentation\Middleware\OptionalAuthMiddleware;
use App\Presentation\Middleware\RequirePermissionMiddleware;

/**
 * Registro central de rutas de la API. Las rutas de negocio restantes
 * (carrito, pedidos, etc.) se irán agregando aquí en las siguientes fases.
 *
 * @var Router $router
 */

$router->get('api/health', [HealthController::class, 'index']);
$router->get('api/settings/public', [SettingsController::class, 'publicSettings']);
$router->get('api/settings/terms', [SettingsController::class, 'terms']);
$router->get('api/settings/privacy', [SettingsController::class, 'privacyPolicy']);
$router->put('api/admin/settings/terms', [SettingsController::class, 'updateTerms'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-settings')]);
$router->put('api/admin/settings/privacy', [SettingsController::class, 'updatePrivacyPolicy'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-settings')]);
$router->put('api/admin/settings/contact', [SettingsController::class, 'updateContactInfo'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-settings')]);
$router->put('api/admin/settings/business-hours', [SettingsController::class, 'updateBusinessHours'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-settings')]);
$router->post('api/admin/settings/logo', [SettingsController::class, 'uploadLogo'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-settings')]);

// --- Asistente de preguntas (Fase 11) — funciona para invitados ---
$router->post('api/assistant/ask', [AssistantController::class, 'ask'], [new OptionalAuthMiddleware()]);
$router->post('api/notifications/subscribe', [PushSubscriptionController::class, 'subscribe'], [new AuthMiddleware()]);
$router->delete('api/notifications/subscribe', [PushSubscriptionController::class, 'unsubscribe'], [new AuthMiddleware()]);

// --- Autenticación (Fase 2) ---
$router->post('api/auth/register', [AuthController::class, 'register']);
$router->post('api/auth/login', [AuthController::class, 'login']);
$router->post('api/auth/google', [AuthController::class, 'google']);
$router->get('api/auth/verify-email', [AuthController::class, 'verifyEmail']);
$router->post('api/auth/resend-verification', [AuthController::class, 'resendVerification']);
$router->post('api/auth/forgot-password', [AuthController::class, 'forgotPassword']);
$router->post('api/auth/reset-password', [AuthController::class, 'resetPassword']);

$router->post('api/auth/logout', [AuthController::class, 'logout'], [new AuthMiddleware()]);
$router->get('api/auth/me', [AuthController::class, 'me'], [new AuthMiddleware()]);
$router->post('api/auth/change-password', [AuthController::class, 'changePassword'], [new AuthMiddleware()]);

// --- Perfil (Fase 2 / sección 8) ---
$router->get('api/profile', [ProfileController::class, 'show'], [new AuthMiddleware()]);
$router->put('api/profile', [ProfileController::class, 'update'], [new AuthMiddleware()]);
$router->post('api/profile/avatar', [ProfileController::class, 'uploadAvatar'], [new AuthMiddleware()]);

// --- Direcciones (Fase 2 / sección 9) ---
$router->get('api/addresses', [AddressController::class, 'index'], [new AuthMiddleware()]);
$router->post('api/addresses', [AddressController::class, 'store'], [new AuthMiddleware()]);
$router->put('api/addresses/{id}', [AddressController::class, 'update'], [new AuthMiddleware()]);
$router->delete('api/addresses/{id}', [AddressController::class, 'destroy'], [new AuthMiddleware()]);
$router->put('api/addresses/{id}/primary', [AddressController::class, 'setPrimary'], [new AuthMiddleware()]);

// --- Categorías (Fase 3 / sección 13) ---
$router->get('api/categories', [CategoryController::class, 'index'], [new OptionalAuthMiddleware()]);
$router->get('api/categories/{slug}', [CategoryController::class, 'show'], [new OptionalAuthMiddleware()]);
$router->post('api/categories', [CategoryController::class, 'store'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-categories')]);
$router->put('api/categories/{id}', [CategoryController::class, 'update'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-categories')]);
$router->post('api/categories/{id}/image', [CategoryController::class, 'uploadImage'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-categories')]);
$router->delete('api/categories/{id}', [CategoryController::class, 'destroy'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-categories')]);

// --- Marcas (Fase 3 / sección 10) ---
$router->get('api/brands', [BrandController::class, 'index'], [new OptionalAuthMiddleware()]);
$router->post('api/brands', [BrandController::class, 'store'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-brands')]);
$router->put('api/brands/{id}', [BrandController::class, 'update'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-brands')]);
$router->post('api/brands/{id}/logo', [BrandController::class, 'uploadLogo'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-brands')]);
$router->delete('api/brands/{id}', [BrandController::class, 'destroy'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-brands')]);

// Proveedores (admin, agenda interna) — a diferencia de brands, ni el index es público.
$router->get('api/admin/suppliers', [SupplierController::class, 'index'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-suppliers')]);
$router->post('api/admin/suppliers', [SupplierController::class, 'store'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-suppliers')]);
$router->put('api/admin/suppliers/{id}', [SupplierController::class, 'update'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-suppliers')]);
$router->delete('api/admin/suppliers/{id}', [SupplierController::class, 'destroy'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-suppliers')]);

// --- Productos (Fase 3 / secciones 10-11) ---
$router->get('api/products', [ProductController::class, 'index'], [new OptionalAuthMiddleware()]);
$router->get('api/products/{slug}', [ProductController::class, 'show'], [new OptionalAuthMiddleware()]);
$router->post('api/products', [ProductController::class, 'store'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-products')]);
$router->put('api/products/{id}', [ProductController::class, 'update'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-products')]);
$router->delete('api/products/{id}', [ProductController::class, 'destroy'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-products')]);
$router->post('api/products/{id}/images', [ProductController::class, 'uploadImage'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-products')]);
$router->delete('api/products/{id}/images/{imageId}', [ProductController::class, 'deleteImage'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-products')]);
$router->put('api/products/{id}/images/{imageId}/primary', [ProductController::class, 'setPrimaryImage'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-products')]);
$router->put('api/products/{id}/variants', [ProductController::class, 'syncVariants'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-products')]);
$router->put('api/products/{id}/attributes', [ProductController::class, 'syncAttributes'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-products')]);
// Traducción ES → EN para los campos "_en" del admin (productos y servicios,
// ver TranslateController) — manage-products alcanza para las dos pantallas
// porque los mismos roles siempre tienen manage-products y manage-services juntos.
$router->post('api/admin/translate', [TranslateController::class, 'translate'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-products')]);

// --- Servicios (Fase 3 / sección 12) ---
$router->get('api/services', [ServiceController::class, 'index'], [new OptionalAuthMiddleware()]);
$router->get('api/services/{slug}', [ServiceController::class, 'show'], [new OptionalAuthMiddleware()]);
$router->get('api/services/{id}/booked-times', [ServiceController::class, 'bookedTimes']);
$router->post('api/services', [ServiceController::class, 'store'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-services')]);
$router->put('api/services/{id}', [ServiceController::class, 'update'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-services')]);
$router->delete('api/services/{id}', [ServiceController::class, 'destroy'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-services')]);
$router->post('api/services/{id}/images', [ServiceController::class, 'uploadImage'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-services')]);
$router->delete('api/services/{id}/images/{imageId}', [ServiceController::class, 'deleteImage'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-services')]);

// --- Búsqueda (Fase 4 / sección 14) ---
$router->get('api/search', [SearchController::class, 'search']);
$router->get('api/search/suggestions', [SearchController::class, 'suggestions']);

// --- Favoritos (Fase 4 / sección 16) ---
$router->get('api/favorites', [FavoriteController::class, 'index'], [new AuthMiddleware()]);
$router->post('api/favorites', [FavoriteController::class, 'store'], [new AuthMiddleware()]);
$router->get('api/favorites/check', [FavoriteController::class, 'check'], [new AuthMiddleware()]);
$router->delete('api/favorites/{type}/{id}', [FavoriteController::class, 'destroy'], [new AuthMiddleware()]);

// Campanita de notificaciones (header, todo el sitio) — cada usuario ve solo las suyas.
$router->get('api/notifications', [NotificationController::class, 'index'], [new AuthMiddleware()]);
$router->put('api/notifications/read-all', [NotificationController::class, 'markAllRead'], [new AuthMiddleware()]);
$router->put('api/notifications/{id}/read', [NotificationController::class, 'markRead'], [new AuthMiddleware()]);

// --- Reseñas (sección 26): lectura pública, publicar requiere sesión + haber comprado ---
$router->get('api/reviews', [ReviewController::class, 'index']);
$router->post('api/reviews', [ReviewController::class, 'store'], [new AuthMiddleware()]);

// --- Carrito (Fase 5 / sección 18) — funciona para invitados vía X-Cart-Token ---
$router->get('api/cart', [CartController::class, 'show'], [new OptionalAuthMiddleware()]);
$router->post('api/cart/items', [CartController::class, 'addItem'], [new OptionalAuthMiddleware()]);
$router->put('api/cart/items/{itemId}', [CartController::class, 'updateItem'], [new OptionalAuthMiddleware()]);
$router->delete('api/cart/items/{itemId}', [CartController::class, 'removeItem'], [new OptionalAuthMiddleware()]);
$router->delete('api/cart', [CartController::class, 'clear'], [new OptionalAuthMiddleware()]);
$router->post('api/cart/coupon', [CartController::class, 'applyCoupon'], [new OptionalAuthMiddleware()]);
$router->delete('api/cart/coupon', [CartController::class, 'removeCoupon'], [new OptionalAuthMiddleware()]);

// --- Métodos de pago habilitados (consulta pública para el checkout) ---
$router->get('api/payment-methods', [PaymentMethodController::class, 'index']);
$router->get('api/admin/payment-methods', [AdminPaymentMethodController::class, 'index'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-payment-methods')]);
$router->put('api/admin/payment-methods/{id}', [AdminPaymentMethodController::class, 'update'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-payment-methods')]);

// --- Checkout y pedidos (Fase 5 / sección 19) ---
$router->post('api/checkout', [CheckoutController::class, 'store'], [new AuthMiddleware()]);
$router->get('api/orders', [CheckoutController::class, 'index'], [new AuthMiddleware()]);
$router->get('api/orders/{orderNumber}', [CheckoutController::class, 'show'], [new AuthMiddleware()]);

// --- Administración de pedidos e inventario (Fase 6 / secciones 22, 25) ---
$router->get('api/admin/orders', [AdminOrderController::class, 'index'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-orders')]);
$router->get('api/admin/orders/{orderNumber}', [AdminOrderController::class, 'show'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-orders')]);
$router->put('api/admin/orders/{orderNumber}/status', [AdminOrderController::class, 'updateStatus'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-orders')]);
$router->get('api/admin/reservations', [AdminReservationController::class, 'index'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-orders')]);
$router->get('api/admin/dashboard/summary', [DashboardController::class, 'summary'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-orders')]);
$router->get('api/admin/customers', [AdminCustomerController::class, 'index'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-users')]);
$router->put('api/admin/customers/{id}/role', [AdminCustomerController::class, 'updateRole'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-roles')]);
$router->get('api/admin/coupons', [AdminCouponController::class, 'index'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-coupons')]);
$router->post('api/admin/coupons', [AdminCouponController::class, 'store'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-coupons')]);
$router->put('api/admin/coupons/{id}', [AdminCouponController::class, 'update'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-coupons')]);
$router->delete('api/admin/coupons/{id}', [AdminCouponController::class, 'destroy'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-coupons')]);

$router->get('api/admin/inventory', [AdminInventoryController::class, 'index'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-inventory')]);
$router->get('api/admin/inventory/movements', [AdminInventoryController::class, 'movements'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-inventory')]);
$router->post('api/admin/inventory/{productId}/adjust', [AdminInventoryController::class, 'adjust'], [new AuthMiddleware(), new RequirePermissionMiddleware('manage-inventory')]);

// --- Archivos servidos (avatares, imágenes de catálogo) ---
$router->get('api/media/avatars/{filename}', [MediaController::class, 'avatar']);
$router->get('api/media/products/{filename}', [MediaController::class, 'productImage']);
$router->get('api/media/services/{filename}', [MediaController::class, 'serviceImage']);
$router->get('api/media/settings/{filename}', [MediaController::class, 'siteLogo']);
$router->get('api/media/categories/{filename}', [MediaController::class, 'categoryImage']);
$router->get('api/media/brands/{filename}', [MediaController::class, 'brandLogo']);
