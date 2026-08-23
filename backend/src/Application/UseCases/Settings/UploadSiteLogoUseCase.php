<?php

declare(strict_types=1);

namespace App\Application\UseCases\Settings;

use App\Application\Support\FileStorage;
use App\Application\Support\UploadedFileValidator;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Persistence\PdoSiteSettingsRepository;

/**
 * Logo del sitio administrable (/admin → Configuración): mismo criterio de
 * subida segura que avatares/imágenes de catálogo (sección 44) — se guarda
 * fuera del docroot con un nombre generado, nunca el original. El nombre de
 * archivo se persiste en site_settings (clave "site_logo", migración 047),
 * igual que los términos y condiciones — no hace falta una tabla nueva.
 *
 * El logo VIEJO se borra del disco al reemplazarlo: a diferencia de las
 * imágenes de catálogo (que son una lista y pueden convivir varias), acá
 * solo existe UN logo vigente a la vez, así que no tiene sentido dejar el
 * anterior huérfano en storage/uploads/settings.
 */
final class UploadSiteLogoUseCase
{
    public function __construct(private PdoSiteSettingsRepository $settings)
    {
    }

    /**
     * @param array $file Entrada estilo $_FILES['logo'] (name, tmp_name, size, error).
     */
    public function handle(array $file): string
    {
        UploadedFileValidator::assertValid(
            $file,
            'logo',
            (int) Config::get('app.uploads.logo_max_size_kb', 2048),
            (array) Config::get('app.uploads.logo_allowed_extensions', []),
            (array) Config::get('app.uploads.logo_allowed_mimes', [])
        );

        $directory = (string) Config::get('app.base_path') . '/storage/uploads/settings';
        $filename = FileStorage::store($file, $directory);

        $previous = $this->settings->get('site_logo');
        $this->settings->set('site_logo', $filename);

        if ($previous) {
            FileStorage::delete($directory, $previous);
        }

        return $filename;
    }
}
