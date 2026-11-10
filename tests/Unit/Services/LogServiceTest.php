<?php

use App\Enums\LogFeature;
use App\Services\LogService;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

it('adds the feature and a trace id to the log context', function () {
    $traceId = LogService::startFeatureLogging(LogFeature::Scheduler, ['post_id' => 7]);

    expect(Context::get('feature'))->toBe('scheduler')
        ->and(Context::get('trace_id'))->toBe($traceId)
        ->and(Context::get('post_id'))->toBe(7);
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
    @unlink($logFile);

    LogService::startFeatureLogging(LogFeature::System);
    LogService::info('Log file check', ['step' => 'test']);

    $lastLine = collect(file($logFile))->last();
    $entry = json_decode($lastLine, true);

    expect($entry['message'])->toBe('Log file check')
        ->and($entry['context']['step'])->toBe('test')
        ->and($entry['extra']['feature'] ?? $entry['context']['feature'] ?? null)->toBe('system');
});
