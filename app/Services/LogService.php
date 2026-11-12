<?php

namespace App\Services;

use App\Enums\LogFeature;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class LogService
{
    public const CHANNEL = 'postloom';

    /**
     * Start a new traced run. Context left over from an earlier run is cleared first: queued jobs
     * receive the dispatcher's Context, which would otherwise mislabel the job's log lines.
     *
     * @param  array<string, mixed>  $context
     */
    public static function startFeatureLogging(LogFeature $feature, array $context = [], ?string $traceId = null): string
    {
        $traceId ??= (string) Str::uuid();

        Context::flush();
        Context::add([
            'feature' => $feature->value,
            'trace_id' => $traceId,
            ...$context,
        ]);

        return $traceId;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function info(string $message, array $context = []): void
    {
        Log::channel(self::CHANNEL)->info($message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function warning(string $message, array $context = [], ?Throwable $exception = null): void
    {
        Log::channel(self::CHANNEL)->warning($message, self::withException($context, $exception));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function error(string $message, array $context = [], ?Throwable $exception = null): void
    {
        Log::channel(self::CHANNEL)->error($message, self::withException($context, $exception));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function withException(array $context, ?Throwable $exception): array
    {
        if ($exception === null) {
            return $context;
        }

        return [
            ...$context,
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
            'exception_location' => $exception->getFile().':'.$exception->getLine(),
        ];
    }
}
