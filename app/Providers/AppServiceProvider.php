<?php

namespace App\Providers;

use App\Logging\FlushAxiomLogs;
use App\Services\Facebook\FacebookPublisher;
use App\Services\Instagram\InstagramPublisher;
use App\Services\LinkedIn\LinkedInPublisher;
use App\Services\Publishing\PublisherRegistry;
use App\Services\TikTok\TikTokPublisher;
use App\Services\YouTube\YouTubePublisher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PublisherRegistry::class, fn (Application $app): PublisherRegistry => new PublisherRegistry([
            $app->make(LinkedInPublisher::class),
            $app->make(YouTubePublisher::class),
            $app->make(FacebookPublisher::class),
            $app->make(InstagramPublisher::class),
            $app->make(TikTokPublisher::class),
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->flushAxiomLogs();
    }

    /**
     * Send buffered Axiom log lines when a request, job, command or scheduled task ends.
     */
    protected function flushAxiomLogs(): void
    {
        Event::listen([
            JobProcessed::class,
            JobFailed::class,
            JobExceptionOccurred::class,
            CommandFinished::class,
            ScheduledTaskFinished::class,
            ScheduledTaskFailed::class,
        ], FlushAxiomLogs::class);

        $this->app->terminating(fn () => $this->app->make(FlushAxiomLogs::class)->handle());
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
