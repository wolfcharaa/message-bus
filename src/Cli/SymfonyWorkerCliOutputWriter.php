<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Cli;

use BackedEnum;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Wolfcharaa\MessageBus\Worker\WorkerCliOutputConfig;
use Wolfcharaa\MessageBus\Worker\WorkerCliOutputFormat;
use Wolfcharaa\MessageBus\Worker\WorkerCliOutputVerbosity;
use Wolfcharaa\MessageBus\Worker\WorkerCliOutputWriterInterface;

final class SymfonyWorkerCliOutputWriter implements WorkerCliOutputWriterInterface
{
    public function __construct(
        private readonly OutputInterface $output,
        private readonly WorkerCliOutputConfig $config = new WorkerCliOutputConfig(),
    ) {
    }

    public function write(
        string $level,
        string $event,
        string $message,
        array $context = [],
        WorkerCliOutputVerbosity $verbosity = WorkerCliOutputVerbosity::Normal,
    ): void {
        if (!$this->shouldWrite($level, $event, $verbosity)) {
            return;
        }

        $target = $this->isErrorLevel($level) && $this->output instanceof ConsoleOutputInterface
            ? $this->output->getErrorOutput()
            : $this->output;

        $target->writeln(match ($this->config->format) {
            WorkerCliOutputFormat::Text => $this->textLine($level, $event, $message, $context),
            WorkerCliOutputFormat::Json => $this->jsonLine($level, $event, $message, $context),
        });
    }

    private function shouldWrite(string $level, string $event, WorkerCliOutputVerbosity $verbosity): bool
    {
        if ($this->isErrorLevel($level) || $event === 'worker.stopped') {
            return true;
        }

        return $this->config->verbosity->allows($verbosity);
    }

    private function isErrorLevel(string $level): bool
    {
        return \in_array($level, ['error', 'fatal'], true);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function textLine(string $level, string $event, string $message, array $context): string
    {
        $parts = [];
        foreach ($context as $key => $value) {
            $parts[] = $key . '=' . $this->textValue($value);
        }

        return \sprintf(
            '[%s] %s %s %s%s',
            $this->timestamp(),
            \strtoupper($level),
            $event,
            $message,
            $parts === [] ? '' : ' ' . \implode(' ', $parts),
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function jsonLine(string $level, string $event, string $message, array $context): string
    {
        return \json_encode([
            'timestamp' => $this->timestamp(),
            'level' => $level,
            'event' => $event,
            'message' => $message,
            'context' => $this->normalize($context),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function textValue(mixed $value): string
    {
        $value = $this->normalize($value);

        if (\is_array($value)) {
            return \json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($value === null) {
            return 'null';
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof Throwable) {
            return [
                'class' => $value::class,
                'message' => $value->getMessage(),
                'code' => $value->getCode(),
            ];
        }

        if ($value instanceof DateTimeImmutable) {
            return $value->format(DATE_ATOM);
        }

        if (\is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalize($item);
            }

            return $normalized;
        }

        if (\is_object($value)) {
            return $value::class;
        }

        return $value;
    }

    private function timestamp(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}
