<?php

use App\Enums\LogFeature;
use App\Services\LogService;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

it('adds the feature and a trace id to the log context', function () {
    $traceId = LogService::startFeatureLogging(LogFeature::Scheduler, ['post_id' => 7]);

    expect(Context::get('feature'))->toBe('scheduler')
        ->and(Context::get('trace_id'))->toBe($traceId)
        ->and(Context::get('post_id'))->toBe(7);
});

it('clears context left over from an earlier run', function () {
    LogService::startFeatureLogging(LogFeature::Scheduler, ['command' => 'posts:dispatch-due']);
    $traceId = LogService::startFeatureLogging(LogFeature::Publishing, ['target_id' => 4]);

    expect(Context::get('command'))->toBeNull()
        ->and(Context::get('feature'))->toBe('publishing')
        ->and(Context::get('target_id'))->toBe(4)
        ->and(Context::get('trace_id'))->toBe($traceId);
});

it('writes info messages to the postloom channel', function () {
    Log::shouldReceive('channel')->once()->with('postloom')->andReturnSelf();
    Log::shouldReceive('info')->once()->with('Post published', ['post_id' => 7]);

    LogService::info('Post published', ['post_id' => 7]);
});

it('adds exception details to error messages', function () {
    $exception = new RuntimeException('LinkedIn returned 401');

    Log::shouldReceive('channel')->once()->with('postloom')->andReturnSelf();
    Log::shouldReceive('error')->once()->withArgs(function (string $message, array $context) {
        return $message === 'Publish failed'
            && $context['post_id'] === 7
            && $context['exception_class'] === RuntimeException::class
            && $context['exception_message'] === 'LinkedIn returned 401'
            && str_contains($context['exception_location'], 'LogServiceTest.php');
    });

    LogService::error('Publish failed', ['post_id' => 7], $exception);
});

it('writes json lines to the daily postloom log file', function () {
    $logFile = storage_path('logs/postloom-'.now()->format('Y-m-d').'.log');
    $marker = 'Log file check '.Str::uuid();

    LogService::startFeatureLogging(LogFeature::System);
    LogService::info($marker, ['step' => 'test']);

    $line = collect(file($logFile))->first(fn (string $line) => str_contains($line, $marker));
    $entry = json_decode($line, true);

    expect($entry['message'])->toBe($marker)
        ->and($entry['context']['step'])->toBe('test')
        ->and($entry['extra']['feature'] ?? $entry['context']['feature'] ?? null)->toBe('system');
});
