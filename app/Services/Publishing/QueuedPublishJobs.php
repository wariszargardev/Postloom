<?php

namespace App\Services\Publishing;

use App\Jobs\PublishPostTargetJob;
use Illuminate\Support\Facades\DB;

/**
 * Which post targets still have a publish job in the database queue, waiting (also a retry waiting for its delay)
 * or running. Other queue drivers can't be read this way, so they report none.
 */
class QueuedPublishJobs
{
    /**
     * @return array<int, int>
     */
    public function targetIds(): array
    {
        $connection = (string) config('queue.default');
        $settings = (array) config("queue.connections.{$connection}", []);

        if (($settings['driver'] ?? null) !== 'database') {
            return [];
        }

        return DB::connection($settings['connection'] ?? null)
            ->table($settings['table'] ?? 'jobs')
            ->where('payload', 'like', '%'.class_basename(PublishPostTargetJob::class).'%')
            ->pluck('payload')
            ->map(fn (string $payload): ?int => $this->targetIdFrom($payload))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function targetIdFrom(string $payload): ?int
    {
        $command = json_decode($payload, true)['data']['command'] ?? null;

        if (! is_string($command) || preg_match('/"postTargetId";i:(\d+);/', $command, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
