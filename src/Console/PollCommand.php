<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Console;

use Beziworld\Allegro\AllegroClient;
use Beziworld\Allegro\AllegroManager;
use Beziworld\Allegro\Exceptions\AllegroException;
use Beziworld\Allegro\Polling\OfferEventPoller;
use Beziworld\Allegro\Polling\OrderEventPoller;
use Illuminate\Console\Command;

/**
 * Shared implementation of the event journal polling commands.
 *
 * Accounts are chosen by name (configured accounts), with --all (every
 * configured account) or with --user (token store keys of sellers
 * connected at runtime). Without arguments the default account is polled.
 * A failure of one account is reported and does not stop the others.
 */
abstract class PollCommand extends Command
{
    abstract protected function poller(): OrderEventPoller|OfferEventPoller;

    abstract protected function label(): string;

    public function handle(AllegroManager $allegro): int
    {
        $failed = false;

        foreach ($this->clients($allegro) as $client) {
            try {
                $count = $this->poller()->poll($client);
                $this->line(sprintf('%s: %d new %s event(s).', $client->key(), $count, $this->label()));
            } catch (AllegroException $exception) {
                $failed = true;
                $this->error(sprintf('%s: %s', $client->key(), $exception->getMessage()));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<AllegroClient>
     */
    private function clients(AllegroManager $allegro): array
    {
        /** @var list<string> $names */
        $names = (array) $this->argument('account');
        /** @var list<string> $users */
        $users = (array) $this->option('user');

        if ($this->option('all') === true) {
            $names = $allegro->accountNames();
        }

        if ($names === [] && $users === []) {
            $names = [$allegro->getDefaultAccount()];
        }

        return [
            ...array_map($allegro->account(...), $names),
            ...array_map(static fn(string $key): AllegroClient => $allegro->forUser($key), $users),
        ];
    }
}
