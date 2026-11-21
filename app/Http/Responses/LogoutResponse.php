<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Symfony\Component\HttpFoundation\Response;

class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        $previousPath = (string) parse_url((string) $request->headers->get('referer'), PHP_URL_PATH);

        return str_starts_with($previousPath, '/admin')
            ? to_route('admin.login')
            : to_route('storefront.home', ['locale' => App::getLocale()]);
    }
}
