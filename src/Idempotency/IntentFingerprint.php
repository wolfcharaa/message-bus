<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Idempotency;

use InvalidArgumentException;

final readonly class IntentFingerprint
{
    public function __construct(
        public string $algorithm,
        public string $version,
        public string $digest,
    ) {
        if (\trim($this->algorithm) === '' || \trim($this->version) === '' || \trim($this->digest) === '') {
            throw new InvalidArgumentException('Intent fingerprint algorithm, version and digest must be non-empty.');
        }
    }

    public static function sha256(string $canonicalPayload, string $version = '1'): self
    {
        return new self('sha256', $version, \hash('sha256', $canonicalPayload));
    }

    public function equals(self $other): bool
    {
        return $this->algorithm === $other->algorithm
            && $this->version === $other->version
            && \hash_equals($this->digest, $other->digest);
    }
}
