<?php

declare(strict_types=1);

namespace App\Application\UseCases\Catalog;

use App\Application\Support\FileStorage;
use App\Application\Support\UploadedFileValidator;
use App\Domain\Repositories\CategoryRepositoryInterface;
use App\Exceptions\NotFoundException;
use App\Infrastructure\Config\Config;

/**
 * Ícono/imagen de categoría (/admin → Categorías) — UNA sola imagen por
 * categoría, que se reemplaza cada vez (a diferencia de las galerías de
 * producto/servicio, acá no tiene sentido tener varias: es un ícono, no
 * fotos del producto). Mismo criterio de subida segura que avatar/logo del
 * sitio (sección 44): nombre generado, nunca el original.
 */
final class UploadCategoryImageUseCase
{
    public function __construct(private CategoryRepositoryInterface $categories)
    {
    }

    /**
     * @param array $file Entrada estilo $_FILES['image'] (name, tmp_name, size, error).
     */
    public function handle(int $categoryId, array $file): string
    {
        $category = $this->categories->find($categoryId);
        if ($category === null) {
            throw new NotFoundException('Categoría no encontrada.');
        }

        // Mismos límites que las imágenes de catálogo (producto/servicio) —
        // un ícono de categoría no necesita reglas propias.
        UploadedFileValidator::assertValid(
            $file,
            'image',
            (int) Config::get('app.uploads.catalog_image_max_size_kb', 4096),
            (array) Config::get('app.uploads.catalog_image_allowed_extensions', []),
            (array) Config::get('app.uploads.catalog_image_allowed_mimes', [])
        );

        $directory = (string) Config::get('app.base_path') . '/storage/uploads/categories';
        $filename = FileStorage::store($file, $directory);

        $this->categories->updateImage($categoryId, $filename);

        if (!empty($category['image'])) {
            FileStorage::delete($directory, (string) $category['image']);
        }

        return $filename;
    }
}
