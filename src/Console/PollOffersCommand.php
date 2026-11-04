<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Console;

use Beziworld\Allegro\Polling\OfferEventPoller;

/**
 * Polls the offer event journal and dispatches OfferEventReceived; meant for the scheduler.
 */
final class PollOffersCommand extends PollCommand
{
    /** @var string */
    protected $signature = 'allegro:poll-offers
        {account?* : Configured account names (default account when omitted)}
        {--all : Poll every configured account}
        {--user=* : Token store keys of sellers connected at runtime}';

    /** @var string */
    protected $description = 'Read new Allegro offer events and dispatch them as Laravel events';

    protected function poller(): OfferEventPoller
    {
        return $this->laravel->make(OfferEventPoller::class);
    }

    protected function label(): string
    {
        return 'offer';
    }
}
