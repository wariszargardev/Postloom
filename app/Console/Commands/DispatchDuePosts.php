<?php

namespace App\Console\Commands;

use App\Services\Publishing\PostDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('posts:dispatch-due')]
#[Description('Send scheduled posts whose time has come to the publish queue')]
class DispatchDuePosts extends Command
{
    public function handle(PostDispatcher $postDispatcher): int
    {
        $summary = $postDispatcher->dispatchDue();

        if (! $summary['enabled']) {
            $this->warn('Publishing is turned off (POSTLOOM_PUBLISHING_ENABLED=false). Due posts stay scheduled.');

            return self::SUCCESS;
        }

        $this->info("Dispatched: {$summary['dispatched']}. Missed: {$summary['missed']}. Timed out: {$summary['timed_out']}.");

        return self::SUCCESS;
    }
}
