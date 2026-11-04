<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Polling;

use Beziworld\Allegro\AllegroClient;
use Beziworld\Allegro\AllegroManager;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Reads an Allegro event journal with an id cursor and dispatches every new event as a Laravel event.
 *
 * The cursor (the id of the last dispatched event) is stored per account
 * in the cache store of the package and saved after each event, so a run
 * that fails half-way resumes where it stopped. A cache lock prevents two
 * runs for the same account from overlapping; a run that cannot acquire
 * the lock returns immediately.
 *
 * @template TEvent of object
 */
abstract class EventPoller
{
    public function __construct(
        protected readonly AllegroManager $manager,
        protected readonly Dispatcher $events,
        protected readonly Config $config,
    ) {}

    /**
     * Polls the journal of one account and returns the number of dispatched events.
     *
     * @param AllegroClient|string|null $account Client, configured account name, or null for the default account.
     */
    public function poll(AllegroClient|string|null $account = null): int
    {
        $client = $account instanceof AllegroClient ? $account : $this->manager->account($account);
        $store = $client->cache()->getStore();

        if (! $store instanceof LockProvider) {
            return $this->run($client);
        }

        $lock = $store->lock($client->scopedKey('lock:poll:' . $this->name()), 300);

        if ($lock->get() === false) {
            return 0;
        }

        try {
            return $this->run($client);
        } finally {
            $lock->release();
        }
    }

    /**
     * Id of the last dispatched event of the account, or null before the first run.
     */
    public function cursor(AllegroClient $client): ?string
    {
        $cursor = $client->cache()->get($this->cursorKey($client));

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    /**
     * Moves the cursor, for example to replay events; null restarts from the configured starting point.
     */
    public function setCursor(AllegroClient $client, ?string $eventId): void
    {
        if ($eventId === null) {
            $client->cache()->forget($this->cursorKey($client));

            return;
        }

        $client->cache()->forever($this->cursorKey($client), $eventId);
    }

    /**
     * Short name of the journal, used in cache keys.
     */
    abstract protected function name(): string;

    /**
     * Fetches the events after the cursor.
     *
     * @return list<TEvent>
     */
    abstract protected function fetch(AllegroClient $client, ?string $from, int $limit): array;

    /**
     * @param TEvent $event
     */
    abstract protected function eventId(object $event): string;

    /**
     * @param TEvent $event
     */
    abstract protected function dispatchEvent(AllegroClient $client, object $event): void;

    /**
     * Cursor used when the account has none yet; null reads the journal from its oldest event.
     */
    protected function initialCursor(AllegroClient $client): ?string
    {
        return null;
    }

    private function run(AllegroClient $client): int
    {
        $limit = $this->option('limit', 100, 1000);
        $maxPages = $this->option('max_pages', 10, 1000);
        $cursor = $this->cursor($client);

        if ($cursor === null) {
            $cursor = $this->initialCursor($client);

            if ($cursor !== null) {
                $this->setCursor($client, $cursor);
            }
        }

        $dispatched = 0;

        for ($page = 1; $page <= $maxPages; $page++) {
            $events = $this->fetch($client, $cursor, $limit);

            foreach ($events as $event) {
                $id = $this->eventId($event);

                if ($id === '' || $id === $cursor) {
                    continue;
                }

                $this->dispatchEvent($client, $event);
                $this->setCursor($client, $cursor = $id);
                $dispatched++;
            }

            if (count($events) < $limit) {
                break;
            }
        }

        return $dispatched;
    }

    private function option(string $key, int $default, int $max): int
    {
        $value = $this->config->get('allegro.events.' . $key);

        return is_numeric($value) ? max(1, min($max, (int) $value)) : $default;
    }

    private function cursorKey(AllegroClient $client): string
    {
        return $client->scopedKey('cursor:' . $this->name());
    }
}
