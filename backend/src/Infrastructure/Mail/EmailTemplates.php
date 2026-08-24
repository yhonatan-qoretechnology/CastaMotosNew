<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

/**
 * Plantillas de correo con la identidad visual de CASTAMOTO (negro/amarillo),
 * tal como pide la sección 23 del prompt maestro.
 */
final class EmailTemplates
{
    public static function verificationEmail(string $name, string $verifyUrl): array
    {
        return [
            'subject' => 'Verifica tu correo — CASTAMOTO',
            'html' => self::layout(
                'Confirma tu correo',
                "<p>Hola {$name},</p>
                <p>Gracias por registrarte en CASTAMOTO. Confirma tu correo para activar todas las funciones de tu cuenta.</p>",
                $verifyUrl,
                'Verificar mi correo'
            ),
        ];
    }

    /** Backup de base de datos (/admin → Configuración) — el archivo va como adjunto, ver BackupController. */
    public static function backupEmail(string $databaseName, int $tableCount, string $sizeLabel, string $adminUrl): array
    {
        $generatedAt = date('d/m/Y \a \l\a\s H:i');

        return [
            'subject' => "Backup de base de datos — {$databaseName} — " . date('Y-m-d H:i'),
            'html' => self::layout(
                'Backup de la base de datos',
                "<p>Adjunto el backup completo de la base de datos <strong>{$databaseName}</strong>, generado el {$generatedAt}.</p>
                <p>{$tableCount} tablas — {$sizeLabel} comprimido.</p>
                <p style=\"color:#8a8a8a;font-size:13px;\">Guardalo en un lugar seguro. Podés generar uno nuevo cuando quieras desde /admin → Configuración.</p>",
                $adminUrl,
                'Ir al panel de administración'
            ),
        ];
    }

    public static function passwordResetEmail(string $name, string $resetUrl): array
    {
        return [
            'subject' => 'Recupera tu contraseña — CASTAMOTO',
            'html' => self::layout(
                'Restablece tu contraseña',
                "<p>Hola {$name},</p>
                <p>Recibimos una solicitud para restablecer tu contraseña. Si no fuiste tú, ignora este correo.</p>",
                $resetUrl,
                'Restablecer contraseña'
            ),
        ];
    }

    /**
     * "Se crea pedido" (sección 23) — el primer correo del ciclo de vida,
     * apenas se confirma el checkout (estado inicial siempre PENDIENTE).
     * A diferencia de orderStatusEmail() de abajo, este SÍ es el recibo
     * completo (ítems, dirección, pago, desglose) — el resto del ciclo son
     * solo avisos cortos de "tu pedido pasó a X", no hace falta repetir todo.
     *
     * @param array $order Payload armado en CheckoutUseCase: order_number,
     *   items[], subtotal, discount_total, tax_total, shipping_total, total,
     *   delivery_method, payment_method_name, address (puede ser null si la
     *   dirección se borró entretanto — no debería pasar, pero no se asume).
     */
    public static function orderCreatedEmail(string $name, array $order, string $orderUrl): array
    {
        return [
            'subject' => "Recibimos tu pedido {$order['order_number']} — CASTAMOTO",
            'html' => self::layout(
                '¡Gracias por tu compra!',
                "<p>Hola {$name},</p>
                <p>Recibimos tu pedido <strong>{$order['order_number']}</strong> y ya lo estamos procesando. Acá el resumen completo:</p>"
                . self::itemsTable($order['items'])
                . self::totalsBlock($order)
                . self::deliveryBlock($order)
                . "<p>Te avisaremos por correo en cada paso: cuando se confirme, cuando el pago quede confirmado, cuando esté en preparación, en camino y entregado.</p>",
                $orderUrl,
                'Ver mi pedido'
            ),
        ];
    }

