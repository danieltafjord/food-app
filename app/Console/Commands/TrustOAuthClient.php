<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Passport\Passport;

#[Signature('oauth:trust-client {client : The id of the OAuth client}')]
#[Description('Mark an OAuth client as trusted: it skips the consent screen and receives full mobile-API tokens')]
class TrustOAuthClient extends Command
{
    public function handle(): int
    {
        $client = Passport::client()->newQuery()->find($this->argument('client'));

        if (! $client) {
            $this->error('Client not found.');

            return self::FAILURE;
        }

        $client->forceFill(['trusted' => true])->save();

        $this->info("Client [{$client->name}] is now trusted.");

        return self::SUCCESS;
    }
}
