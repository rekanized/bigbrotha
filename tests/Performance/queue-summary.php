<?php

// Uses a private in-memory database and invokes the production summary code.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Livewire\Admin\AdminJobQueue;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\Facade;

$container = new Container;
Container::setInstance($container);
$database = new Manager($container);
$database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$container->instance('db', $database->getDatabaseManager());
Facade::setFacadeApplication($container);
$connection = $database->getConnection();
$connection->statement('CREATE TEMPORARY TABLE jobs (id INTEGER PRIMARY KEY, queue VARCHAR(255), payload TEXT, available_at INTEGER)');
$payload = json_encode(['displayName' => 'App\\Jobs\\FixtureJob', 'padding' => str_repeat('x', 8192)], JSON_THROW_ON_ERROR);
$counts = [];
for ($batch = 0; $batch < 50; $batch++) {
    $rows = [];
    for ($offset = 0; $offset < 100; $offset++) {
        $id = $batch * 100 + $offset + 1;
        $queue = 'fixture-'.($id % 4);
        $counts[$queue] = ($counts[$queue] ?? 0) + 1;
        $rows[] = ['id' => $id, 'queue' => $queue, 'payload' => $payload, 'available_at' => 1700000000 + $id % 100];
    }
    $connection->table('jobs')->insert($rows);
}
unset($rows);
$component = new AdminJobQueue;
$method = new ReflectionMethod($component, 'buildQueueSummary');
$run = fn () => $method->invoke($component, $counts, [], array_keys($counts));
$run();
memory_reset_peak_usage();
$memoryBefore = memory_get_usage();
$samples = [];
for ($iteration = 0; $iteration < 7; $iteration++) {
    $start = hrtime(true);
    $summary = $run();
    $samples[] = (hrtime(true) - $start) / 1e6;
}
sort($samples);
printf("PHP %s; %s; jobs=5000 queues=4 median_ms=%.3f peak_extra_bytes=%d result_sha256=%s\n", PHP_VERSION, $connection->getDriverName(), $samples[3], memory_get_peak_usage() - $memoryBefore, hash('sha256', serialize($summary)));
