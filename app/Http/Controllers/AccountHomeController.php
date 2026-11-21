<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\AccountHome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AccountHomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        return redirect()->to(AccountHome::urlFor($user instanceof User ? $user : null));
    }
}
