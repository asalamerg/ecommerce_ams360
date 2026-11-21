<?php

namespace App\Http\Responses;

use App\Http\Responses\Concerns\RedirectsToAccountHome;
use Illuminate\Http\JsonResponse;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class PasskeyLoginResponse implements PasskeyLoginResponseContract
{
    use RedirectsToAccountHome;

    public function toResponse($request): Response
    {
        $redirect = $this->accountHomeUrl($request);

        return $request->wantsJson()
            ? new JsonResponse(['redirect' => $redirect], 200)
            : redirect()->to($redirect);
    }
}
