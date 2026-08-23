<?php

declare(strict_types=1);

namespace App\Presentation\Controllers;

use App\Application\UseCases\Settings\UploadSiteLogoUseCase;
use App\Exceptions\ValidationException;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Response;
use App\Infrastructure\Persistence\PdoSiteSettingsRepository;

/**
 * Configuración pública y segura de exponer al frontend (nunca secretos ni
 * llaves privadas — para eso está el archivo .env, que el frontend nunca lee
 * directamente). Hoy expone el contacto de WhatsApp (.env) y los textos
 * generales del sitio (tabla site_settings, migración 047) — se amplía aquí
 * si el frontend necesita otro dato de configuración no sensible.
 */
final class SettingsController
{
    public function publicSettings(Request $request): void
    {
        $settings = new PdoSiteSettingsRepository(Connection::get());

        Response::success([
            'contact_whatsapp_number' => (string) Config::get('app.contact.whatsapp_number', ''),
            // Solo el nombre de archivo (igual que products.primary_image) — el
            // frontend arma la URL con helpers.mediaUrl('settings', ...). Null =
            // todavía no se subió ninguno, el frontend cae al logo estático.
            'site_logo' => $settings->get('site_logo'),
        ]);
    }

    public function terms(Request $request): void
    {
        $settings = new PdoSiteSettingsRepository(Connection::get());

        Response::success([
            'content' => $settings->get('terms_and_conditions') ?? '',
        ]);
    }

    /** Edición desde /admin (permiso manage-settings) — el mismo texto que ya muestra /terminos. */
    public function updateTerms(Request $request): void
    {
        $data = \App\Application\Validation\Validator::make($request->input(), [
            'content' => 'required',
        ])->validate();

        $settings = new PdoSiteSettingsRepository(Connection::get());
        $settings->set('terms_and_conditions', $data['content']);

        Response::success(null, 'Términos y condiciones actualizados.');
    }

    /** Logo del sitio (permiso manage-settings) — reemplaza el que hubiera. */
    public function uploadLogo(Request $request): void
    {
        $file = $request->file('logo');
        if ($file === null) {
            throw new ValidationException('No fue posible subir el logo.', [
                'logo' => ['Debes adjuntar un archivo con el campo "logo".'],
            ]);
        }

        $settings = new PdoSiteSettingsRepository(Connection::get());
        $filename = (new UploadSiteLogoUseCase($settings))->handle($file);

        Response::success(['site_logo' => $filename], 'Logo actualizado correctamente.');
    }
}
