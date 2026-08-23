<?php

declare(strict_types=1);

namespace App\Presentation\Controllers;

use App\Exceptions\NotFoundException;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Http\Request;

/**
 * Sirve archivos subidos (avatares, imágenes de producto/servicio) que viven
 * fuera del docroot público (sección 44: "separar almacenamiento de archivos
 * del código ejecutable"). El nombre de archivo se valida con una lista
 * blanca estricta para evitar path traversal (../../) y solo se permiten
 * las extensiones de imagen conocidas.
 */
final class MediaController
{
    private const ALLOWED_MIME_BY_EXTENSION = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        // .svg solo lo generan scripts del propio servidor (ej. DemoDataSeeder) para
        // imágenes de marcador de posición; UploadedFileValidator NUNCA permite subir
        // SVG por HTTP (riesgo de XSS), así que ningún avatar/imagen de usuario puede
        // llegar a ser .svg — se sirve siempre vía <img src>, que no ejecuta scripts
        // embebidos en el SVG (a diferencia de <object>/<iframe>).
        'svg' => 'image/svg+xml',
    ];

    public function avatar(Request $request, string $filename): void
    {
        $this->serveFrom('avatars', $filename);
    }

    public function productImage(Request $request, string $filename): void
    {
        $this->serveFrom('products', $filename);
    }

    public function serviceImage(Request $request, string $filename): void
    {
        $this->serveFrom('services', $filename);
    }

    public function siteLogo(Request $request, string $filename): void
    {
        $this->serveFrom('settings', $filename);
    }

    private function serveFrom(string $subdirectory, string $filename): void
    {
        if (!preg_match('/^[a-f0-9]{32}\.(jpg|jpeg|png|webp|svg)$/i', $filename)) {
            throw new NotFoundException('Archivo no encontrado.');
        }

        $path = (string) Config::get('app.base_path') . "/storage/uploads/{$subdirectory}/" . $filename;

        if (!is_file($path)) {
            throw new NotFoundException('Archivo no encontrado.');
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        header('Content-Type: ' . self::ALLOWED_MIME_BY_EXTENSION[$extension]);
        header('Cache-Control: public, max-age=86400');
        readfile($path);
    }
}
