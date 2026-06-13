<?php

namespace Laravel\Horizon\Console;

use Illuminate\Console\Command;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'horizon:usage')]
class UsageCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'horizon:usage {--json : Output the usage report as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Report how many records Horizon is retaining in Redis';

    /**
     * Execute the console command.
     *
     * @param  \Laravel\Horizon\Contracts\JobRepository  $jobs
     * @param  \Laravel\Horizon\Contracts\MetricsRepository  $metrics
     * @return int
     */
    public function handle(JobRepository $jobs, MetricsRepository $metrics)
    {
        $rows = [
            $this->row('Recent jobs', $jobs->countRecent(), config('horizon.trim.recent')),
            $this->row('Pending jobs', $jobs->countPending(), config('horizon.trim.pending')),
            $this->row('Completed jobs', $jobs->countCompleted(), config('horizon.trim.completed')),
            $this->row('Silenced jobs', $jobs->countSilenced(), config('horizon.trim.completed')),
            $this->row('Failed jobs', $jobs->countFailed(), config('horizon.trim.failed')),
            $this->row('Monitored (recently failed)', $jobs->countRecentlyFailed(), config('horizon.trim.monitored')),
            $this->row('Measured job classes', count($metrics->measuredJobs()), null),
            $this->row('Measured queues', count($metrics->measuredQueues()), null),
        ];

        if ($this->option('json')) {
            $this->output->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->output->writeln('');

        $this->table(['Category', 'Count', 'Retention (minutes)'], collect($rows)->map(fn ($row) => [
            $row['category'],
            number_format($row['count']),
            $row['retention_minutes'] ?? '-',
        ])->all());

        $this->output->writeln('');

        return 0;
    }

    /**
     * Build a usage report row.
     *
     * @param  string  $category
     * @param  int  $count
     * @param  int|null  $retentionMinutes
     * @return array
     */
    protected function row($category, $count, $retentionMinutes)
    {
        return [
            'category' => $category,
            'count' => (int) $count,
            'retention_minutes' => $retentionMinutes,
        ];
    }
}
