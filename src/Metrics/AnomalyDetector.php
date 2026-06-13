<?php

namespace Laravel\Horizon\Metrics;

use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;

class AnomalyDetector
{
    /**
     * The minimum number of snapshots required before trend analysis runs.
     */
    const MIN_SNAPSHOTS = 4;

    /**
     * The fraction of the baseline below which throughput is considered a drop.
     */
    const THROUGHPUT_DROP_RATIO = 0.4;

    /**
     * The minimum baseline throughput required to consider a drop meaningful.
     */
    const MIN_BASELINE_THROUGHPUT = 5;

    /**
     * The multiple of the baseline above which runtime is considered a regression.
     */
    const RUNTIME_REGRESSION_RATIO = 2.0;

    /**
     * The minimum baseline runtime (ms) required to consider a regression meaningful.
     */
    const MIN_BASELINE_RUNTIME = 10;

    /**
     * The recent failure ratio above which the failure rate is considered elevated.
     */
    const FAILURE_RATE_THRESHOLD = 0.2;

    /**
     * The minimum number of recent jobs required to evaluate the failure rate.
     */
    const MIN_RECENT_FOR_FAILURE = 20;

    /**
     * The metrics repository implementation.
     *
     * @var \Laravel\Horizon\Contracts\MetricsRepository
     */
    protected $metrics;

    /**
     * The job repository implementation.
     *
     * @var \Laravel\Horizon\Contracts\JobRepository
     */
    protected $jobs;

    /**
     * The workload repository implementation.
     *
     * @var \Laravel\Horizon\Contracts\WorkloadRepository
     */
    protected $workload;

    /**
     * Create a new anomaly detector instance.
     *
     * @param  \Laravel\Horizon\Contracts\MetricsRepository  $metrics
     * @param  \Laravel\Horizon\Contracts\JobRepository  $jobs
     * @param  \Laravel\Horizon\Contracts\WorkloadRepository  $workload
     * @return void
     */
    public function __construct(MetricsRepository $metrics, JobRepository $jobs, WorkloadRepository $workload)
    {
        $this->metrics = $metrics;
        $this->jobs = $jobs;
        $this->workload = $workload;
    }

    /**
     * Detect any current anomalies in the queue system.
     *
     * @return array<int, array{type: string, severity: string, title: string, detail: string, queue: string|null}>
     */
    public function detect()
    {
        $anomalies = [];

        $this->detectQueueTrends($anomalies);
        $this->detectStalledQueues($anomalies);
        $this->detectFailureRate($anomalies);

        return $anomalies;
    }

    /**
     * Detect throughput collapse and runtime regressions per queue.
     *
     * @param  array  $anomalies
     * @return void
     */
    protected function detectQueueTrends(array &$anomalies)
    {
        foreach ($this->metrics->measuredQueues() as $queue) {
            $snapshots = collect($this->metrics->snapshotsForQueue($queue));

            if ($snapshots->count() < self::MIN_SNAPSHOTS) {
                continue;
            }

            $history = $snapshots->slice(0, -1);
            $latest = $snapshots->last();

            $baselineThroughput = $this->median($history->pluck('throughput'));
            $latestThroughput = (float) ($latest->throughput ?? 0);

            if ($baselineThroughput >= self::MIN_BASELINE_THROUGHPUT &&
                $latestThroughput < $baselineThroughput * self::THROUGHPUT_DROP_RATIO) {
                $anomalies[] = [
                    'type' => 'throughput_drop',
                    'severity' => $latestThroughput == 0 ? 'critical' : 'warning',
                    'title' => 'Throughput drop on "'.$queue.'"',
                    'detail' => 'Throughput fell to '.round($latestThroughput).' from a baseline of '.round($baselineThroughput).' jobs per snapshot.',
                    'queue' => $queue,
                ];
            }

            $baselineRuntime = $this->median($history->pluck('runtime'));
            $latestRuntime = (float) ($latest->runtime ?? 0);

            if ($baselineRuntime >= self::MIN_BASELINE_RUNTIME &&
                $latestRuntime > $baselineRuntime * self::RUNTIME_REGRESSION_RATIO) {
                $anomalies[] = [
                    'type' => 'runtime_regression',
                    'severity' => 'warning',
                    'title' => 'Runtime regression on "'.$queue.'"',
                    'detail' => 'Average runtime rose to '.round($latestRuntime).'ms from a baseline of '.round($baselineRuntime).'ms.',
                    'queue' => $queue,
                ];
            }
        }
    }

    /**
     * Detect queues that have pending jobs but no workers assigned.
     *
     * @param  array  $anomalies
     * @return void
     */
    protected function detectStalledQueues(array &$anomalies)
    {
        foreach ($this->workload->get() as $queue) {
            if (($queue['length'] ?? 0) > 0 && ($queue['processes'] ?? 0) == 0) {
                $anomalies[] = [
                    'type' => 'stalled_queue',
                    'severity' => 'critical',
                    'title' => 'Stalled queue "'.$queue['name'].'"',
                    'detail' => $queue['length'].' job(s) are pending on this queue but no workers are assigned.',
                    'queue' => $queue['name'],
                ];
            }
        }
    }

    /**
     * Detect an elevated recent failure rate.
     *
     * @param  array  $anomalies
     * @return void
     */
    protected function detectFailureRate(array &$anomalies)
    {
        $recent = $this->jobs->countRecent();
        $failed = $this->jobs->countRecentlyFailed();
        $total = $recent + $failed;

        if ($total < self::MIN_RECENT_FOR_FAILURE) {
            return;
        }

        $ratio = $failed / $total;

        if ($ratio >= self::FAILURE_RATE_THRESHOLD) {
            $anomalies[] = [
                'type' => 'elevated_failure_rate',
                'severity' => $ratio >= 0.5 ? 'critical' : 'warning',
                'title' => 'Elevated failure rate',
                'detail' => round($ratio * 100).'% of recent jobs ('.$failed.' of '.$total.') have failed.',
                'queue' => null,
            ];
        }
    }

    /**
     * Compute the median of a numeric collection.
     *
     * @param  \Illuminate\Support\Collection  $values
     * @return float
     */
    protected function median($values)
    {
        $sorted = $values->map(fn ($value) => (float) $value)->sort()->values();

        $count = $sorted->count();

        if ($count === 0) {
            return 0.0;
        }

        $middle = intdiv($count, 2);

        return $count % 2
            ? $sorted[$middle]
            : ($sorted[$middle - 1] + $sorted[$middle]) / 2;
    }
}
