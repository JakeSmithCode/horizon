<?php

namespace Laravel\Horizon\Notifications;

class MasterSupervisorOutOfMemory extends HorizonAlert
{
    /**
     * The name of the master supervisor that ran out of memory.
     *
     * @var string
     */
    public $master;

    /**
     * Create a new notification instance.
     *
     * @param  string  $master
     * @return void
     */
    public function __construct($master)
    {
        $this->master = $master;
    }

    /**
     * Get the subject line for the alert.
     *
     * @return string
     */
    public function subject()
    {
        return 'Master Supervisor Out Of Memory';
    }

    /**
     * Get the human readable message body for the alert.
     *
     * @return string
     */
    public function message()
    {
        return sprintf(
            'The "%s" master supervisor exceeded its memory limit and will be restarted.',
            $this->master
        );
    }

    /**
     * The unique signature of the notification.
     *
     * @return string
     */
    public function signature()
    {
        return md5('master-supervisor-out-of-memory:'.$this->master);
    }
}
