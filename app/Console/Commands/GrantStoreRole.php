<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;

class GrantStoreRole extends Command
{
    protected $signature = 'store:grant-role {email} {role : owner, administrator, product_manager, inventory_manager, order_manager, support_agent, or customer}';

    protected $description = 'Assign a store role to an existing verified user';

    public function handle(): int
    {
        $role = UserRole::tryFrom((string) $this->argument('role'));

        if (! $role) {
            $this->error('The requested role is not supported.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No user exists with that email address.');

            return self::FAILURE;
        }

        if (! $user->hasVerifiedEmail()) {
            $this->error('Verify the user email before granting a store role.');

            return self::FAILURE;
        }

        $user->forceFill(['role' => $role])->save();
        $this->info("Assigned the {$role->value} role to {$user->email}.");

        return self::SUCCESS;
    }
}
