<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Fortify;

/**
 * Credential check shared by the customer and staff sign-in forms. Every refusal
 * returns null, so all of them produce the same generic "auth.failed" message and
 * nobody can learn whether an account exists, is disabled, or lacks staff access.
 */
class AuthenticateStoreUser
{
    /**
     * A bcrypt hash of a discarded random value. Checking against it when the
     * account does not exist keeps both paths equally slow.
     */
    private const string DUMMY_HASH = '$2y$12$BE3N0wRdh9rO8i8DgMiNrefNWf9F6bKKMi3pBeOeK9C6xpOvDAFjq';

    public function __invoke(Request $request): ?User
    {
        $user = User::query()->where(Fortify::username(), $request->input(Fortify::username()))->first();
        $passwordMatches = Hash::check((string) $request->input('password'), $user->password ?? self::DUMMY_HASH);

        if (! $user instanceof User || ! $passwordMatches || ! $user->is_active) {
            return null;
        }

        if ($request->routeIs('admin.login.store') && ! $user->role->isStaff()) {
            return null;
        }

        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $request->input('password')])->save();
        }

        return $user;
    }
}
