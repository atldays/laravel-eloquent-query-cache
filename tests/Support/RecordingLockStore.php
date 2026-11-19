<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test\Support;

use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Lock;

class RecordingLockStore extends ArrayStore
{
    /** @var list<array{string, int}> */
    public array $locksRequested = [];

    public ?Closure $onLockRequest = null;

    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        $this->locksRequested[] = [$name, $seconds];
        ($this->onLockRequest ?? static fn () => null)();

        return parent::lock($name, $seconds, $owner);
    }
}
