<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Cache;

use InvalidArgumentException;

final readonly class CacheLockOptions
{
    public function __construct(
        public int $seconds = 30,
        public int $waitSeconds = 5,
    ) {
        if ($seconds <= 0) {
            throw new InvalidArgumentException('Cache lock seconds must be positive.');
        }

        if ($waitSeconds < 0) {
            throw new InvalidArgumentException('Cache lock waitSeconds must not be negative.');
        }
    }
}
