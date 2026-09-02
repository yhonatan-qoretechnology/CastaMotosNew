<?php

declare(strict_types=1);

namespace App\Application\Support;

/**
 * "Que aparezca un círculo con el color" con solo escribir el nombre —
 * antes había que además abrir la paleta y elegir el color a mano (ver
 * variantColorFieldMarkup en admin.js); si alguien se saltea ese paso, la
 * variante quedaba sin color_hex y la ficha caía al desplegable de texto en
 * vez del círculo. Este resuelve el nombre más común de colores en español
 * (sin tilde, sin mayúsculas) a un código hex — solo se usa como RESPALDO
 * cuando color_hex vino vacío; escribir el código a mano siempre lo pisa.
 */
final class ColorNameResolver
{
    private const COLORS = [
        'rojo' => '#E53935',
        'azul' => '#1E88E5',
        'verde' => '#43A047',
        'negro' => '#212121',
        'blanco' => '#FAFAFA',
        'amarillo' => '#FDD835',
        'naranja' => '#FB8C00',
        'naranjado' => '#FB8C00',
        'morado' => '#8E24AA',
        'violeta' => '#8E24AA',
        'purpura' => '#8E24AA',
        'lila' => '#AB47BC',
        'rosa' => '#EC407A',
        'rosado' => '#EC407A',
        'fucsia' => '#D81B60',
        'gris' => '#757575',
        'plomo' => '#757575',
        'cafe' => '#6D4C41',
        'marron' => '#6D4C41',
        'carmelita' => '#6D4C41',
        'celeste' => '#29B6F6',
        'turquesa' => '#00ACC1',
        'dorado' => '#C9A227',
        'oro' => '#C9A227',
        'plateado' => '#B0B0B0',
        'plata' => '#B0B0B0',
        'beige' => '#D8C3A5',
        'crema' => '#F0E6D2',
        'vino' => '#7B1E3A',
        'granate' => '#7B1E3A',
        'lima' => '#C0CA33',
        'khaki' => '#8C8258',
        'caqui' => '#8C8258',
    ];

    /** Devuelve el hex si "name" es un color reconocido, o null si no matchea nada. */
    public static function resolve(string $name): ?string
    {
        $normalized = strtolower(trim($name));
        // Sin tildes: "café", "marrón" también matchean sus versiones sin acento.
        $normalized = strtr($normalized, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);

        return self::COLORS[$normalized] ?? null;
    }
}
