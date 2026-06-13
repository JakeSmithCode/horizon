<?php

namespace Laravel\Horizon\Notifications;

class SupervisorOutOfMemory extends HorizonAlert
{
    /**
     * The name of the supervisor that ran out of memory.
     *
     * @var string
     */
    public $supervisor;

    /**
     * The memory usage that exceeded the allowable limit.
     *
     * @var int|float
     */
    public $memoryUsage;

    /**
     * Create a new notification instance.
     *
     * @param  string  $supervisor
     * @param  int|float  $memoryUsage
     * @return void
     */
    public function __construct($supervisor, $memoryUsage)
    {
        $this->supervisor = $supervisor;
        $this->memoryUsage = $memoryUsage;
    }

    /**
     * Determine whether this alert is enabled.
     *
     * @return bool
     */
    public function enabled()
    {
        return (bool) config('horizon.alerts.supervisor_out_of_memory', false);
    }

    /**
     * Get the subject line for the alert.
     *
     * @return string
     */
    public function subject()
    {
        return 'Supervisor Out Of Memory';
    }

    /**
     * Get the human readable message body for the alert.
     *
     * @return string
     */
    public function message()
    {
        return sprintf(
            'The "%s" supervisor exceeded its memory limit using %s MB and will be restarted.',
            $this->supervisor, round($this->memoryUsage, 2)
        );
    }

    /**
     * The unique signature of the notification.
     *
     * @return string
     */
    public function signature()
    {
        return md5('supervisor-out-of-memory:'.$this->supervisor);
    }
}
