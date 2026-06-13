<?php

namespace Laravel\Horizon\Notifications;

class HorizonStopped extends HorizonAlert
{
    /**
     * Get the subject line for the alert.
     *
     * @return string
     */
    public function subject()
    {
        return 'Horizon Is Not Running';
    }

    /**
     * Get the human readable message body for the alert.
     *
     * @return string
     */
    public function message()
    {
        return 'No Horizon master supervisor is reporting as active. Horizon may have stopped unexpectedly.';
    }

    /**
     * The unique signature of the notification.
     *
     * @return string
     */
    public function signature()
    {
        return md5('horizon-stopped');
    }
}
