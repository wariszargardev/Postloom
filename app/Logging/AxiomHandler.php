<?php

namespace App\Logging;

use Illuminate\Support\Facades\Http;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Keeps log lines in memory and sends them to Axiom in batches: when the batch is full,
 * at the end of a request, job, command or scheduled task, and when PHP shuts down.
 * Sending never throws; a batch that can't be sent is dropped and noted in the PHP error log.
 */
class AxiomHandler extends AbstractProcessingHandler
{
    private const REDACTED = '[redacted]';

    private const SECRET_KEY_PATTERN = '/token|secret|password|authorization|cookie|api[_-]?key|client[_-]?key|code[_-]?verifier/i';

    /** @var list<array<string, mixed>> */
    private array $batch = [];

    private bool $shutdownRegistered = false;

    private NormalizerFormatter $normalizer;

    public function __construct(
        private readonly ?string $token,
        private readonly ?string $dataset,
        private readonly string $url = 'https://api.axiom.co',
        private readonly int $batchSize = 50,
        private readonly string $environment = 'production',
        private readonly int $timeoutSeconds = 3,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);

        $this->normalizer = new NormalizerFormatter('Y-m-d\TH:i:s.uP');
    }

    public function isConfigured(): bool
    {
        return filled($this->token) && filled($this->dataset);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pending(): array
    {
        return $this->batch;
    }

    public function flush(): void
    {
        if ($this->batch === []) {
            return;
        }

        $events = $this->batch;
        $this->batch = [];

        try {
            $response = Http::withToken((string) $this->token)
                ->acceptJson()
                ->timeout($this->timeoutSeconds)
                ->post($this->ingestUrl(), $events);

            if ($response->failed()) {
                error_log(sprintf('Postloom: Axiom refused %d log lines (HTTP %d).', count($events), $response->status()));
            }
        } catch (Throwable $exception) {
            error_log(sprintf('Postloom: could not send %d log lines to Axiom: %s', count($events), $exception->getMessage()));
        }
    }

    public function close(): void
    {
        $this->flush();

        parent::close();
    }

    protected function write(LogRecord $record): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $this->registerShutdownFlush();

        $this->batch[] = $this->toEvent($record);

        if (count($this->batch) >= max(1, $this->batchSize)) {
            $this->flush();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function toEvent(LogRecord $record): array
    {
        /** @var array<string, mixed> $normalized */
        $normalized = $this->normalizer->format($record);

        return [
            '_time' => $normalized['datetime'],
            'level' => strtolower($record->level->getName()),
            'level_name' => $record->level->getName(),
            'message' => $record->message,
            'context' => $this->redact((array) ($normalized['context'] ?? [])),
            'extra' => $this->redact((array) ($normalized['extra'] ?? [])),
            'channel' => $record->channel,
            'environment' => $this->environment,
            'service' => 'postloom',
        ];
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY_PATTERN, $key) === 1) {
                $values[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }

    private function ingestUrl(): string
    {
        return rtrim($this->url, '/').'/v1/datasets/'.rawurlencode((string) $this->dataset).'/ingest';
    }

    private function registerShutdownFlush(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }

        $this->shutdownRegistered = true;
        register_shutdown_function(fn () => $this->flush());
    }
}
