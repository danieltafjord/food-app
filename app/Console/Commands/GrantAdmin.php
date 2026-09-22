<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('user:admin {email : The e-mail address of the user} {--revoke : Remove admin access instead of granting it}')]
#[Description('Grant (or revoke) access to the admin pages in the web app')]
class GrantAdmin extends Command
{
    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $isAdmin = ! $this->option('revoke');
        $user->forceFill(['is_admin' => $isAdmin])->save();

        $this->info($isAdmin ? "{$user->email} is now an admin." : "{$user->email} is no longer an admin.");

        return self::SUCCESS;
    }
}
