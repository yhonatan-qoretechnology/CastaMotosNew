<?php

declare(strict_types=1);

namespace App\Presentation\Controllers;

use App\Application\UseCases\Auth\ChangePasswordUseCase;
use App\Application\UseCases\Auth\GoogleAuthUseCase;
use App\Application\UseCases\Auth\LoginUseCase;
use App\Application\UseCases\Auth\RegisterUserUseCase;
use App\Application\UseCases\Auth\RequestPasswordResetUseCase;
use App\Application\UseCases\Auth\ResendVerificationUseCase;
use App\Application\UseCases\Auth\ResetPasswordUseCase;
use App\Application\UseCases\Auth\VerifyEmailUseCase;
use App\Application\Validation\Validator;
use App\Domain\Entities\User;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Response;
use App\Infrastructure\Persistence\PdoCartRepository;
use App\Infrastructure\Persistence\PdoLoginHistoryRepository;
use App\Infrastructure\Persistence\PdoPasswordResetRepository;
use App\Infrastructure\Persistence\PdoUserRepository;

final class AuthController
{
    private PdoUserRepository $users;
    private PdoLoginHistoryRepository $loginHistory;
    private PdoPasswordResetRepository $passwordResets;
    private PdoCartRepository $carts;

    public function __construct()
    {
        $connection = Connection::get();
        $this->users = new PdoUserRepository($connection);
        $this->loginHistory = new PdoLoginHistoryRepository($connection);
        $this->passwordResets = new PdoPasswordResetRepository($connection);
        $this->carts = new PdoCartRepository($connection);
    }

    public function register(Request $request): void
    {
        $data = Validator::make($request->input(), [
            'name' => 'required|max:100',
            'last_name' => 'required|max:100',
            'email' => 'required|email|max:150',
            'phone' => 'required|max:30',
            'password' => 'required|min:8|confirmed',
            'terms_accepted' => 'accepted',
        ])->validate();

        $useCase = new RegisterUserUseCase($this->users);
        $result = $useCase->handle($data, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        Response::success([
            'user' => $result['user']->toArray(),
            'token' => $result['token'],
        ], 'Cuenta creada correctamente. Revisa tu correo para verificarla.', 201);
    }

    public function login(Request $request): void
    {
        $data = Validator::make($request->input(), [
            'email' => 'required|email',
            'password' => 'required',
        ])->validate();

        $useCase = new LoginUseCase($this->users, $this->loginHistory);
        $result = $useCase->handle(
            $data['email'],
            $data['password'],
            (bool) $request->input('remember', false),
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        );

        // Carrito de invitado → carrito del usuario (sección 18), si venía uno.
        $cartToken = (string) $request->header('X-Cart-Token', '');
        if ($cartToken !== '') {
            $this->carts->mergeGuestCartIntoUser($result['user']->id, $cartToken);
        }

        Response::success([
            'user' => $result['user']->toArray(),
            'token' => $result['token'],
        ], 'Sesión iniciada correctamente.');
    }

    /** "Continuar con Google" — un solo endpoint cubre login y registro (ver GoogleAuthUseCase). */
    public function google(Request $request): void
    {
        $data = Validator::make($request->input(), [
            'credential' => 'required',
        ])->validate();

        $useCase = new GoogleAuthUseCase($this->users, $this->loginHistory);
        $result = $useCase->handle(
            $data['credential'],
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        );

        // Mismo tratamiento de carrito de invitado que login() (sección 18).
        $cartToken = (string) $request->header('X-Cart-Token', '');
        if ($cartToken !== '') {
            $this->carts->mergeGuestCartIntoUser($result['user']->id, $cartToken);
        }

        Response::success([
            'user' => $result['user']->toArray(),
            'token' => $result['token'],
        ], 'Sesión iniciada correctamente.');
    }

    public function logout(Request $request): void
    {
        // JWT sin estado (Fase 2): el cierre de sesión real ocurre al descartar
        // el token en el cliente. Se deja el endpoint por consistencia de la API.
        Response::success(null, 'Sesión cerrada correctamente.');
    }

    public function me(Request $request): void
    {
        /** @var User $user */
        $user = $request->attribute('auth_user');

        Response::success($user->toArray());
    }

    public function verifyEmail(Request $request): void
    {
        $token = (string) $request->query('token', '');

        (new VerifyEmailUseCase($this->users))->handle($token);

        Response::success(null, 'Correo verificado correctamente.');
    }

    public function resendVerification(Request $request): void
    {
        $data = Validator::make($request->input(), ['email' => 'required|email'])->validate();

        (new ResendVerificationUseCase($this->users))->handle($data['email']);

        Response::success(null, 'Si el correo existe y no ha sido verificado, se envió un nuevo enlace.');
    }

    public function forgotPassword(Request $request): void
    {
        $data = Validator::make($request->input(), ['email' => 'required|email'])->validate();

        (new RequestPasswordResetUseCase($this->users, $this->passwordResets))->handle($data['email']);

        Response::success(null, 'Si el correo existe, se enviaron instrucciones para restablecer la contraseña.');
    }

    public function resetPassword(Request $request): void
    {
        $data = Validator::make($request->input(), [
            'token' => 'required',
            'password' => 'required|min:8|confirmed',
        ])->validate();

        (new ResetPasswordUseCase($this->users, $this->passwordResets))->handle($data['token'], $data['password']);

        Response::success(null, 'Contraseña restablecida correctamente. Ya puedes iniciar sesión.');
    }

    public function changePassword(Request $request): void
    {
        $data = Validator::make($request->input(), [
            'current_password' => 'required',
            'password' => 'required|min:8|confirmed',
        ])->validate();

        /** @var User $user */
        $user = $request->attribute('auth_user');

        (new ChangePasswordUseCase($this->users))->handle($user, $data['current_password'], $data['password']);

        Response::success(null, 'Contraseña actualizada correctamente.');
    }
}
