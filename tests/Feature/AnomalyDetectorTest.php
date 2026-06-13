<?php

namespace Laravel\Horizon\Tests\Feature;

use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Laravel\Horizon\Metrics\AnomalyDetector;
use Laravel\Horizon\Tests\IntegrationTest;
use Mockery;

class AnomalyDetectorTest extends IntegrationTest
{
    public function test_throughput_collapse_is_detected()
    {
        $detector = $this->detector(
            queues: ['default'],
            snapshots: ['default' => $this->series([100, 110, 95, 105, 0])],
        );

        $anomalies = collect($detector->detect());

        $drop = $anomalies->firstWhere('type', 'throughput_drop');

        $this->assertNotNull($drop);
        $this->assertSame('critical', $drop['severity']);
        $this->assertSame('default', $drop['queue']);
    }

    public function test_runtime_regression_is_detected()
    {
        $detector = $this->detector(
            queues: ['default'],
            snapshots: ['default' => $this->series([100, 100, 100, 100], [50, 55, 52, 400])],
        );

        $anomalies = collect($detector->detect());

        $this->assertNotNull($anomalies->firstWhere('type', 'runtime_regression'));
    }

    public function test_stalled_queue_is_detected_from_workload()
    {
        $detector = $this->detector(
            workload: [
                ['name' => 'emails', 'length' => 42, 'wait' => 99, 'processes' => 0, 'split_queues' => null],
            ],
        );

        $stalled = collect($detector->detect())->firstWhere('type', 'stalled_queue');

        $this->assertNotNull($stalled);
        $this->assertSame('emails', $stalled['queue']);
    }

    public function test_no_anomalies_for_stable_system()
    {
        $detector = $this->detector(
            queues: ['default'],
            snapshots: ['default' => $this->series([100, 105, 98, 102, 101])],
        );

        $this->assertSame([], $detector->detect());
    }

    /**
     * Build an anomaly detector backed by mocked repositories.
     */
    protected function detector(array $queues = [], array $snapshots = [], array $workload = [])
    {
        $metrics = Mockery::mock(MetricsRepository::class);
        $metrics->shouldReceive('measuredQueues')->andReturn($queues);
        $metrics->shouldReceive('snapshotsForQueue')->andReturnUsing(fn ($queue) => $snapshots[$queue] ?? []);

        $workloadRepo = Mockery::mock(WorkloadRepository::class);
        $workloadRepo->shouldReceive('get')->andReturn($workload);

        return new AnomalyDetector($metrics, $workloadRepo);
    }

    /**
     * Build a series of snapshot objects from throughput (and optional runtime) values.
     */
    protected function series(array $throughputs, ?array $runtimes = null)
    {
        return collect($throughputs)->map(fn ($throughput, $i) => (object) [
            'throughput' => $throughput,
            'runtime' => $runtimes[$i] ?? 50,
            'time' => 1000 + $i,
        ])->all();
    }
}
