<?php

namespace Laravel\Horizon\Notifications;

class UnableToLaunchProcess extends HorizonAlert
{
    /**
     * The command line of the process that could not be launched.
     *
     * @var string
     */
    public $command;

    /**
     * Create a new notification instance.
     *
     * @param  string  $command
     * @return void
     */
    public function __construct($command)
    {
        $this->command = $command;
    }

    /**
     * Get the subject line for the alert.
     *
     * @return string
     */
    public function subject()
    {
        return 'Unable To Launch Worker Process';
    }

    /**
     * Get the human readable message body for the alert.
     *
     * @return string
     */
    public function message()
    {
        return sprintf(
            'Horizon was unable to launch a worker process: %s',
            $this->command
        );
    }

    /**
     * The unique signature of the notification.
     *
     * @return string
     */
    public function signature()
    {
        return md5('unable-to-launch-process:'.$this->command);
    }
}
