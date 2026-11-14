<?php

use App\Enums\LogFeature;
use App\Logging\AxiomHandler;
use App\Services\LogService;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Logger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

uses(TestCase::class);

const AXIOM_INGEST_URL = 'https://api.axiom.co/v1/datasets/postloom-local/ingest';

function axiomLogger(array $overrides = []): array
{
    $handler = new AxiomHandler(...[
        'token' => 'xaat-test-token',
        'dataset' => 'postloom-local',
        'batchSize' => 3,
        'environment' => 'local',
        ...$overrides,
    ]);

    return [new Logger('postloom', [$handler]), $handler];
}

beforeEach(function () {
    Http::preventStrayRequests();
});

it('keeps lines until the batch is full, then sends them in one request', function () {
    Http::fake([AXIOM_INGEST_URL => Http::response(['ingested' => 3])]);
    [$logger, $handler] = axiomLogger();

    $logger->info('First');
    $logger->info('Second');

    Http::assertNothingSent();
    expect($handler->pending())->toHaveCount(2);

    $logger->warning('Third');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === AXIOM_INGEST_URL
        && $request->hasHeader('Authorization', 'Bearer xaat-test-token')
        && collect($request->data())->pluck('message')->all() === ['First', 'Second', 'Third']);
    expect($handler->pending())->toBe([]);
});

it('sends each line with time, level, message, context, extra and environment', function () {
    Http::fake([AXIOM_INGEST_URL => Http::response()]);
    [$logger, $handler] = axiomLogger();

    $logger->pushProcessor(function ($record) {
        $record->extra['feature'] = 'posts';
        $record->extra['trace_id'] = 'trace-1';

        return $record;
    });
    $logger->error('Post could not be scheduled', ['post_id' => 7]);
    $handler->flush();

    Http::assertSent(function (Request $request) {
        $event = $request->data()[0];

        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}[+-]\d{2}:\d{2}$/', $event['_time']) === 1
            && $event['level'] === 'error'
            && $event['message'] === 'Post could not be scheduled'
            && $event['context'] === ['post_id' => 7]
            && $event['extra'] === ['feature' => 'posts', 'trace_id' => 'trace-1']
            && $event['environment'] === 'local'
            && $event['service'] === 'postloom';
    });
});

it('never sends tokens, secrets or passwords', function () {
    Http::fake([AXIOM_INGEST_URL => Http::response()]);
    [$logger, $handler] = axiomLogger();

    $logger->info('Token refreshed', [
        'account_id' => 4,
        'access_token' => 'abc',
        'response' => ['refresh_token' => 'def', 'client_secret' => 'ghi', 'expires_in' => 3600],
        'password' => 'hunter2',
    ]);
    $handler->flush();

    Http::assertSent(fn (Request $request) => $request->data()[0]['context'] === [
        'account_id' => 4,
        'access_token' => '[redacted]',
        'response' => ['refresh_token' => '[redacted]', 'client_secret' => '[redacted]', 'expires_in' => 3600],
        'password' => '[redacted]',
    ]);
});

it('sends nothing when the token or dataset is missing', function (array $overrides) {
    Http::fake();
    [$logger, $handler] = axiomLogger($overrides);

    $logger->info('Hello');
    $logger->info('Hello');
    $logger->info('Hello');
    $handler->flush();

    expect($handler->isConfigured())->toBeFalse()
        ->and($handler->pending())->toBe([]);
    Http::assertNothingSent();
})->with([
    'no token' => [['token' => null]],
    'no dataset' => [['dataset' => '']],
]);

it('keeps the app running when Axiom is down or refuses the lines', function ($response) {
    Http::fake([AXIOM_INGEST_URL => $response]);
    [$logger, $handler] = axiomLogger();

    $logger->info('Hello');
    $handler->flush();

    expect($handler->pending())->toBe([]);
})->with([
    'server error' => fn () => Http::response('down', 503),
    'bad token' => fn () => Http::response('unauthorized', 401),
    'no connection' => fn () => fn () => throw new ConnectionException('Connection timed out'),
]);

it('sends LogService lines when a command finishes', function () {
    Http::fake([AXIOM_INGEST_URL => Http::response()]);
    config([
        'logging.channels.postloom.channels' => ['postloom_testing', 'postloom_axiom'],
        'logging.channels.postloom_axiom.handler_with.token' => 'xaat-test-token',
        'logging.channels.postloom_axiom.handler_with.dataset' => 'postloom-local',
    ]);
    Log::forgetChannel(LogService::CHANNEL);

    LogService::startFeatureLogging(LogFeature::Scheduler, ['command' => 'posts:dispatch-due']);
    LogService::info('Due posts dispatched', ['count' => 2]);

    Http::assertNothingSent();

    event(new CommandFinished('posts:dispatch-due', new ArrayInput([]), new NullOutput, 0));

    Http::assertSent(fn (Request $request) => $request->data()[0]['message'] === 'Due posts dispatched'
        && $request->data()[0]['extra']['feature'] === 'scheduler'
        && $request->data()[0]['extra']['command'] === 'posts:dispatch-due');

    Log::forgetChannel(LogService::CHANNEL);
});
