<?php

namespace App\Http\Responses;

use App\Http\Responses\Concerns\RedirectsToAccountHome;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Symfony\Component\HttpFoundation\Response;

class RegisterResponse implements RegisterResponseContract
{
    use RedirectsToAccountHome;

    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['two_factor' => false], 201);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('ui.auth.registered')]);

        return redirect()->to($this->accountHomeUrl($request));
    }
}