    /** Tabla de ítems del pedido (sección 23: "correo bien profesional... con toda la información"). */
    private static function itemsTable(array $items): string
    {
        $rows = '';
        foreach ($items as $item) {
            $scheduleLine = !empty($item['scheduled_at'])
                ? '<br><span style="color:#8a8a8a;font-size:12px;">Agendado: ' . htmlspecialchars(self::formatDate((string) $item['scheduled_at'])) . '</span>'
                : '';
            $rows .= '<tr>
                <td style="padding:8px 0;border-bottom:1px solid #2c2c2c;">'
                    . htmlspecialchars((string) $item['name_snapshot']) . ' × ' . (int) $item['quantity'] . $scheduleLine
                . '</td>
                <td style="padding:8px 0;border-bottom:1px solid #2c2c2c;text-align:right;white-space:nowrap;">' . self::formatCop((float) $item['subtotal']) . '</td>
            </tr>';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0;font-size:14px;">' . $rows . '</table>';
    }

    private static function totalsBlock(array $order): string
    {
        $rows = [
            ['Subtotal', (float) $order['subtotal']],
            ['Descuento', -1 * (float) ($order['discount_total'] ?? 0)],
            ['Impuestos', (float) ($order['tax_total'] ?? 0)],
            ['Envío', (float) ($order['shipping_total'] ?? 0)],
        ];

        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;color:#b3b3b3;">';
        foreach ($rows as [$label, $value]) {
            $html .= "<tr><td style=\"padding:2px 0;\">{$label}</td><td style=\"padding:2px 0;text-align:right;\">" . self::formatCop($value) . '</td></tr>';
        }
        $html .= '<tr><td style="padding:8px 0 0;font-weight:bold;color:#f4c430;font-size:16px;">Total</td>'
            . '<td style="padding:8px 0 0;font-weight:bold;color:#f4c430;font-size:16px;text-align:right;">' . self::formatCop((float) $order['total']) . '</td></tr>';
        $html .= '</table>';

        return $html;
    }

    private static function deliveryBlock(array $order): string
    {
        $deliveryLabel = $order['delivery_method'] === 'recogida_tienda' ? 'Recogida en tienda' : 'Entrega a domicilio';
        $address = $order['address'] ?? null;

        $addressLine = '';
        if ($address !== null && $order['delivery_method'] !== 'recogida_tienda') {
            $addressLine = '<br>' . htmlspecialchars(
                trim("{$address['address_line']}, {$address['city']}, {$address['state']}")
            );
        }

        return '<p style="margin:16px 0 4px;font-size:13px;color:#b3b3b3;">'
            . "<strong style=\"color:#e6e6e6;\">Entrega:</strong> {$deliveryLabel}{$addressLine}<br>"
            . '<strong style="color:#e6e6e6;">Pago:</strong> ' . htmlspecialchars((string) $order['payment_method_name'])
            . '</p>';
    }

    private static function formatDate(string $value): string
    {
        $timestamp = strtotime(str_replace(' ', 'T', $value));

        return $timestamp !== false ? date('d/m/Y h:i A', $timestamp) : $value;
    }

    /**
     * Resto del ciclo de vida del pedido (sección 22/23): confirmado, pago
     * confirmado, preparación, en camino, entregado, cancelado. Un solo
     * método porque las seis comparten la misma forma (encabezado + mensaje
     * + total + botón "ver pedido") — solo cambia el texto según el estado.
     * Los estados que la sección 23 no menciona (PAGO_PENDIENTE, DEVUELTO)
     * no generan correo: devuelve null y el llamador simplemente no envía nada.
     */
    public static function orderStatusEmail(string $status, string $name, string $orderNumber, float $total, string $orderUrl): ?array
    {
        $content = self::STATUS_CONTENT[$status] ?? null;
        if ($content === null) {
            return null;
        }

        return [
            'subject' => "{$content['subject']} — Pedido {$orderNumber}",
            'html' => self::layout(
                $content['heading'],
                "<p>Hola {$name},</p>
                <p>{$content['message']}</p>
                <p>Pedido <strong>{$orderNumber}</strong> — Total: " . self::formatCop($total) . '.</p>',
                $orderUrl,
                'Ver mi pedido'
            ),
        ];
    }

    private const STATUS_CONTENT = [
        'CONFIRMADO' => [
            'subject' => 'Tu pedido fue confirmado',
            'heading' => 'Pedido confirmado',
            'message' => 'Confirmamos tu pedido y ya lo estamos procesando.',
        ],
        'PAGO_CONFIRMADO' => [
            'subject' => 'Confirmamos tu pago',
            'heading' => 'Pago confirmado',
            'message' => 'Ya confirmamos tu pago. En breve empezamos a preparar tu pedido.',
        ],
        'PREPARANDO' => [
            'subject' => 'Tu pedido está en preparación',
            'heading' => 'Preparando tu pedido',
            'message' => 'Estamos alistando tu pedido para el envío o para que lo recojas.',
        ],
        'EN_CAMINO' => [
            'subject' => 'Tu pedido está en camino',
            'heading' => 'Pedido en camino',
            'message' => 'Tu pedido ya salió y va en camino a tu dirección.',
        ],
        'ENTREGADO' => [
            'subject' => 'Tu pedido fue entregado',
            'heading' => 'Pedido entregado',
            'message' => '¡Tu pedido fue entregado! Gracias por comprar en CASTAMOTO.',
        ],
        'CANCELADO' => [
            'subject' => 'Tu pedido fue cancelado',
            'heading' => 'Pedido cancelado',
            'message' => 'Tu pedido fue cancelado. Si tienes dudas, contáctanos.',
        ],
    ];

    /** Formato de moneda simple (COP, sin decimales) — mismo criterio que
     * helpers.formatCurrency() en el frontend, acá del lado del correo. */
    private static function formatCop(float $amount): string
    {
        return '$' . number_format($amount, 0, ',', '.') . ' COP';
    }

    private static function layout(string $title, string $bodyHtml, string $ctaUrl, string $ctaLabel): string
    {
        return <<<HTML
        <!DOCTYPE html>
        <html lang="es">
        <body style="margin:0;padding:0;background:#0d0d0d;font-family:Arial,sans-serif;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0d0d0d;padding:32px 0;">
                <tr>
                    <td align="center">
                        <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background:#1a1a1a;border-radius:8px;overflow:hidden;">
                            <tr>
                                <td style="background:#0d0d0d;padding:24px;text-align:center;border-bottom:2px solid #f4c430;">
                                    <span style="color:#f4c430;font-size:22px;font-weight:bold;letter-spacing:1px;">CASTAMOTO</span>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:24px;color:#e6e6e6;font-size:15px;line-height:1.6;">
                                    <h2 style="color:#f4c430;margin-top:0;">{$title}</h2>
                                    {$bodyHtml}
                                    <p style="text-align:center;margin:28px 0;">
                                        <a href="{$ctaUrl}" style="background:#f4c430;color:#0d0d0d;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold;display:inline-block;">{$ctaLabel}</a>
                                    </p>
                                    <p style="color:#8a8a8a;font-size:12px;">Si el botón no funciona, copia y pega este enlace: <br>{$ctaUrl}</p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        HTML;
    }
}
