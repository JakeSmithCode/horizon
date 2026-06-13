<?php

namespace Laravel\Horizon\Notifications;

use Laravel\Horizon\Contracts\HighFailureRateDetectedNotification;

class HighFailureRateDetected extends HorizonAlert implements HighFailureRateDetectedNotification
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
     * Create a new notification instance.
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
     * Get the subject line for the alert.
     *
     * @return string
     */
    public function subject()
    {
        return 'High Job Failure Rate Detected';
    }

    /**
     * Get the human readable message body for the alert.
     *
     * @return string
     */
    public function message()
    {
        return sprintf(
            '%s jobs have failed recently, which exceeds the configured threshold of %s.',
            $this->failedJobs, $this->threshold
        );
    }

    /**
     * The unique signature of the notification.
     *
     * @return string
     */
    public function signature()
    {
        return md5('high-failure-rate');
    }
}
