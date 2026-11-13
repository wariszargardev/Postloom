<?php

namespace App\Providers;

use App\Services\Facebook\FacebookPublisher;
use App\Services\Instagram\InstagramPublisher;
use App\Services\LinkedIn\LinkedInPublisher;
use App\Services\Publishing\PublisherRegistry;
use App\Services\TikTok\TikTokPublisher;
use App\Services\YouTube\YouTubePublisher;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
