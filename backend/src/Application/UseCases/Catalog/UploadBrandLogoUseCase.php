<?php

declare(strict_types=1);

namespace App\Application\UseCases\Catalog;

use App\Application\Support\FileStorage;
use App\Application\Support\UploadedFileValidator;
use App\Domain\Repositories\BrandRepositoryInterface;
use App\Exceptions\NotFoundException;
use App\Infrastructure\Config\Config;

/**
 * Logo de marca (/admin → Marcas) — reemplaza la URL externa que se pedía
 * antes por una subida real (mismo criterio que el ícono de categoría/logo
 * del sitio): un archivo, nombre generado, se reemplaza el que hubiera.
 */
final class UploadBrandLogoUseCase
{
    public function __construct(private BrandRepositoryInterface $brands)
    {
    }

    /**
     * @param array $file Entrada estilo $_FILES['logo'] (name, tmp_name, size, error).
     */
    public function handle(int $brandId, array $file): string
    {
        $brand = $this->brands->find($brandId);
        if ($brand === null) {
            throw new NotFoundException('Marca no encontrada.');
        }

        UploadedFileValidator::assertValid(
            $file,
            'logo',
            (int) Config::get('app.uploads.catalog_image_max_size_kb', 4096),
            (array) Config::get('app.uploads.catalog_image_allowed_extensions', []),
            (array) Config::get('app.uploads.catalog_image_allowed_mimes', [])
        );

        $directory = (string) Config::get('app.base_path') . '/storage/uploads/brands';
        $filename = FileStorage::store($file, $directory);

        $this->brands->updateLogo($brandId, $filename);

        if (!empty($brand['logo'])) {
            FileStorage::delete($directory, (string) $brand['logo']);
        }

        return $filename;
    }
}
