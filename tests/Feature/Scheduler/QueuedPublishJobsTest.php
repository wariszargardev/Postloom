<?php

use App\Jobs\PublishPostTargetJob;
use App\Services\Publishing\QueuedPublishJobs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->queuedPublishJobs = app(QueuedPublishJobs::class);
});

it('reads the post targets that still have a publish job in the database queue', function () {
    config(['queue.default' => 'database']);

    PublishPostTargetJob::dispatch(41);
    PublishPostTargetJob::dispatch(42);

    expect($this->queuedPublishJobs->targetIds())->toEqualCanonicalizing([41, 42]);
});

it('reports none for queue drivers it cannot read', function () {
    config(['queue.default' => 'sync']);

    expect($this->queuedPublishJobs->targetIds())->toBe([]);
});
