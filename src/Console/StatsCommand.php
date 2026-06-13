<?php

namespace Laravel\Horizon\Console;

use Illuminate\Console\Command;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\WaitTimeCalculator;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'horizon:stats')]
class StatsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'horizon:stats {--json : Output the statistics as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display the current Horizon performance statistics';

    /**
     * Execute the console command.
     *
     * @param  \Laravel\Horizon\Contracts\JobRepository  $jobs
     * @param  \Laravel\Horizon\Contracts\MetricsRepository  $metrics
     * @param  \Laravel\Horizon\Contracts\SupervisorRepository  $supervisors
     * @param  \Laravel\Horizon\Contracts\MasterSupervisorRepository  $masters
     * @param  \Laravel\Horizon\WaitTimeCalculator  $waitTime
     * @return int
     */
    public function handle(
        JobRepository $jobs,
        MetricsRepository $metrics,
        SupervisorRepository $supervisors,
        MasterSupervisorRepository $masters,
        WaitTimeCalculator $waitTime,
    ) {
        $stats = $this->gather($jobs, $metrics, $supervisors, $masters, $waitTime);

        if ($this->option('json')) {
            $this->output->writeln(json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->output->writeln('');

        $this->table(['Metric', 'Value'], [
            ['Status', $stats['status']],
            ['Active Processes', $stats['processes']],
            ['Jobs Per Minute', $stats['jobsPerMinute']],
            ['Recent Jobs', $stats['recentJobs']],
            ['Recently Failed Jobs', $stats['failedJobs']],
            ['Total Throughput', $stats['throughput']],
            ['Paused Masters', $stats['pausedMasters']],
            ['Max Runtime Queue', $stats['queueWithMaxRuntime'] ?: '-'],
            ['Max Throughput Queue', $stats['queueWithMaxThroughput'] ?: '-'],
        ]);

        if (! empty($stats['wait'])) {
            $this->output->writeln('');

            $this->table(['Queue', 'Estimated Wait (seconds)'], collect($stats['wait'])
                ->map(fn ($seconds, $queue) => [$queue, $seconds])
                ->values()
                ->all());
        }

        $this->output->writeln('');

        return 0;
    }

    /**
     * Gather the current performance statistics.
     *
     * @param  \Laravel\Horizon\Contracts\JobRepository  $jobs
     * @param  \Laravel\Horizon\Contracts\MetricsRepository  $metrics
     * @param  \Laravel\Horizon\Contracts\SupervisorRepository  $supervisors
     * @param  \Laravel\Horizon\Contracts\MasterSupervisorRepository  $masters
     * @param  \Laravel\Horizon\WaitTimeCalculator  $waitTime
     * @return array
     */
    protected function gather($jobs, $metrics, $supervisors, $masters, $waitTime)
    {
        $allMasters = collect($masters->all());

        return [
            'status' => $this->currentStatus($allMasters),
            'processes' => collect($supervisors->all())->reduce(
                fn ($carry, $supervisor) => $carry + collect($supervisor->processes)->sum(), 0
            ),
            'jobsPerMinute' => $metrics->jobsProcessedPerMinute(),
            'recentJobs' => $jobs->countRecent(),
            'failedJobs' => $jobs->countRecentlyFailed(),
            'throughput' => $metrics->throughput(),
            'pausedMasters' => $allMasters->where('status', 'paused')->count(),
            'queueWithMaxRuntime' => $metrics->queueWithMaximumRuntime(),
            'queueWithMaxThroughput' => $metrics->queueWithMaximumThroughput(),
            'wait' => $waitTime->calculate(),
        ];
    }

    /**
     * Determine the current status of Horizon.
     *
     * @param  \Illuminate\Support\Collection  $masters
     * @return string
     */
    protected function currentStatus($masters)
    {
        if ($masters->isEmpty()) {
            return 'inactive';
        }

        return $masters->every(fn ($master) => $master->status === 'paused') ? 'paused' : 'running';
    }
}
