<?php

namespace App\Http\Responses\Concerns;

use App\Models\User;
use App\Support\AccountHome;
use Illuminate\Http\Request;

trait RedirectsToAccountHome
{
    /**
     * Resolve the post-authentication URL, honouring the intended URL only when
     * this user can use it: a customer who bounced off /admin must not land on a 403.
     */
    protected function accountHomeUrl(Request $request): string
    {
        $user = $request->user();
        $home = AccountHome::urlFor($user instanceof User ? $user : null);

        if (! $request->hasSession()) {
            return $home;
        }

        $intended = $request->session()->pull('url.intended');

        if (! is_string($intended)) {
            return $home;
        }

        $path = (string) parse_url($intended, PHP_URL_PATH);
        $isAdminPath = $path === '/admin' || str_starts_with($path, '/admin/');
        $isStaff = $user instanceof User && $user->role->isStaff();

        return $isAdminPath && ! $isStaff ? $home : $intended;
    }
}
