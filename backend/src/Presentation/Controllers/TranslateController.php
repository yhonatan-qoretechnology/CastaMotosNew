<?php

declare(strict_types=1);

namespace App\Presentation\Controllers;

use App\Application\UseCases\Catalog\TranslateTextUseCase;
use App\Application\Validation\Validator;
use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Response;

/**
 * Traducción ES → EN de los campos "_en" en /admin → Productos/Servicios
 * (nombre, descripción) — botón "Traducir" junto a cada campo, en vez de
 * escribirlo a mano. Protegida por manage-products (ver routes/api.php):
 * quien puede editar productos también puede editar servicios (mismos
 * roles, sección 002_permissions_seeder.php), así que un solo permiso
 * alcanza para las dos pantallas que usan este endpoint.
 */
final class TranslateController
{
    public function translate(Request $request): void
    {
        $data = Validator::make($request->input(), [
            'text' => 'required|max:5000',
        ])->validate();

        $translated = (new TranslateTextUseCase())->handle($data['text']);

        Response::success(['translated' => $translated]);
    }
}
