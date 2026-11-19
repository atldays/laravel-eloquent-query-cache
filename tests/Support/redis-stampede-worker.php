<?php

declare(strict_types=1);

use Atldays\QueryCache\Query\Builder;
use Atldays\QueryCache\Test\Support\RedisStampedeFixture;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Events\Dispatcher;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[$script, $namespace, $role, $sqlitePath, $id] = $argv;
$id = (int)$id;
$client = RedisStampedeFixture::client();
$reportedContention = false;
$store = RedisStampedeFixture::store($client, $namespace, function (string $name, bool $acquired) use ($client, $namespace, $role, &$reportedContention): void {
    if (!$acquired && !$reportedContention) {
        $reportedContention = true;
        $client->rPush($namespace . ':waiting:' . $role, $name);
    }
});

$connection = new SQLiteConnection(new PDO('sqlite:' . $sqlitePath), $sqlitePath, '', ['name' => 'stampede-sqlite', 'driver' => 'sqlite']);
$connection->setEventDispatcher(new Dispatcher);
$connection->listen(function (QueryExecuted $query) use ($client, $namespace, $role, $id): void {
    $client->incr($namespace . ':sql:' . $id);
    if ($role === 'producer') {
        $client->rPush($namespace . ':producer-in-sql', 'ready');
        if (!$client->blPop([$namespace . ':finish-production'], 12)) {
            throw new RuntimeException('Producer did not receive the test barrier signal.');
        }
    }
});

$query = (new Builder($connection))->from('records')->where('id', $id)
    ->cacheDriver($store->tags(['integration']))->cacheFor(60)->cacheLock(20, 10);

echo json_encode(['pid' => getmypid(), 'value' => $query->value('label')], JSON_THROW_ON_ERROR);
