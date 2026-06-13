<?php

namespace Laravel\Horizon\Events;

use Laravel\Horizon\MasterSupervisor;

class MasterSupervisorOutOfMemory
{
    /**
     * The master supervisor instance.
     *
     * @var \Laravel\Horizon\MasterSupervisor
     */
    public $master;

    /**
     * Create a new event instance.
     *
     * @param  \Laravel\Horizon\MasterSupervisor  $master
     * @return void
     */
    public function __construct(MasterSupervisor $master)
    {
        $this->master = $master;
    }

    /**
     * Get a notification representation of the event.
     *
     * @return \Laravel\Horizon\Notifications\MasterSupervisorOutOfMemory
     */
    public function toNotification()
    {
        return new \Laravel\Horizon\Notifications\MasterSupervisorOutOfMemory(
            $this->master->name
        );
    }
}
