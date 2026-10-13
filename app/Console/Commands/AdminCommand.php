<?php

namespace App\Console\Commands;

use App\Models\PlatformAdmin;
use Illuminate\Console\Command;

/** Creates or updates a platform admin (admin.<domain>) from the server shell. */
class AdminCommand extends Command
{
    protected $signature = 'egmenu:admin {email : Login email} {--name=Admin : Display name} {--password= : Password (prompted when omitted)}';

    protected $description = 'Create or update a platform admin';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email');

            return self::FAILURE;
        }

        $password = $this->option('password') ?: $this->secret('Password (min 10 characters)');
        if (strlen((string) $password) < 10) {
            $this->error('Password must be at least 10 characters');

            return self::FAILURE;
        }

        $admin = PlatformAdmin::query()->where('email', $email)->first();
        $data = ['name' => $this->option('name'), 'password' => $password];
        $admin ? $admin->update($data) : PlatformAdmin::query()->create($data + ['email' => $email]);

        $this->info(($admin ? 'Updated' : 'Created')." platform admin $email");

        return self::SUCCESS;
    }
}
