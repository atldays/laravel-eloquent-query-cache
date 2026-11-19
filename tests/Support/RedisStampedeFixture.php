<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test\Support;

use Closure;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Redis;
use RuntimeException;

final class RedisStampedeFixture
{
    public static function client(): Redis
    {
        $client = new Redis;
        $host = getenv('QUERY_CACHE_REDIS_HOST');
        $port = (int)(getenv('QUERY_CACHE_REDIS_PORT') ?: 6379);
        if ($host === false || !$client->connect($host, $port, 2.0)) {
            throw new RuntimeException('Could not connect to the Redis test service.');
        }
        $client->setOption(Redis::OPT_READ_TIMEOUT, 15);

        return $client;
    }

    /** @param  null|Closure(string, bool): void  $observeLock */
    public static function store(Redis $client, string $namespace, ?Closure $observeLock = null): RedisStore
    {
        $connection = new class($client, $observeLock) extends PhpRedisConnection
        {
            public function __construct(Redis $client, private ?Closure $observeLock)
            {
                parent::__construct($client);
            }

            public function command($method, array $parameters = [])
            {
                $result = parent::command($method, $parameters);
                $options = $parameters[2] ?? null;
                if ($method === 'set' && is_array($options) && in_array('NX', $options, true)) {
                    ($this->observeLock)?->__invoke($parameters[0], (bool)$result);
                }

                return $result;
            }
        };
        $factory = new class($connection) implements Factory
        {
            public function __construct(private PhpRedisConnection $connection) {}

            public function connection($name = null): PhpRedisConnection
            {
                return $this->connection;
            }
        };

        return new RedisStore($factory, $namespace . ':data:');
    }
}
