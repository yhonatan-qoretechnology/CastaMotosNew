<?php

declare(strict_types=1);

namespace App\Presentation\Controllers;

use App\Application\Support\AccessChecker;
use App\Application\Support\SlugGenerator;
use App\Application\UseCases\Catalog\UploadBrandLogoUseCase;
use App\Application\Validation\Validator;
use App\Domain\Entities\User;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Response;
use App\Infrastructure\Persistence\PdoBrandRepository;
use App\Infrastructure\Persistence\PdoUserRepository;

final class BrandController
{
    private PdoBrandRepository $brands;
    private PdoUserRepository $users;

    private const RULES = [
        'name' => 'required|max:100',
        'logo' => 'max:255',
        'status' => 'in:active,inactive',
    ];

    public function __construct()
    {
        $connection = Connection::get();
        $this->brands = new PdoBrandRepository($connection);
        $this->users = new PdoUserRepository($connection);
    }

    public function index(Request $request): void
    {
        /** @var User|null $user */
        $user = $request->attribute('auth_user');
        $includeInactive = AccessChecker::can($user, 'manage-brands', $this->users);

        Response::success($this->brands->list($includeInactive));
    }

    public function store(Request $request): void
    {
        $data = Validator::make($request->input(), self::RULES)->validate();
        $data['slug'] = SlugGenerator::unique($data['name'], fn (string $slug) => $this->brands->existsBySlug($slug));

        $id = $this->brands->create($data);

        Response::success($this->brands->find($id), 'Marca creada correctamente.', 201);
    }

    public function update(Request $request, string $id): void
    {
        if (!$this->brands->exists((int) $id)) {
            throw new NotFoundException('Marca no encontrada.');
        }

        $data = Validator::make($request->input(), self::RULES)->validate();
        $data['slug'] = SlugGenerator::unique(
            $data['name'],
            fn (string $slug) => $this->brands->existsBySlug($slug, (int) $id)
        );

        $this->brands->update((int) $id, $data);

        Response::success($this->brands->find((int) $id), 'Marca actualizada correctamente.');
    }

    public function uploadLogo(Request $request, string $id): void
    {
        $file = $request->file('logo');
        if ($file === null) {
            throw new ValidationException('No fue posible subir el logo.', [
                'logo' => ['Debes adjuntar un archivo con el campo "logo".'],
            ]);
        }

        $filename = (new UploadBrandLogoUseCase($this->brands))->handle((int) $id, $file);

        Response::success(['logo' => $filename], 'Logo actualizado correctamente.');
    }

    public function destroy(Request $request, string $id): void
    {
        if (!$this->brands->exists((int) $id)) {
            throw new NotFoundException('Marca no encontrada.');
        }

        $this->brands->delete((int) $id);

        Response::success(null, 'Marca eliminada correctamente.');
    }
}
