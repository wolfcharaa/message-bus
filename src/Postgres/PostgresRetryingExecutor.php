<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Postgres;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class PostgresRetryingExecutor
{
    private readonly PostgresTransientFailureDetectorInterface $detector;
    private readonly PostgresRetryConfig $config;
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly PdoConnectionProviderInterface $connectionProvider,
        ?PostgresTransientFailureDetectorInterface $detector = null,
        ?PostgresRetryConfig $config = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->detector = $detector ?? new DefaultPostgresTransientFailureDetector();
        $this->config = $config ?? PostgresRetryConfig::default();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     */
    public function execute(
        string $operation,
        OperationSafety $safety,
        callable $callback,
        ?string $idempotencyKey = null,
    ): mixed {
        return $this->run($operation, $safety, $callback, $idempotencyKey, transactional: false);
    }

    /**
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     */
    public function transactional(
        string $operation,
        OperationSafety $safety,
        callable $callback,
        ?string $idempotencyKey = null,
    ): mixed {
        return $this->run($operation, $safety, $callback, $idempotencyKey, transactional: true);
    }

    /**
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     */
    private function run(
        string $operation,
        OperationSafety $safety,
        callable $callback,
        ?string $idempotencyKey,
        bool $transactional,
    ): mixed {
        if ($operation === '') {
            throw new \InvalidArgumentException('PostgreSQL retry operation name must not be empty.');
        }

        if ($safety === OperationSafety::IdempotentWithUniqueKey && ($idempotencyKey === null || $idempotencyKey === '')) {
            throw new \InvalidArgumentException('IdempotentWithUniqueKey PostgreSQL operation requires stable idempotency key.');
        }

        $attempt = 0;

        retry:
        ++$attempt;
        $pdo = $this->connectionProvider->connection();

        try {
            if (!$transactional) {
                return $callback($pdo);
            }

            $pdo->beginTransaction();
            try {
                $result = $callback($pdo);
                $pdo->commit();

                return $result;
            } catch (\Throwable $error) {
                $this->rollback($pdo, $operation, $error);
                throw $error;
            }
        } catch (\Throwable $error) {
            $reason = $this->detector->reason($error);
            if ($reason === null) {
                throw $error;
            }

            try {
                $this->connectionProvider->reset();
            } catch (\Throwable $resetError) {
                $this->logger->error('PostgreSQL connection provider reset failed after transient failure.', [
                    'operation' => $operation,
                    'attempt' => $attempt,
                    'safety' => $safety->value,
                    'reason' => $reason,
                    'exception' => $resetError,
                    'original_exception' => $error,
                ]);

                throw new \RuntimeException(
                    \sprintf(
                        'PostgreSQL connection provider reset failed after transient failure in operation "%s": %s',
                        $operation,
                        $resetError->getMessage(),
                    ),
                    0,
                    $error,
                );
            }

            if (!$this->shouldRetry($safety, $idempotencyKey, $attempt)) {
                $this->logger->error('PostgreSQL transient failure was not retried.', [
                    'operation' => $operation,
                    'attempt' => $attempt,
                    'safety' => $safety->value,
                    'reason' => $reason,
                    'exception' => $error,
                ]);

                throw $error;
            }

            $this->logger->warning('Retrying PostgreSQL operation after transient failure.', [
                'operation' => $operation,
                'attempt' => $attempt,
                'next_attempt' => $attempt + 1,
                'max_attempts' => $this->config->attempts,
                'safety' => $safety->value,
                'reason' => $reason,
                'exception' => $error,
            ]);

            $this->sleepBeforeRetry($attempt);

            goto retry;
        }
    }

    private function shouldRetry(OperationSafety $safety, ?string $idempotencyKey, int $attempt): bool
    {
        return $this->config->enabled
            && $attempt < $this->config->attempts
            && $safety->allowsRetry($idempotencyKey);
    }

    private function rollback(PDO $pdo, string $operation, \Throwable $original): void
    {
        if (!$pdo->inTransaction()) {
            return;
        }

        try {
            $pdo->rollBack();
        } catch (\Throwable $rollbackError) {
            $this->logger->warning('PostgreSQL transaction rollback failed after operation error.', [
                'operation' => $operation,
                'exception' => $rollbackError,
                'original_exception' => $original,
            ]);
        }
    }

    private function sleepBeforeRetry(int $attempt): void
    {
        $delay = $this->config->delayMillisecondsForRetry($attempt);
        if ($delay > 0) {
            \usleep($delay * 1000);
        }
    }
}
