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
     * @param  array<string, mixed>  $context
     */
    public static function startFeatureLogging(LogFeature $feature, array $context = []): string
    {
        $traceId = (string) Str::uuid();

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
    public static function warning(string $message, array $context = []): void
    {
        Log::channel(self::CHANNEL)->warning($message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function error(string $message, array $context = [], ?Throwable $exception = null): void
    {
        if ($exception !== null) {
            $context = [
                ...$context,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'exception_location' => $exception->getFile().':'.$exception->getLine(),
            ];
        }

        Log::channel(self::CHANNEL)->error($message, $context);
    }
}
