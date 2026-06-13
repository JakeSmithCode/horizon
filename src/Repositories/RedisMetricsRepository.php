<?php

namespace Laravel\Horizon\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Lock;
use Laravel\Horizon\LuaScripts;
use Laravel\Horizon\WaitTimeCalculator;

class RedisMetricsRepository implements MetricsRepository
{
    /**
     * The upper bounds (in milliseconds) of the runtime histogram buckets.
     *
     * Used to capture an approximate runtime distribution so percentiles can be
     * derived at snapshot time. Runtimes above the final bound fall into an
     * overflow bucket whose index equals the number of bounds.
     *
     * @var array<int, int>
     */
    const RUNTIME_BUCKETS = [1, 5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000, 30000, 60000];

    /**
     * The Redis connection instance.
     *
     * @var \Illuminate\Contracts\Redis\Factory
     */
    public $redis;

    /**
     * Create a new repository instance.
     *
     * @param  \Illuminate\Contracts\Redis\Factory  $redis
     * @return void
     */
    public function __construct(RedisFactory $redis)
    {
        $this->redis = $redis;
    }

    /**
     * Get all of the class names that have metrics measurements.
     *
     * @return array
     */
    public function measuredJobs()
    {
        $classes = (array) $this->connection()->smembers('measured_jobs');

        return collect($classes)
            ->map(fn ($class) => preg_match('/job:(.*)$/', $class, $matches) ? $matches[1] : $class)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Get all of the queues that have metrics measurements.
     *
     * @return array
     */
    public function measuredQueues()
    {
        $queues = (array) $this->connection()->smembers('measured_queues');

        return collect($queues)
            ->map(fn ($class) => preg_match('/queue:(.*)$/', $class, $matches) ? $matches[1] : $class)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Get the jobs processed per minute since the last snapshot.
     *
     * @return float
     */
    public function jobsProcessedPerMinute()
    {
        return round($this->throughput() / $this->minutesSinceLastSnapshot());
    }

    /**
     * Get the application's total throughput since the last snapshot.
     *
     * @return int
     */
    public function throughput()
    {
        return collect($this->measuredQueues())
            ->reduce(fn ($carry, $queue) => $carry + $this->connection()->hget('queue:'.$queue, 'throughput'), 0);
    }

    /**
     * Get the throughput for a given job.
     *
     * @param  string  $job
     * @return int
     */
    public function throughputForJob($job)
    {
        return $this->throughputFor('job:'.$job);
    }

    /**
     * Get the throughput for a given queue.
     *
     * @param  string  $queue
     * @return int
     */
    public function throughputForQueue($queue)
    {
        return $this->throughputFor('queue:'.$queue);
    }

    /**
     * Get the throughput for a given key.
     *
     * @param  string  $key
     * @return int
     */
    protected function throughputFor($key)
    {
        return (int) $this->connection()->hget($key, 'throughput');
    }

    /**
     * Get the average runtime for a given job in milliseconds.
     *
     * @param  string  $job
     * @return float
     */
    public function runtimeForJob($job)
    {
        return $this->runtimeFor('job:'.$job);
    }

    /**
     * Get the average runtime for a given queue in milliseconds.
     *
     * @param  string  $queue
     * @return float
     */
    public function runtimeForQueue($queue)
    {
        return $this->runtimeFor('queue:'.$queue);
    }

    /**
     * Get the average runtime for a given key in milliseconds.
     *
     * @param  string  $key
     * @return float
     */
    protected function runtimeFor($key)
    {
        return (float) $this->connection()->hget($key, 'runtime');
    }

    /**
     * Get the queue that has the longest runtime.
     *
     * @return int
     */
    public function queueWithMaximumRuntime()
    {
        return collect($this->measuredQueues())
            ->sortBy(function ($queue) {
                if ($snapshots = $this->connection()->zrange('snapshot:queue:'.$queue, -1, 1)) {
                    return json_decode($snapshots[0])->runtime;
                }
            })
            ->last();
    }

    /**
     * Get the queue that has the most throughput.
     *
     * @return int
     */
    public function queueWithMaximumThroughput()
    {
        return collect($this->measuredQueues())
            ->sortBy(function ($queue) {
                if ($snapshots = $this->connection()->zrange('snapshot:queue:'.$queue, -1, 1)) {
                    return json_decode($snapshots[0])->throughput;
                }
            })
            ->last();
    }

    /**
     * Increment the metrics information for a job.
     *
     * @param  string  $job
     * @param  float|null  $runtime
     * @return void
     */
    public function incrementJob($job, $runtime)
    {
        $this->connection()->eval(LuaScripts::updateMetrics(), 2,
            'job:'.$job, 'measured_jobs', str_replace(',', '.', (string) $runtime)
        );

        $this->recordRuntimeBucket('job:'.$job, $runtime);
    }

    /**
     * Increment the metrics information for a queue.
     *
     * @param  string  $queue
     * @param  float|null  $runtime
     * @return void
     */
    public function incrementQueue($queue, $runtime)
    {
        $this->connection()->eval(LuaScripts::updateMetrics(), 2,
            'queue:'.$queue, 'measured_queues', str_replace(',', '.', (string) $runtime)
        );

        $this->recordRuntimeBucket('queue:'.$queue, $runtime);
    }

    /**
     * Record a runtime measurement in the percentile histogram for the key.
     *
     * This is opt-in via the "horizon.metrics.percentiles" config flag. When
     * disabled (the default) no extra Redis writes occur, preserving the
     * original behavior for existing installations.
     *
     * @param  string  $key
     * @param  float|null  $runtime
     * @return void
     */
    protected function recordRuntimeBucket($key, $runtime)
    {
        if (! config('horizon.metrics.percentiles') || ! is_numeric($runtime)) {
            return;
        }

        $this->connection()->hincrby('percentile:'.$key, $this->bucketFor((float) $runtime), 1);
    }

    /**
     * Get the histogram bucket index for the given runtime in milliseconds.
     *
     * @param  float  $runtime
     * @return int
     */
    protected function bucketFor($runtime)
    {
        foreach (self::RUNTIME_BUCKETS as $index => $upperBound) {
            if ($runtime <= $upperBound) {
                return $index;
            }
        }

        return count(self::RUNTIME_BUCKETS);
    }

    /**
     * Compute the runtime percentiles for a key and reset its histogram.
     *
     * @param  string  $key
     * @return array<string, float>
     */
    protected function percentilesFor($key)
    {
        $histogram = (array) $this->connection()->hgetall('percentile:'.$key);

        if (empty($histogram)) {
            return [];
        }

        $this->connection()->del('percentile:'.$key);

        return [
            'p95' => $this->percentileFromHistogram($histogram, 0.95),
            'p99' => $this->percentileFromHistogram($histogram, 0.99),
        ];
    }

    /**
     * Estimate a percentile (in milliseconds) from a bucketed histogram.
     *
     * @param  array  $histogram
     * @param  float  $percentile
     * @return float
     */
    protected function percentileFromHistogram($histogram, $percentile)
    {
        $total = array_sum(array_map('intval', $histogram));

        if ($total === 0) {
            return 0.0;
        }

        $rank = $percentile * $total;
        $cumulative = 0;
        $lowerBound = 0.0;

        foreach (self::RUNTIME_BUCKETS as $index => $upperBound) {
            $count = (int) ($histogram[$index] ?? 0);

            if ($count > 0 && $cumulative + $count >= $rank) {
                $fraction = ($rank - $cumulative) / $count;

                return round($lowerBound + $fraction * ($upperBound - $lowerBound), 2);
            }

            $cumulative += $count;
            $lowerBound = $upperBound;
        }

        // The percentile falls in the overflow bucket; we can only report a floor.
        $buckets = self::RUNTIME_BUCKETS;

        return (float) end($buckets);
    }

    /**
     * Get all of the snapshots for the given job.
     *
     * @param  string  $job
     * @return array
     */
    public function snapshotsForJob($job)
    {
        return $this->snapshotsFor('job:'.$job);
    }

    /**
     * Get all of the snapshots for the given queue.
     *
     * @param  string  $queue
     * @return array
     */
    public function snapshotsForQueue($queue)
    {
        return $this->snapshotsFor('queue:'.$queue);
    }

    /**
     * Get all of the snapshots for the given key.
     *
     * @param  string  $key
     * @return array
     */
    protected function snapshotsFor($key)
    {
        return collect($this->connection()->zrange('snapshot:'.$key, 0, -1))
            ->map(fn ($snapshot) => (object) json_decode($snapshot, true))
            ->values()
            ->all();
    }

    /**
     * Store a snapshot of the metrics information.
     *
     * @return void
     */
    public function snapshot()
    {
        collect($this->measuredJobs())->each(function ($job) {
            $this->storeSnapshotForJob($job);
        });

        collect($this->measuredQueues())->each(function ($queue) {
            $this->storeSnapshotForQueue($queue);
        });

        $this->storeSnapshotTimestamp();
    }

    /**
     * Store a snapshot for the given job.
     *
     * @param  string  $job
     * @return void
     */
    protected function storeSnapshotForJob($job)
    {
        $data = $this->baseSnapshotData($key = 'job:'.$job);

        $this->connection()->zadd(
            'snapshot:'.$key, $time = CarbonImmutable::now()->getTimestamp(), json_encode(array_merge([
                'throughput' => $data['throughput'],
                'runtime' => $data['runtime'],
                'time' => $time,
            ], $this->percentilesFor($key)))
        );

        $this->connection()->zremrangebyrank(
            'snapshot:'.$key, 0, -abs(1 + config('horizon.metrics.trim_snapshots.job', 24))
        );
    }

    /**
     * Store a snapshot for the given queue.
     *
     * @param  string  $queue
     * @return void
     */
    protected function storeSnapshotForQueue($queue)
    {
        $data = $this->baseSnapshotData($key = 'queue:'.$queue);

        $this->connection()->zadd(
            'snapshot:'.$key, $time = CarbonImmutable::now()->getTimestamp(), json_encode(array_merge([
                'throughput' => $data['throughput'],
                'runtime' => $data['runtime'],
                'wait' => app(WaitTimeCalculator::class)->calculateFor($queue),
                'time' => $time,
            ], $this->percentilesFor($key)))
        );

        $this->connection()->zremrangebyrank(
            'snapshot:'.$key, 0, -abs(1 + config('horizon.metrics.trim_snapshots.queue', 24))
        );
    }

    /**
     * Get the base snapshot data for a given key.
     *
     * @param  string  $key
     * @return array
     */
    protected function baseSnapshotData($key)
    {
        $responses = $this->connection()->transaction(function ($trans) use ($key) {
            $trans->hmget($key, ['throughput', 'runtime']);

            $trans->del($key);
        });

        $snapshot = array_values($responses[0]);

        return [
            'throughput' => $snapshot[0],
            'runtime' => $snapshot[1],
        ];
    }

    /**
     * Get the number of minutes passed since the last snapshot.
     *
     * @return float
     */
    protected function minutesSinceLastSnapshot()
    {
        $lastSnapshotAt = (int) ($this->connection()->get('last_snapshot_at')
            ?: $this->storeSnapshotTimestamp());

        return max(
            (CarbonImmutable::now()->getTimestamp() - $lastSnapshotAt) / 60, 1
        );
    }

    /**
     * Store the current timestamp as the "last snapshot timestamp".
     *
     * @return int
     */
    protected function storeSnapshotTimestamp()
    {
        return tap(CarbonImmutable::now()->getTimestamp(), function ($timestamp) {
            $this->connection()->set('last_snapshot_at', $timestamp);
        });
    }

    /**
     * Attempt to acquire a lock to monitor the queue wait times.
     *
     * @return bool
     */
    public function acquireWaitTimeMonitorLock()
    {
        return app(Lock::class)->get('monitor:time-to-clear');
    }

    /**
     * Clear the metrics for a key.
     *
     * @param  string  $key
     * @return void
     */
    public function forget($key)
    {
        $this->connection()->del($key);
    }

    /**
     * Delete all stored metrics information.
     *
     * @return void
     */
    public function clear()
    {
        $this->forget('last_snapshot_at');
        $this->forget('measured_jobs');
        $this->forget('measured_queues');
        $this->forget('metrics:snapshot');

        foreach (['queue:*', 'job:*', 'snapshot:*', 'percentile:*'] as $pattern) {
            $cursor = null;

            do {
                $scanResult = $this->connection()->scan(
                    $cursor ?? 0, ['match' => config('horizon.prefix').$pattern]
                );

                if (! is_array($scanResult)) {
                    break;
                }

                [$cursor, $keys] = $scanResult;

                foreach ($keys ?? [] as $key) {
                    $this->forget(Str::after($key, config('horizon.prefix')));
                }
            } while ($cursor > 0);
        }
    }

    /**
     * Get the Redis connection instance.
     *
     * @return \Illuminate\Redis\Connections\Connection
     */
    public function connection()
    {
        return $this->redis->connection('horizon');
    }
}
