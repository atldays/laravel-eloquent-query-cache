<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test\Support;

use Illuminate\Cache\Repository;

class RecordingRepository extends Repository
{
    public array $keys = [];

    public array $writes = [];

    public function get($key, $default = null): mixed
    {
        $this->keys[] = $key;

        return parent::get($key, $default);
    }

    public function put($key, $value, $ttl = null): bool
    {
        $this->writes[] = $key;

        return parent::put($key, $value, $ttl);
    }

    public function forever($key, $value): bool
    {
        $this->writes[] = $key;

        return parent::forever($key, $value);
    }
}
