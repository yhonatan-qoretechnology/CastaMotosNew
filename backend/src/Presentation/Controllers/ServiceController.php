<?php

declare(strict_types=1);

namespace App\Presentation\Controllers;

use App\Application\Support\AccessChecker;
use App\Application\UseCases\Catalog\CreateServiceUseCase;
use App\Application\UseCases\Catalog\UpdateServiceUseCase;
use App\Application\UseCases\Catalog\UploadServiceImageUseCase;
use App\Application\Validation\Validator;
use App\Domain\Entities\User;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Response;
use App\Infrastructure\Persistence\PdoCategoryRepository;
use App\Infrastructure\Persistence\PdoFavoriteRepository;
use App\Infrastructure\Persistence\PdoNotificationRepository;
use App\Infrastructure\Persistence\PdoServiceRepository;
use App\Infrastructure\Persistence\PdoUserRepository;

final class ServiceController
{
    private PdoServiceRepository $services;
    private PdoCategoryRepository $categories;
    private PdoUserRepository $users;
    private PdoFavoriteRepository $favorites;
    private PdoNotificationRepository $notifications;

    private const RULES = [
        'name' => 'required|max:200',
        // Traducción al inglés (selector de idioma, opcional): vacío = fallback
        // al texto en español — ver helpers.localized() en el frontend.
        'name_en' => 'max:200',
        'description_en' => 'max:5000',
        'category_id' => 'integer',
        'price' => 'required|numeric|gte:0',
        'duration_minutes' => 'integer|gte:0',
        'requires_scheduling' => 'boolean',
        'location' => 'max:255',
        'latitude' => 'numeric',
        'longitude' => 'numeric',
        'warranty' => 'max:150',
        'facebook_url' => 'max:255',
        'status' => 'in:draft,active,inactive',
    ];

    public function __construct()
    {
        $connection = Connection::get();
        $this->services = new PdoServiceRepository($connection);
        $this->categories = new PdoCategoryRepository($connection);
        $this->users = new PdoUserRepository($connection);
        $this->favorites = new PdoFavoriteRepository($connection);
        $this->notifications = new PdoNotificationRepository($connection);
    }

    public function index(Request $request): void
    {
        $result = $this->services->paginate($request->query(), $this->canManage($request));

        Response::success($result);
    }

    public function show(Request $request, string $slug): void
    {
        $service = $this->services->findBySlug($slug, $this->canManage($request));
        if ($service === null) {
            throw new NotFoundException('Servicio no encontrado.');
        }

        $service['canonical_url'] = rtrim((string) Config::get('app.url'), '/') . '/servicio/' . $service['slug'];

        /** @var User|null $user */
        $user = $request->attribute('auth_user');
        $service['is_favorite'] = $user !== null
            ? $this->favorites->isFavorite($user->id, 'service', (int) $service['id'])
            : false;

        Response::success($service);
    }

    /** Horas ya ocupadas de este servicio en una fecha (sección 12) — público, sin datos sensibles. */
    public function bookedTimes(Request $request, string $id): void
    {
        $data = Validator::make($request->query(), ['date' => 'required'])->validate();

        Response::success($this->services->bookedTimesForDate((int) $id, $data['date']));
    }

    public function store(Request $request): void
    {
        $data = Validator::make($request->input(), self::RULES)->validate();

        $id = (new CreateServiceUseCase($this->services, $this->categories))->handle($data);
        $service = $this->services->find($id);

        if (($service['status'] ?? null) === 'active') {
            $this->notifications->notifyAllUsers(
                'new_service',
                'Nuevo servicio',
                $service['name'] . ' ya está disponible en CASTAMOTO.',
                ['service_id' => $id, 'slug' => $service['slug']]
            );
        }

        Response::success($service, 'Servicio creado correctamente.', 201);
    }

    public function update(Request $request, string $id): void
    {
        $data = Validator::make($request->input(), self::RULES)->validate();

        (new UpdateServiceUseCase($this->services, $this->categories))->handle((int) $id, $data);

        Response::success($this->services->find((int) $id), 'Servicio actualizado correctamente.');
    }

    public function destroy(Request $request, string $id): void
    {
        if (!$this->services->exists((int) $id)) {
            throw new NotFoundException('Servicio no encontrado.');
        }

        $this->services->delete((int) $id);

        Response::success(null, 'Servicio eliminado correctamente.');
    }

    public function uploadImage(Request $request, string $id): void
    {
        $file = $request->file('image');
        if ($file === null) {
            throw new ValidationException('No fue posible subir la imagen.', [
                'image' => ['Debes adjuntar un archivo con el campo "image".'],
            ]);
        }

        $image = (new UploadServiceImageUseCase($this->services))->handle((int) $id, $file);

        Response::success($image, 'Imagen agregada correctamente.', 201);
    }

    public function deleteImage(Request $request, string $id, string $imageId): void
    {
        if (!$this->services->imageBelongsToService((int) $imageId, (int) $id)) {
            throw new NotFoundException('Imagen no encontrada.');
        }

        $this->services->deleteImage((int) $imageId);

        Response::success(null, 'Imagen eliminada correctamente.');
    }

    private function canManage(Request $request): bool
    {
        /** @var User|null $user */
        $user = $request->attribute('auth_user');

        return AccessChecker::can($user, 'manage-services', $this->users);
    }
}
