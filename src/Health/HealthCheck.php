<?php

namespace Laravel\Horizon\Health;

use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\WaitTimeCalculator;
use Throwable;

class HealthCheck
{
    /**
     * Status indicating a healthy check.
     */
    const OK = 'ok';

    /**
     * Status indicating a non-fatal problem.
     */
    const WARNING = 'warning';

    /**
     * Status indicating a fatal problem.
     */
    const CRITICAL = 'critical';

    /**
     * The job repository implementation.
     *
     * @var \Laravel\Horizon\Contracts\JobRepository
     */
    protected $jobs;

    /**
     * The master supervisor repository implementation.
     *
     * @var \Laravel\Horizon\Contracts\MasterSupervisorRepository
     */
    protected $masters;

    /**
     * The supervisor repository implementation.
     *
     * @var \Laravel\Horizon\Contracts\SupervisorRepository
     */
    protected $supervisors;

    /**
     * The wait time calculator instance.
     *
     * @var \Laravel\Horizon\WaitTimeCalculator
     */
    protected $waitTime;

    /**
     * Create a new health check instance.
     *
     * @param  \Laravel\Horizon\Contracts\JobRepository  $jobs
     * @param  \Laravel\Horizon\Contracts\MasterSupervisorRepository  $masters
     * @param  \Laravel\Horizon\Contracts\SupervisorRepository  $supervisors
     * @param  \Laravel\Horizon\WaitTimeCalculator  $waitTime
     * @return void
     */
    public function __construct(
        JobRepository $jobs,
        MasterSupervisorRepository $masters,
        SupervisorRepository $supervisors,
        WaitTimeCalculator $waitTime,
    ) {
        $this->jobs = $jobs;
        $this->masters = $masters;
        $this->supervisors = $supervisors;
        $this->waitTime = $waitTime;
    }

    /**
     * Run all of the health checks.
     *
     * @return array<int, array{name: string, status: string, message: string}>
     */
    public function run()
    {
        return [
            $this->checkExtensions(),
            $this->checkRedis(),
            $this->checkMasters(),
            $this->checkSupervisors(),
            $this->checkWaitTimes(),
            $this->checkFailures(),
        ];
    }

    /**
     * Determine whether the given checks represent a healthy installation.
     *
     * @param  array  $checks
     * @return bool
     */
    public function healthy(array $checks)
    {
        return ! collect($checks)->pluck('status')->contains(
            fn ($status) => $status !== self::OK
        );
    }

    /**
     * Verify the PHP extensions required by Horizon are present.
     *
     * @return array
     */
    protected function checkExtensions()
    {
        $missing = collect(['pcntl', 'posix'])
            ->reject(fn ($extension) => extension_loaded($extension))
            ->values();

        if ($missing->isNotEmpty()) {
            return $this->result('PHP Extensions', self::CRITICAL,
                'Missing required extension(s): '.$missing->implode(', '));
        }

        return $this->result('PHP Extensions', self::OK, 'pcntl and posix are loaded.');
    }

    /**
     * Verify the Horizon Redis connection is reachable.
     *
     * @return array
     */
    protected function checkRedis()
    {
        $connection = config('horizon.use', 'default');

        try {
            Redis::connection($connection)->ping();
        } catch (Throwable $e) {
            return $this->result('Redis Connection', self::CRITICAL,
                "Unable to reach Redis connection [{$connection}]: ".$e->getMessage());
        }

        return $this->result('Redis Connection', self::OK,
            "Redis connection [{$connection}] is reachable.");
    }

    /**
     * Verify a Horizon master supervisor is running.
     *
     * @return array
     */
    protected function checkMasters()
    {
        $all = collect($this->masters->all());

        if ($all->isEmpty()) {
            return $this->result('Master Supervisor', self::WARNING,
                'Horizon is inactive. No master supervisor is running.');
        }

        if ($all->every(fn ($master) => $master->status === 'paused')) {
            return $this->result('Master Supervisor', self::WARNING, 'Horizon is paused.');
        }

        return $this->result('Master Supervisor', self::OK,
            $all->count().' master supervisor(s) running.');
    }

    /**
     * Verify worker supervisors and processes are present.
     *
     * @return array
     */
    protected function checkSupervisors()
    {
        $all = collect($this->supervisors->all());

        if ($all->isEmpty()) {
            return $this->result('Worker Processes', self::WARNING, 'No supervisors are running.');
        }

        $processes = $all->reduce(
            fn ($carry, $supervisor) => $carry + collect($supervisor->processes)->sum(), 0
        );

        if ($processes === 0) {
            return $this->result('Worker Processes', self::WARNING,
                'Supervisors are running but no worker processes are active.');
        }

        return $this->result('Worker Processes', self::OK,
            $processes.' worker process(es) across '.$all->count().' supervisor(s).');
    }

    /**
     * Verify no queue is waiting longer than its configured threshold.
     *
     * @return array
     */
    protected function checkWaitTimes()
    {
        $thresholds = config('horizon.waits', []);

        $breaching = collect($this->waitTime->calculate())->filter(function ($seconds, $queue) use ($thresholds) {
            $threshold = $thresholds[$queue] ?? null;

            return $threshold !== null && $seconds > $threshold;
        });

        if ($breaching->isNotEmpty()) {
            return $this->result('Queue Wait Times', self::WARNING,
                'Long wait detected on: '.$breaching->map(
                    fn ($seconds, $queue) => "{$queue} ({$seconds}s)"
                )->implode(', '));
        }

        return $this->result('Queue Wait Times', self::OK,
            'All monitored queues are within their wait thresholds.');
    }

    /**
     * Verify the recent failure rate is acceptable.
     *
     * @return array
     */
    protected function checkFailures()
    {
        $failed = $this->jobs->countRecentlyFailed();
        $threshold = config('horizon.failure_threshold');

        if ($threshold && $failed > $threshold) {
            return $this->result('Recent Failures', self::CRITICAL,
                $failed.' recent failures exceed the configured threshold of '.$threshold.'.');
        }

        if ($failed > 0) {
            return $this->result('Recent Failures', self::WARNING,
                $failed.' job(s) have failed recently.');
        }

        return $this->result('Recent Failures', self::OK, 'No recent job failures.');
    }

    /**
     * Build a structured check result.
     *
     * @param  string  $name
     * @param  string  $status
     * @param  string  $message
     * @return array
     */
    protected function result($name, $status, $message)
    {
        return ['name' => $name, 'status' => $status, 'message' => $message];
    }
}
