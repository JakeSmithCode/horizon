<?php

namespace Laravel\Horizon\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Console\CheckCommand;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Events\HorizonStopped;
use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\Tests\IntegrationTest;

class HorizonCheckTest extends IntegrationTest
{
    public function test_check_passes_and_records_marker_when_running()
    {
        $master = new MasterSupervisor;
        $master->name = 'running-master';
        resolve(MasterSupervisorRepository::class)->update($master);

        Event::fake([HorizonStopped::class]);

        $this->artisan('horizon:check')->assertExitCode(0);

        Event::assertNotDispatched(HorizonStopped::class);
        $this->assertSame(1, Redis::connection('horizon')->exists(CheckCommand::MARKER));
    }

    public function test_check_alerts_once_when_horizon_stops_after_running()
    {
        // Simulate that Horizon was previously seen running.
        Redis::connection('horizon')->set(CheckCommand::MARKER, time());

        Event::fake([HorizonStopped::class]);

        $this->artisan('horizon:check')->assertExitCode(1);

        Event::assertDispatched(HorizonStopped::class);

        // The marker is cleared so a subsequent check during the same outage is silent.
        $this->assertSame(0, Redis::connection('horizon')->exists(CheckCommand::MARKER));
    }

    public function test_check_is_silent_when_never_running()
    {
        Event::fake([HorizonStopped::class]);

        $this->artisan('horizon:check')->assertExitCode(1);

        Event::assertNotDispatched(HorizonStopped::class);
    }
}
