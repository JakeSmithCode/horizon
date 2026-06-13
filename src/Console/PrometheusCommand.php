<?php

namespace Laravel\Horizon\Console;

use Illuminate\Console\Command;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'horizon:prometheus')]
class PrometheusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'horizon:prometheus
                            {--file= : Write the metrics to the given file instead of stdout}
                            {--namespace=horizon : The metric name prefix to use}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export Horizon metrics in Prometheus / OpenMetrics text format';

    /**
     * The accumulated metric lines.
     *
     * @var array
     */
    protected $lines = [];

    /**
     * Execute the console command.
     *
     * @param  \Laravel\Horizon\Contracts\JobRepository  $jobs
     * @param  \Laravel\Horizon\Contracts\MetricsRepository  $metrics
     * @param  \Laravel\Horizon\Contracts\WorkloadRepository  $workload
     * @param  \Laravel\Horizon\Contracts\SupervisorRepository  $supervisors
     * @param  \Laravel\Horizon\Contracts\MasterSupervisorRepository  $masters
     * @return int
     */
    public function handle(
        JobRepository $jobs,
        MetricsRepository $metrics,
        WorkloadRepository $workload,
        SupervisorRepository $supervisors,
        MasterSupervisorRepository $masters,
    ) {
        $allMasters = collect($masters->all());

        $this->gauge('up', 'Whether a Horizon master supervisor is running (1) or not (0).',
            $allMasters->isNotEmpty() ? 1 : 0);

        $this->gauge('paused', 'Whether all Horizon master supervisors are paused (1) or not (0).',
            $allMasters->isNotEmpty() && $allMasters->every(fn ($m) => $m->status === 'paused') ? 1 : 0);

        $this->gauge('processes', 'The number of active worker processes.',
            collect($supervisors->all())->reduce(
                fn ($carry, $supervisor) => $carry + collect($supervisor->processes)->sum(), 0
            ));

        $this->gauge('jobs_per_minute', 'Jobs processed per minute since the last snapshot.',
            $metrics->jobsProcessedPerMinute());

        $this->gauge('throughput_total', 'Total throughput since the last snapshot.',
            $metrics->throughput());

        $this->gauge('recent_jobs', 'The number of recent jobs.', $jobs->countRecent());

        $this->gauge('recently_failed_jobs', 'The number of recently failed jobs.',
            $jobs->countRecentlyFailed());

        $this->gauge('failed_jobs_total', 'The total number of stored failed jobs.',
            $jobs->totalFailed());

        $this->perQueueWorkload($workload);
        $this->perQueueMetrics($metrics);
        $this->perJobMetrics($metrics);

        $output = implode("\n", $this->lines)."\n";

        if ($file = $this->option('file')) {
            file_put_contents($file, $output);

            $this->components->info("Horizon metrics written to [{$file}].");

            return 0;
        }

        $this->output->write($output);

        return 0;
    }

    /**
     * Emit per-queue workload metrics.
     *
     * @param  \Laravel\Horizon\Contracts\WorkloadRepository  $workload
     * @return void
     */
    protected function perQueueWorkload($workload)
    {
        $queues = collect($workload->get());

        $this->help('queue_length', 'The number of pending jobs on a queue.', 'gauge');
        $this->help('queue_wait_seconds', 'The estimated time to clear a queue in seconds.', 'gauge');
        $this->help('queue_processes', 'The number of processes assigned to a queue.', 'gauge');

        foreach ($queues as $queue) {
            $labels = ['queue' => $queue['name']];

            $this->sample('queue_length', $labels, $queue['length']);
            $this->sample('queue_wait_seconds', $labels, $queue['wait']);
            $this->sample('queue_processes', $labels, $queue['processes']);
        }
    }

    /**
     * Emit per-queue throughput and runtime metrics.
     *
     * @param  \Laravel\Horizon\Contracts\MetricsRepository  $metrics
     * @return void
     */
    protected function perQueueMetrics($metrics)
    {
        $queues = $metrics->measuredQueues();

        $this->help('queue_throughput', 'Throughput per queue since the last snapshot.', 'gauge');
        $this->help('queue_runtime_milliseconds', 'Average job runtime per queue in milliseconds.', 'gauge');

        foreach ($queues as $queue) {
            $labels = ['queue' => $queue];

            $this->sample('queue_throughput', $labels, $metrics->throughputForQueue($queue));
            $this->sample('queue_runtime_milliseconds', $labels, $metrics->runtimeForQueue($queue));
        }
    }

    /**
     * Emit per-job throughput and runtime metrics.
     *
     * @param  \Laravel\Horizon\Contracts\MetricsRepository  $metrics
     * @return void
     */
    protected function perJobMetrics($metrics)
    {
        $jobs = $metrics->measuredJobs();

        $this->help('job_throughput', 'Throughput per job class since the last snapshot.', 'gauge');
        $this->help('job_runtime_milliseconds', 'Average runtime per job class in milliseconds.', 'gauge');

        foreach ($jobs as $job) {
            $labels = ['job' => $job];

            $this->sample('job_throughput', $labels, $metrics->throughputForJob($job));
            $this->sample('job_runtime_milliseconds', $labels, $metrics->runtimeForJob($job));
        }
    }

    /**
     * Emit a single labelless gauge with help and type metadata.
     *
     * @param  string  $name
     * @param  string  $help
     * @param  int|float  $value
     * @return void
     */
    protected function gauge($name, $help, $value)
    {
        $this->help($name, $help, 'gauge');
        $this->sample($name, [], $value);
    }

    /**
     * Append HELP and TYPE metadata lines for a metric.
     *
     * @param  string  $name
     * @param  string  $help
     * @param  string  $type
     * @return void
     */
    protected function help($name, $help, $type)
    {
        $metric = $this->metricName($name);

        $this->lines[] = '# HELP '.$metric.' '.$help;
        $this->lines[] = '# TYPE '.$metric.' '.$type;
    }

    /**
     * Append a sample line for a metric.
     *
     * @param  string  $name
     * @param  array  $labels
     * @param  int|float  $value
     * @return void
     */
    protected function sample($name, array $labels, $value)
    {
        $metric = $this->metricName($name);

        if (! empty($labels)) {
            $pairs = collect($labels)->map(
                fn ($value, $key) => $key.'="'.$this->escapeLabel((string) $value).'"'
            )->implode(',');

            $metric .= '{'.$pairs.'}';
        }

        $this->lines[] = $metric.' '.$this->formatValue($value);
    }

    /**
     * Prefix a metric name with the configured namespace.
     *
     * @param  string  $name
     * @return string
     */
    protected function metricName($name)
    {
        return $this->option('namespace').'_'.$name;
    }

    /**
     * Escape a Prometheus label value.
     *
     * @param  string  $value
     * @return string
     */
    protected function escapeLabel($value)
    {
        return str_replace(['\\', "\n", '"'], ['\\\\', '\\n', '\\"'], $value);
    }

    /**
     * Format a numeric value for the exposition format.
     *
     * @param  int|float  $value
     * @return string
     */
    protected function formatValue($value)
    {
        if (is_float($value)) {
            return rtrim(rtrim(sprintf('%.4f', $value), '0'), '.');
        }

        return (string) $value;
    }
}
