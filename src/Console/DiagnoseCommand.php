<?php

namespace Laravel\Horizon\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\WaitTimeCalculator;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'horizon:diagnose')]
class DiagnoseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'horizon:diagnose {--json : Output the diagnostics as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run health checks against the current Horizon installation';

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
     * Execute the console command.
     *
     * @param  \Laravel\Horizon\Contracts\JobRepository  $jobs
     * @param  \Laravel\Horizon\Contracts\MasterSupervisorRepository  $masters
     * @param  \Laravel\Horizon\Contracts\SupervisorRepository  $supervisors
     * @param  \Laravel\Horizon\WaitTimeCalculator  $waitTime
     * @return int
     */
    public function handle(
        JobRepository $jobs,
        MasterSupervisorRepository $masters,
        SupervisorRepository $supervisors,
        WaitTimeCalculator $waitTime,
    ) {
        $checks = [
            $this->checkExtensions(),
            $this->checkRedis(),
            $this->checkMasters($masters),
            $this->checkSupervisors($supervisors),
            $this->checkWaitTimes($waitTime),
            $this->checkFailures($jobs),
        ];

        $exitCode = $this->exitCodeFor($checks);

        if ($this->option('json')) {
            $this->output->writeln(json_encode([
                'healthy' => $exitCode === 0,
                'checks' => $checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exitCode;
        }

        $this->output->writeln('');

        foreach ($checks as $check) {
            $this->renderCheck($check);
        }

        $this->output->writeln('');

        return $exitCode;
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
     * @param  \Laravel\Horizon\Contracts\MasterSupervisorRepository  $masters
     * @return array
     */
    protected function checkMasters($masters)
    {
        $all = collect($masters->all());

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
     * @param  \Laravel\Horizon\Contracts\SupervisorRepository  $supervisors
     * @return array
     */
    protected function checkSupervisors($supervisors)
    {
        $all = collect($supervisors->all());

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
     * @param  \Laravel\Horizon\WaitTimeCalculator  $waitTime
     * @return array
     */
    protected function checkWaitTimes($waitTime)
    {
        $thresholds = config('horizon.waits', []);

        $breaching = collect($waitTime->calculate())->filter(function ($seconds, $queue) use ($thresholds) {
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
     * @param  \Laravel\Horizon\Contracts\JobRepository  $jobs
     * @return array
     */
    protected function checkFailures($jobs)
    {
        $failed = $jobs->countRecentlyFailed();

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

    /**
     * Determine the process exit code for the given checks.
     *
     * @param  array  $checks
     * @return int
     */
    protected function exitCodeFor($checks)
    {
        $statuses = collect($checks)->pluck('status');

        if ($statuses->contains(self::CRITICAL)) {
            return 2;
        }

        return $statuses->contains(self::WARNING) ? 1 : 0;
    }

    /**
     * Render a single check result to the console.
     *
     * @param  array  $check
     * @return void
     */
    protected function renderCheck($check)
    {
        $message = $check['name'].': '.$check['message'];

        match ($check['status']) {
            self::OK => $this->components->info($message),
            self::WARNING => $this->components->warn($message),
            default => $this->components->error($message),
        };
    }
}
