<?php

namespace Laravel\Horizon\Listeners;

use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Events\HighFailureRateDetected;
use Laravel\Horizon\Lock;

class MonitorFailureRate
{
    /**
     * The job repository implementation.
     *
     * @var \Laravel\Horizon\Contracts\JobRepository
     */
    public $jobs;

    /**
     * Create a new listener instance.
     *
     * @param  \Laravel\Horizon\Contracts\JobRepository  $jobs
     * @return void
     */
    public function __construct(JobRepository $jobs)
    {
        $this->jobs = $jobs;
    }

    /**
     * Handle the event.
     *
     * @return void
     */
    public function handle()
    {
        $threshold = config('horizon.failure_threshold');

        // Monitoring is opt-in. When no threshold is configured the feature is
        // disabled entirely, preserving the default behavior of Horizon and
        // keeping the package a drop-in replacement for existing installs.
        if (! $threshold) {
            return;
        }

        // A short-lived Redis lock throttles the check to once per interval
        // across the whole cluster. Listeners are resolved fresh on every
        // dispatch, so we cannot rely on in-memory state for the interval.
        if (! app(Lock::class)->get('monitor:failure-rate', 60)) {
            return;
        }

        $failedJobs = $this->jobs->countRecentlyFailed();

        if ($failedJobs > $threshold) {
            event(new HighFailureRateDetected($failedJobs, (int) $threshold));
        }
    }
}
