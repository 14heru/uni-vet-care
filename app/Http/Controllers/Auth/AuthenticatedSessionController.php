<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AuthenticatedSessionController extends Controller
{
    private const MAX_LOGIN_ATTEMPTS = 5;

    private const LOGIN_DECAY_SECONDS = 60;

    public function __construct(private readonly AuditLogService $auditLog) {}

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = Str::transliterate(Str::lower($credentials['email']).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_LOGIN_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if (!Auth::attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'status' => 'active',
        ])) {
            RateLimiter::hit($throttleKey, self::LOGIN_DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        $authenticatedUser = Auth::guard('web')->user();

        if (! $authenticatedUser instanceof User) {
            $this->clearAuthenticationSession($request);

            return $this->auditFailureResponse();
        }

        try {
            $this->auditLog->log(
                $authenticatedUser,
                AuditLogService::ACTION_AUTH_LOGIN,
                AuditLogService::ENTITY_USER,
                (int) $authenticatedUser->getKey(),
            );
        } catch (Throwable $exception) {
            $this->clearAuthenticationSession($request);
            report($exception);

            return $this->auditFailureResponse();
        }

        return redirect()->intended('/');
    }

    public function destroy(Request $request): RedirectResponse|JsonResponse
    {
        $authenticatedUser = $request->user();

        $this->clearAuthenticationSession($request);

        if (! $authenticatedUser instanceof User) {
            return $this->auditFailureResponse();
        }

        try {
            $this->auditLog->log(
                $authenticatedUser,
                AuditLogService::ACTION_AUTH_LOGOUT,
                AuditLogService::ENTITY_USER,
                (int) $authenticatedUser->getKey(),
            );
        } catch (Throwable $exception) {
            $this->clearAuthenticationSession($request);
            report($exception);

            return $this->auditFailureResponse();
        }

        return redirect('/');
    }

    private function clearAuthenticationSession(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function auditFailureResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Unable to complete authentication. Please try again.',
        ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
    }
}
