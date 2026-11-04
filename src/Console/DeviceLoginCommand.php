<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Console;

use Beziworld\Allegro\AllegroManager;
use Beziworld\Allegro\Exceptions\AllegroException;
use Illuminate\Console\Command;

/**
 * Connects a seller account from the terminal with the OAuth2 device flow.
 *
 * Prints the user code and the verification URL, waits until the seller
 * confirms it in the browser and stores the tokens for the account.
 */
final class DeviceLoginCommand extends Command
{
    /** @var string */
    protected $signature = 'allegro:device-login
        {account? : Configured account name (default account when omitted)}
        {--user= : Token store key of a seller connected at runtime, instead of an account}';

    /** @var string */
    protected $description = 'Connect an Allegro seller account using the device flow';

    public function handle(AllegroManager $allegro): int
    {
        $user = $this->option('user');
        $account = $this->argument('account');
        $client = is_string($user) && $user !== '' ? $allegro->forUser($user) : $allegro->account(is_string($account) ? $account : null);

        try {
            $code = $allegro->startDeviceFlow();

            $this->info(sprintf('Open %s and enter the code: %s', $code->verificationUri, $code->formattedUserCode()));

            if ($code->verificationUriComplete !== null) {
                $this->line('Direct link: ' . $code->verificationUriComplete);
            }

            $this->line('Waiting for confirmation...');
            $token = $client->waitForDeviceToken($code);
        } catch (AllegroException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Account "%s" connected; the access token is valid until %s.', $client->key(), $token->expiresAt->toDateTimeString()));

        return self::SUCCESS;
    }
}
