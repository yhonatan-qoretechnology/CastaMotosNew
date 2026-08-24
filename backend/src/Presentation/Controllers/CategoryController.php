<?php

declare(strict_types=1);

namespace App\Presentation\Controllers;

use App\Application\Support\AccessChecker;
use App\Application\UseCases\Catalog\CreateCategoryUseCase;
use App\Application\UseCases\Catalog\UpdateCategoryUseCase;
use App\Application\UseCases\Catalog\UploadCategoryImageUseCase;
use App\Application\Validation\Validator;
use App\Domain\Entities\User;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Response;
use App\Infrastructure\Persistence\PdoCategoryRepository;
use App\Infrastructure\Persistence\PdoUserRepository;

final class CategoryController
{
    private PdoCategoryRepository $categories;
    private PdoUserRepository $users;

    private const RULES = [
        'name' => 'required|max:100',
        'parent_id' => 'integer',
        'description' => 'max:255',
        'image' => 'max:255',
        'status' => 'in:active,inactive',
        'sort_order' => 'integer',
    ];

    public function __construct()
    {
        $connection = Connection::get();
        $this->categories = new PdoCategoryRepository($connection);
        $this->users = new PdoUserRepository($connection);
    }

    public function index(Request $request): void
    {
        $includeInactive = $this->canManage($request);

        Response::success($this->categories->tree($includeInactive));
    }

    public function show(Request $request, string $slug): void
    {
        $includeInactive = $this->canManage($request);

        $category = $this->categories->findBySlug($slug, $includeInactive);
        if ($category === null) {
            throw new NotFoundException('Categoría no encontrada.');
        }

        Response::success($category);
    }

    public function store(Request $request): void
    {
        $data = Validator::make($request->input(), self::RULES)->validate();

        $id = (new CreateCategoryUseCase($this->categories))->handle($data);

        Response::success($this->categories->find($id), 'Categoría creada correctamente.', 201);
    }

    public function update(Request $request, string $id): void
    {
        $data = Validator::make($request->input(), self::RULES)->validate();

        (new UpdateCategoryUseCase($this->categories))->handle((int) $id, $data);

        Response::success($this->categories->find((int) $id), 'Categoría actualizada correctamente.');
    }

    public function uploadImage(Request $request, string $id): void
    {
        $file = $request->file('image');
        if ($file === null) {
            throw new ValidationException('No fue posible subir la imagen.', [
                'image' => ['Debes adjuntar un archivo con el campo "image".'],
            ]);
        }

        $filename = (new UploadCategoryImageUseCase($this->categories))->handle((int) $id, $file);

        Response::success(['image' => $filename], 'Imagen actualizada correctamente.');
    }

    public function destroy(Request $request, string $id): void
    {
        if (!$this->categories->exists((int) $id)) {
            throw new NotFoundException('Categoría no encontrada.');
        }

        $this->categories->delete((int) $id);

        Response::success(null, 'Categoría eliminada correctamente.');
    }

    private function canManage(Request $request): bool
    {
        /** @var User|null $user */
        $user = $request->attribute('auth_user');

        return AccessChecker::can($user, 'manage-categories', $this->users);
    }
}
