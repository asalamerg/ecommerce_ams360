<?php

namespace App\Http\Responses;

use App\Http\Responses\Concerns\RedirectsToAccountHome;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Symfony\Component\HttpFoundation\Response;

class VerifyEmailResponse implements VerifyEmailResponseContract
{
    use RedirectsToAccountHome;

    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('ui.auth.email_verified')]);

        return redirect()->to($this->accountHomeUrl($request));
    }
}
