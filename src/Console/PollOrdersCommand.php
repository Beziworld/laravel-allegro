<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Console;

use Beziworld\Allegro\Polling\OrderEventPoller;

/**
 * Polls the order event journal and dispatches OrderEventReceived; meant for the scheduler.
 */
final class PollOrdersCommand extends PollCommand
{
    /** @var string */
    protected $signature = 'allegro:poll-orders
        {account?* : Configured account names (default account when omitted)}
        {--all : Poll every configured account}
        {--user=* : Token store keys of sellers connected at runtime}';

    /** @var string */
    protected $description = 'Read new Allegro order events and dispatch them as Laravel events';

    protected function poller(): OrderEventPoller
    {
        return $this->laravel->make(OrderEventPoller::class);
    }

    protected function label(): string
    {
        return 'order';
    }
}
