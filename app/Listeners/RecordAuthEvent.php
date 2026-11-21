<?php

namespace App\Listeners;

use App\Models\AuthEvent;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Fortify;

/**
 * Keeps a security trail of sign-in activity. It stores who, what and where from,
 * and never the credentials carried by the Failed event.
 */
class RecordAuthEvent
{
    public function __construct(private Request $request)
    {
        //
    }

    public function handleLogin(Login $event): void
    {
        $this->record('login', $this->userId($event->user));
    }

    public function handleLogout(Logout $event): void
    {
        $this->record('logout', $this->userId($event->user));
    }

    public function handleFailed(Failed $event): void
    {
        $email = $event->credentials[Fortify::username()] ?? null;

        $this->record('failed', $event->user !== null
            ? $this->userId($event->user)
            : (is_string($email) ? User::query()->where('email', $email)->value('id') : null));
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $this->record('password_reset', $this->userId($event->user));
    }

    public function handleVerified(Verified $event): void
    {
        $this->record('email_verified', $event->user instanceof Authenticatable ? $this->userId($event->user) : null);
    }

    public function handleTwoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        $this->record('two_factor_failed', $this->userId($event->user));
    }

    private function userId(?Authenticatable $user): ?int
    {
        $id = $user?->getAuthIdentifier();

        return is_int($id) ? $id : null;
    }

    private function record(string $event, ?int $userId): void
    {
        AuthEvent::query()->create([
            'user_id' => $userId,
            'event' => $event,
            'ip_address' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 250, ''),
            'created_at' => now(),
        ]);
    }
}
