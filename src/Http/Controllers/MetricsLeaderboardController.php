<?php

namespace Laravel\Horizon\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Horizon\Contracts\MetricsRepository;

class MetricsLeaderboardController extends Controller
{
    /**
     * The metrics repository implementation.
     *
     * @var \Laravel\Horizon\Contracts\MetricsRepository
     */
    public $metrics;

    /**
     * Create a new controller instance.
     *
     * @param  \Laravel\Horizon\Contracts\MetricsRepository  $metrics
     * @return void
     */
    public function __construct(MetricsRepository $metrics)
    {
        parent::__construct();

        $this->metrics = $metrics;
    }

    /**
     * Get the slowest measured jobs ranked by runtime (p95 when available).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function slowestJobs(Request $request)
    {
        $limit = min((int) ($request->query('limit') ?: 10), 25);

        return collect($this->metrics->measuredJobs())
            ->map(function ($job) {
                $snapshot = collect($this->metrics->snapshotsForJob($job))->last();

                return [
                    'job' => $job,
                    'runtime' => round($snapshot?->runtime ?? $this->metrics->runtimeForJob($job), 2),
                    'p95' => isset($snapshot->p95) ? round($snapshot->p95, 2) : null,
                    'p99' => isset($snapshot->p99) ? round($snapshot->p99, 2) : null,
                    'throughput' => (int) ($snapshot?->throughput ?? $this->metrics->throughputForJob($job)),
                ];
            })
            ->filter(fn ($entry) => ($entry['p95'] ?? $entry['runtime']) > 0)
            ->sortByDesc(fn ($entry) => $entry['p95'] ?? $entry['runtime'])
            ->take($limit)
            ->values()
            ->all();
    }
}
