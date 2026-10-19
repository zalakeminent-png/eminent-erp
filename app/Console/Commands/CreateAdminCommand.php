<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogger;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Secure bootstrap of the first administrator. Run on the server only; the
 * password is entered interactively (hidden) and never stored in plaintext.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'erp:create-admin
        {--email= : Administrator email address}
        {--first-name= : First name}
        {--last-name= : Last name}
        {--force : Allow creating an additional administrator when one already exists}';

    protected $description = 'Create an ERP administrator account (initial setup)';

    public function handle(ActivityLogger $activity): int
    {
        if (! Role::primaryAdmin() || ! Company::query()->exists()) {
            $this->components->info('Seeding company, roles and permissions…');
            $this->call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);
        }

        $adminRole = Role::primaryAdmin();

        if ($adminRole->users()->where('is_active', true)->exists() && ! $this->option('force')) {
            $this->components->error('An active administrator already exists. Create further users from the application, or re-run with --force.');

            return self::FAILURE;
        }

        $data = [
            'email' => Str::lower(trim($this->option('email') ?? $this->ask('Email address'))),
            'first_name' => $this->option('first-name') ?? $this->ask('First name'),
            'last_name' => $this->option('last-name') ?? $this->ask('Last name'),
            'password' => $this->secret('Password (min 10 chars, upper/lower case, number, symbol)'),
        ];
        $data['password_confirmation'] = $this->secret('Confirm password');

        $validator = Validator::make($data, [
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($data, $adminRole) {
            $user = new User([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);
            $user->company_id = Company::current()?->id;
            $user->is_active = true;
            $user->email_verified_at = now();
            $user->save();
            $user->assignRole($adminRole);

            return $user;
        });

        $activity->log('user.created', $user, ['email' => $user->email, 'roles' => [$adminRole->name], 'source' => 'console'], 'Administrator created via console', actor: null);

        $this->components->info("Administrator {$user->email} created. You can now sign in.");

        return self::SUCCESS;
    }
}
