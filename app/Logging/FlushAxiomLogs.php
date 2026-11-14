<?php

namespace App\Logging;

use App\Services\LogService;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Monolog\Logger as MonologLogger;
use Throwable;

/**
 * Sends the Axiom batch of the postloom channel. Runs after every request, queued job,
 * console command and scheduled task, so a long-running queue worker doesn't hold lines back.
 */
class FlushAxiomLogs
{
    public function handle(): void
    {
        try {
            $channel = Log::channel(LogService::CHANNEL);
        } catch (Throwable) {
            return;
        }

        if (! $channel instanceof Logger) {
            return;
        }

        $monolog = $channel->getLogger();

        if (! $monolog instanceof MonologLogger) {
            return;
        }

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof AxiomHandler) {
                $handler->flush();
            }
        }
    }
}
