<?php

declare(strict_types=1);

/**
 * Configuración general de la aplicación.
 * Los valores sensibles siempre se leen desde el archivo .env, nunca se hardcodean aquí.
 */
return [
    'name' => $_ENV['APP_NAME'] ?? 'CASTAMOTO',
    'env' => $_ENV['APP_ENV'] ?? 'local',
    'url' => $_ENV['APP_URL'] ?? 'http://localhost',
    'timezone' => $_ENV['APP_TIMEZONE'] ?? 'America/Bogota',

    'cors' => [
        // "*" solo debe usarse en desarrollo. En producción definir dominios específicos en .env.
        'allowed_origins' => $_ENV['CORS_ALLOWED_ORIGINS'] ?? '*',
    ],

    'jwt' => [
        'secret' => $_ENV['JWT_SECRET'] ?? '',
        'ttl' => (int) ($_ENV['JWT_TTL'] ?? 3600),
    ],

    'auth' => [
        // Fuerza bruta (sección 6): intentos fallidos permitidos antes de bloquear el login.
        'max_login_attempts' => (int) ($_ENV['AUTH_MAX_LOGIN_ATTEMPTS'] ?? 5),
        'lockout_minutes' => (int) ($_ENV['AUTH_LOCKOUT_MINUTES'] ?? 15),
        // TTL del JWT cuando el usuario marca "recordar sesión" (sección 7).
        'remember_ttl_days' => (int) ($_ENV['AUTH_REMEMBER_TTL_DAYS'] ?? 30),
        'email_verification_ttl_hours' => (int) ($_ENV['EMAIL_VERIFICATION_TTL_HOURS'] ?? 24),
        'password_reset_ttl_minutes' => (int) ($_ENV['PASSWORD_RESET_TTL_MINUTES'] ?? 60),
        // "Continuar con Google" (sección nueva): GOOGLE_CLIENT_ID es público a
        // propósito (se expone vía /api/settings/public para armar el botón en
        // el frontend) — a diferencia de un API key, un OAuth Client ID no es
        // secreto, Google lo espera visible en el navegador. Vacío = el botón
        // no se muestra (mismo criterio honesto que AiProviderFactory).
        'google_client_id' => $_ENV['GOOGLE_CLIENT_ID'] ?? '',
    ],

    'mail' => [
        'host' => $_ENV['MAIL_HOST'] ?? '',
        'port' => (int) ($_ENV['MAIL_PORT'] ?? 587),
        'username' => $_ENV['MAIL_USERNAME'] ?? '',
        'password' => $_ENV['MAIL_PASSWORD'] ?? '',
        'from_address' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'no-reply@castamoto.local',
        'from_name' => $_ENV['MAIL_FROM_NAME'] ?? 'CASTAMOTO',
    ],

    'payment' => [
        'provider' => $_ENV['PAYMENT_PROVIDER'] ?? '',
        'public_key' => $_ENV['PAYMENT_PUBLIC_KEY'] ?? '',
        'secret_key' => $_ENV['PAYMENT_SECRET_KEY'] ?? '',
    ],

    'ai' => [
        'provider' => $_ENV['AI_PROVIDER'] ?? '',
        'api_key' => $_ENV['AI_API_KEY'] ?? '',
        // Vacío = usa el default de AiProviderFactory según el proveedor.
        'model' => $_ENV['AI_MODEL'] ?? '',
    ],

    'push' => [
        'provider' => $_ENV['PUSH_PROVIDER'] ?? '',
    ],

    'cart' => [
        'guest_token_bytes' => 20,
    ],

    'shipping' => [
        // Tarifa plana provisional (sección 51: integración real con transportadoras
        // queda para más adelante). Se ignora si delivery_method = 'recogida_tienda'.
        'flat_rate' => (float) ($_ENV['SHIPPING_FLAT_RATE'] ?? 12000),
        'free_threshold' => (float) ($_ENV['SHIPPING_FREE_THRESHOLD'] ?? 300000),
    ],

    'uploads' => [
        // Validación de subida de archivos (sección 44): extensión, MIME y tamaño.
        'avatar_max_size_kb' => (int) ($_ENV['AVATAR_MAX_SIZE_KB'] ?? 2048),
        'avatar_allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
        'avatar_allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp'],

        'catalog_image_max_size_kb' => (int) ($_ENV['CATALOG_IMAGE_MAX_SIZE_KB'] ?? 4096),
        'catalog_image_allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
        'catalog_image_allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
        // Máximo de fotos por producto/servicio (compartido entre los dos catálogos).
        'max_images_per_catalog_item' => (int) ($_ENV['MAX_IMAGES_PER_CATALOG_ITEM'] ?? 6),

        // Logo del sitio (sección nueva, /admin → Configuración) — mismo criterio
        // que avatar/catálogo: sin SVG (riesgo XSS, ver MediaController).
        'logo_max_size_kb' => (int) ($_ENV['LOGO_MAX_SIZE_KB'] ?? 2048),
        'logo_allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
        'logo_allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
    ],

    'admin' => [
        'name' => $_ENV['ADMIN_NAME'] ?? 'Administrador',
        'email' => $_ENV['ADMIN_EMAIL'] ?? 'admin@castamoto.local',
        'password' => $_ENV['ADMIN_PASSWORD'] ?? null,
    ],

    'backup' => [
        // Destino del botón "Enviar backup" del admin (sección nueva). Por
        // defecto el mismo correo desde el que ya se envía todo (MAIL_FROM_ADDRESS)
        // — así el admin se lo manda a sí mismo sin tener que tipear otra
        // dirección a mano (fuente de errores ya vista con el typo de Gmail).
        // BACKUP_EMAIL en .env permite mandarlo a otro lado si hace falta.
        'email' => $_ENV['BACKUP_EMAIL'] ?? ($_ENV['MAIL_FROM_ADDRESS'] ?? ''),
    ],

    'contact' => [
        // Número de WhatsApp del negocio, formato internacional sin "+" ni espacios
        // (ej. 573001234567), tal como lo exige la API de wa.me. Se expone solo vía
        // GET /api/settings/public (SettingsController) — nunca se hardcodea en el frontend.
        'whatsapp_number' => $_ENV['CONTACT_WHATSAPP_NUMBER'] ?? '',
    ],
];
