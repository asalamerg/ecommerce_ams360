<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\CartManager;
use Illuminate\Auth\Events\Login;

class MergeGuestCart
{
    public function __construct(private CartManager $carts)
    {
        //
    }

    public function handle(Login $event): void
    {
        if ($event->user instanceof User) {
            $this->carts->mergeGuestCart($event->user);
        }
    }
}
