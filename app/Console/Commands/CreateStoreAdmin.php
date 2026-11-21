<?php

namespace App\Console\Commands;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateStoreAdmin extends Command
{
    use PasswordValidationRules, ProfileValidationRules;

    protected $signature = 'store:create-admin {email} {--role=owner : A staff role such as owner or administrator}';

    protected $description = 'Create a verified staff account, prompting for its name and password';

    public function handle(): int
    {
        $role = UserRole::tryFrom((string) $this->option('role'));

        if ($role === null || ! $role->isStaff()) {
            $this->error('The role must be a staff role.');

            return self::FAILURE;
        }

        $input = [
            'email' => (string) $this->argument('email'),
            'name' => (string) $this->ask('Name'),
            'password' => (string) $this->secret('Password'),
            'password_confirmation' => (string) $this->secret('Confirm password'),
        ];

        $validator = Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = new User([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
        $user->forceFill(['role' => $role, 'email_verified_at' => now()])->save();

        $this->info("Created the {$role->value} account {$user->email}.");

        return self::SUCCESS;
    }
}
