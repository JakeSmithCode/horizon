<?php

namespace Laravel\Horizon\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Stopwatch;
use Laravel\Horizon\Tests\IntegrationTest;
use Mockery;
use ReflectionMethod;

class RuntimePercentilesTest extends IntegrationTest
{
    public function test_percentile_is_interpolated_within_the_histogram_bucket()
    {
        $repo = resolve(MetricsRepository::class);

        $method = new ReflectionMethod($repo, 'percentileFromHistogram');
        $method->setAccessible(true);

        // 90 samples <= 1ms (bucket 0) and 10 samples in the (500, 1000] bucket (index 8).
        $histogram = [0 => 90, 8 => 10];

        // rank(p95) = 95 lands 5/10 into bucket 8: 500 + 0.5 * (1000 - 500) = 750.
        $this->assertSame(750.0, $method->invoke($repo, $histogram, 0.95));

        // rank(p99) = 99 lands 9/10 into bucket 8: 500 + 0.9 * (1000 - 500) = 950.
        $this->assertSame(950.0, $method->invoke($repo, $histogram, 0.99));
    }

    public function test_percentiles_are_stored_in_snapshots_when_enabled()
    {
        config(['horizon.metrics.percentiles' => true]);

        $stopwatch = Mockery::mock(Stopwatch::class);
        $stopwatch->shouldReceive('start');
        $stopwatch->shouldReceive('check')->andReturn(12); // ms -> bucket (10, 25]
        $this->app->instance(Stopwatch::class, $stopwatch);

        Queue::push(new Jobs\BasicJob);
        Queue::push(new Jobs\BasicJob);
        $this->work();
        $this->work();

        $metrics = resolve(MetricsRepository::class);
        $metrics->snapshot();

        $snapshot = collect($metrics->snapshotsForJob(Jobs\BasicJob::class))->last();

        $this->assertTrue(isset($snapshot->p95));
        $this->assertTrue(isset($snapshot->p99));
        $this->assertGreaterThanOrEqual(10, $snapshot->p95);
        $this->assertLessThanOrEqual(25, $snapshot->p99);
        $this->assertGreaterThanOrEqual($snapshot->p95, $snapshot->p99);
    }

    public function test_percentiles_are_absent_when_disabled()
    {
        config(['horizon.metrics.percentiles' => false]);

        Queue::push(new Jobs\BasicJob);
        $this->work();

        $metrics = resolve(MetricsRepository::class);
        $metrics->snapshot();

        $snapshot = collect($metrics->snapshotsForJob(Jobs\BasicJob::class))->last();

        $this->assertFalse(isset($snapshot->p95));
    }
}
