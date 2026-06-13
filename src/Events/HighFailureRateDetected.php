<?php

namespace Laravel\Horizon\Events;

use Illuminate\Container\Container;
use Laravel\Horizon\Contracts\HighFailureRateDetectedNotification;

class HighFailureRateDetected
{
    /**
     * The number of recently failed jobs.
     *
     * @var int
     */
    public $failedJobs;

    /**
     * The configured failure threshold that was exceeded.
     *
     * @var int
     */
    public $threshold;

    /**
     * Create a new event instance.
     *
     * @param  int  $failedJobs
     * @param  int  $threshold
     * @return void
     */
    public function __construct($failedJobs, $threshold)
    {
        $this->failedJobs = $failedJobs;
        $this->threshold = $threshold;
    }

    /**
     * Get a notification representation of the event.
     *
     * @return \Laravel\Horizon\Notifications\HighFailureRateDetected
     */
    public function toNotification()
    {
        return Container::getInstance()->make(HighFailureRateDetectedNotification::class, [
            'failedJobs' => $this->failedJobs,
            'threshold' => $this->threshold,
        ]);
    }
}
