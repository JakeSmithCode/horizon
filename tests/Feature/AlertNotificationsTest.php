<?php

namespace Laravel\Horizon\Tests\Feature;

use Illuminate\Support\Facades\Notification;
use Laravel\Horizon\Events\HorizonStopped;
use Laravel\Horizon\Events\MasterSupervisorOutOfMemory;
use Laravel\Horizon\Events\SupervisorOutOfMemory;
use Laravel\Horizon\Events\UnableToLaunchProcess;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\Notifications;
use Laravel\Horizon\Supervisor;
use Laravel\Horizon\SupervisorOptions;
use Laravel\Horizon\Tests\IntegrationTest;
use Laravel\Horizon\WorkerProcess;
use Symfony\Component\Process\Process;

class AlertNotificationsTest extends IntegrationTest
{
    public function test_supervisor_out_of_memory_sends_a_notification()
    {
        Notification::fake();
        Horizon::routeMailNotificationsTo('ops@example.com');
        config(['horizon.alerts.supervisor_out_of_memory' => true]);

        $supervisor = new Supervisor(new SupervisorOptions('alpha', 'redis', 'default'));

        event((new SupervisorOutOfMemory($supervisor))->setMemoryUsage(256));

        Notification::assertSentOnDemand(Notifications\SupervisorOutOfMemory::class, function ($notification) {
            return $notification->supervisor === 'alpha' && $notification->memoryUsage === 256;
        });
    }

    public function test_master_supervisor_out_of_memory_sends_a_notification()
    {
        Notification::fake();
        Horizon::routeMailNotificationsTo('ops@example.com');
        config(['horizon.alerts.master_supervisor_out_of_memory' => true]);

        $master = new MasterSupervisor;
        $master->name = 'master-1';

        event(new MasterSupervisorOutOfMemory($master));

        Notification::assertSentOnDemand(Notifications\MasterSupervisorOutOfMemory::class, function ($notification) {
            return $notification->master === 'master-1';
        });
    }

    public function test_unable_to_launch_process_sends_a_notification()
    {
        Notification::fake();
        Horizon::routeMailNotificationsTo('ops@example.com');
        config(['horizon.alerts.failed_to_launch_process' => true]);

        $process = new WorkerProcess(new Process(['echo', 'worker']));

        event(new UnableToLaunchProcess($process));

        Notification::assertSentOnDemand(Notifications\UnableToLaunchProcess::class);
    }

    public function test_horizon_stopped_sends_a_notification()
    {
        Notification::fake();
        Horizon::routeMailNotificationsTo('ops@example.com');

        event(new HorizonStopped);

        Notification::assertSentOnDemand(Notifications\HorizonStopped::class);
    }

    public function test_alert_has_no_delivery_channels_without_configured_routing()
    {
        Horizon::$email = null;
        Horizon::$slackWebhookUrl = null;
        Horizon::$smsNumber = null;

        $this->assertSame([], (new Notifications\HorizonStopped)->via(null));
    }

    public function test_out_of_memory_alerts_are_disabled_by_default()
    {
        // Routing is configured, but the opt-in flags are off (the default), so
        // upgrading must not start delivering these alerts.
        Horizon::routeMailNotificationsTo('ops@example.com');

        $this->assertSame([], (new Notifications\SupervisorOutOfMemory('alpha', 128))->via(null));
        $this->assertSame([], (new Notifications\MasterSupervisorOutOfMemory('master-1'))->via(null));
        $this->assertSame([], (new Notifications\UnableToLaunchProcess('cmd'))->via(null));

        config(['horizon.alerts.supervisor_out_of_memory' => true]);

        $this->assertContains('mail', (new Notifications\SupervisorOutOfMemory('alpha', 128))->via(null));
    }
}
