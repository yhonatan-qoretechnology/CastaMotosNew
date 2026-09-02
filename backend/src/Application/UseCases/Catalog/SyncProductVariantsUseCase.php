<?php

declare(strict_types=1);

namespace App\Application\UseCases\Catalog;

use App\Application\Support\ColorNameResolver;
use App\Domain\Repositories\ProductRepositoryInterface;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;

final class SyncProductVariantsUseCase
{
    public function __construct(private ProductRepositoryInterface $products)
    {
    }

    public function handle(int $productId, array $variants): void
    {
        if (!$this->products->exists($productId)) {
            throw new NotFoundException('Producto no encontrado.');
        }

        foreach ($variants as $index => $variant) {
            if (empty($variant['name'])) {
                throw new ValidationException('Los datos enviados no son válidos.', [
                    "variants.{$index}.name" => ['El nombre de la variante es obligatorio.'],
                ]);
            }
        }

        // Respaldo si no se cargó color a mano (ver ColorNameResolver): con
        // solo escribir "Verde" como nombre ya sale el círculo, sin obligar a
        // abrir la paleta — elegir un color a mano siempre pisa esto.
        $variants = array_map(static function (array $variant): array {
            if (empty($variant['color_hex']) && !empty($variant['name'])) {
                $resolved = ColorNameResolver::resolve($variant['name']);
                if ($resolved !== null) {
                    $variant['color_hex'] = $resolved;
                }
            }
            return $variant;
        }, $variants);

        $this->products->replaceVariants($productId, $variants);
    }
}
