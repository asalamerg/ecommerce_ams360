<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Marks the notification read and opens what it is about. Only links into
     * this site are followed, whatever the stored data says.
     */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $entry = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $entry->markAsRead();

        $path = parse_url((string) ($entry->data['url'] ?? ''), PHP_URL_PATH);

        return redirect(is_string($path) && str_starts_with($path, '/admin') ? $path : '/admin/dashboard');
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
