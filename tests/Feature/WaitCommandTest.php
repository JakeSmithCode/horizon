<?php

namespace Laravel\Horizon\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Tests\IntegrationTest;

class WaitCommandTest extends IntegrationTest
{
    public function test_wait_returns_immediately_when_queues_are_empty()
    {
        $this->artisan('horizon:wait', ['--connection' => 'redis', '--queue' => ['default']])
            ->assertExitCode(0);
    }

    public function test_wait_times_out_when_jobs_remain()
    {
        Queue::push(new Jobs\BasicJob);

        $this->artisan('horizon:wait', [
            '--connection' => 'redis',
            '--queue' => ['default'],
            '--timeout' => 1,
            '--sleep' => 1,
        ])->assertExitCode(1);
    }
}
