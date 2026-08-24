<?php

declare(strict_types=1);

use App\Infrastructure\Database\Seeder;

/**
 * Contenido inicial de site_settings (migración 047). El texto de términos
 * y condiciones es un PUNTO DE PARTIDA genérico, no asesoría legal — CASTAMOTO
 * debería hacerlo revisar por un abogado antes de considerarlo vinculante de
 * verdad (menciones a la Ley 1480 de 2011 de Colombia, retracto, garantías).
 *
 * "ON DUPLICATE KEY UPDATE setting_key = setting_key" es intencional (no-op):
 * si en el futuro alguien edita el valor directo en la base, volver a correr
 * este seeder no debe pisarlo.
 */
return new class extends Seeder {
    public function run(PDO $connection): void
    {
        $terms = <<<TEXT
        Términos y Condiciones de CASTAMOTO

        Última actualización: 2026

        1. Aceptación de los términos
        Al registrarte o realizar una compra en CASTAMOTO aceptas estos Términos y Condiciones. Si no estás de acuerdo, por favor no utilices la plataforma.

        2. Productos y servicios
        CASTAMOTO ofrece repuestos, accesorios y servicios especializados para motocicletas. Los precios, la disponibilidad de stock y las descripciones se muestran en tiempo real y pueden cambiar sin previo aviso.

        3. Pedidos y pagos
        Al confirmar un pedido, el total se recalcula con los precios y el stock vigentes al momento del pago — nunca con datos enviados por tu navegador. Los medios de pago disponibles se muestran en el checkout.

        4. Envíos y entrega
        Puedes elegir entrega a domicilio o recogida en tienda. Los tiempos de entrega son estimados y pueden variar según tu ubicación y el transportador.

        5. Devoluciones y garantías
        De acuerdo con la Ley 1480 de 2011 (Estatuto del Consumidor de Colombia), tienes derecho de retracto dentro de los plazos legales para compras a distancia, salvo las excepciones que la ley contempla. Los productos con falla de fábrica cuentan con la garantía legal correspondiente.

        6. Cuenta de usuario
        Eres responsable de mantener la confidencialidad de tu contraseña y de toda actividad realizada desde tu cuenta.

        7. Reseñas
        Solo los usuarios que compraron un producto o servicio pueden dejar una reseña sobre él. Las reseñas deben ser honestas y respetuosas.

        8. Contacto
        Para dudas sobre estos términos, escríbenos por los canales de contacto publicados en el sitio.

        Este documento es una plantilla inicial y debe ser revisado por un asesor legal antes de considerarse definitivo.
        TEXT;

        $privacy = <<<TEXT
        Política de Datos de CASTAMOTO

        Última actualización: 2026

        1. Qué datos recopilamos
        Nombre, correo, teléfono, direcciones de entrega y el historial de pedidos que generás al usar la plataforma — nunca más de lo necesario para procesar tu compra o reserva.

        2. Para qué los usamos
        Para procesar pedidos y reservas, contactarte sobre su estado, y mejorar el servicio. Nunca vendemos tus datos a terceros.

        3. Con quién los compartimos
        Solo con quienes hace falta para completar tu pedido (ej. la pasarela de pago que elijas, el transportador si aplica) — cada uno bajo sus propias políticas de privacidad.

        4. Tus derechos
        De acuerdo con la Ley 1581 de 2012 de Colombia (protección de datos personales), podés pedir acceder, corregir o eliminar tus datos escribiéndonos por los canales de contacto publicados en el sitio.

        5. Seguridad
        Tu contraseña nunca se guarda en texto plano, y las conexiones al sitio están cifradas.

        Este documento es una plantilla inicial y debe ser revisado por un asesor legal antes de considerarse definitivo.
        TEXT;

        $stmt = $connection->prepare(
            'INSERT INTO site_settings (setting_key, value) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE setting_key = setting_key'
        );
        $stmt->execute(['key' => 'terms_and_conditions', 'value' => $terms]);
        $stmt->execute(['key' => 'privacy_policy', 'value' => $privacy]);

        // El WhatsApp de contacto vivía SOLO en .env (CONTACT_WHATSAPP_NUMBER,
        // sin editor en el admin) — se migra acá para que sea administrable
        // como el resto, sembrando el valor que ya hubiera en .env (si lo hay)
        // como punto de partida, sin perderlo.
        $stmt->execute(['key' => 'contact_whatsapp_number', 'value' => $_ENV['CONTACT_WHATSAPP_NUMBER'] ?? '']);
        $stmt->execute(['key' => 'contact_email', 'value' => $_ENV['MAIL_FROM_ADDRESS'] ?? '']);

        // Horario de atención (sección nueva) — arma la grilla de horarios de
        // lavado.js/servicio.js (cada hora, de start a end incluido) y se
        // muestra en la portada. Antes vivía hardcodeado en el frontend
        // (WASH_BUSINESS_HOURS: 08:00 a 17:00) — ahora es editable desde
        // /admin → Configuración, sin tocar código.
        $stmt->execute(['key' => 'business_hours_start', 'value' => '08:30']);
        $stmt->execute(['key' => 'business_hours_end', 'value' => '16:30']);
    }
};
