<?php

namespace App\Http\Responses;

use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers exactly like a successful request, so the reset form cannot be used
 * to discover which email addresses have an account.
 */
class PasswordResetLinkFailedResponse implements FailedPasswordResetLinkRequestResponse
{
    public function toResponse($request): Response
    {
        return app(SuccessfulPasswordResetLinkRequestResponse::class, ['status' => Password::RESET_LINK_SENT])
            ->toResponse($request);
    }
}
