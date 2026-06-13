<?php

namespace Laravel\Horizon\Events;

class HorizonStopped
{
    /**
     * Get a notification representation of the event.
     *
     * @return \Laravel\Horizon\Notifications\HorizonStopped
     */
    public function toNotification()
    {
        return new \Laravel\Horizon\Notifications\HorizonStopped;
    }
}
