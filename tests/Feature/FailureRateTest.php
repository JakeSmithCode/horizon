<?php

namespace Laravel\Horizon\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Events\HighFailureRateDetected;
use Laravel\Horizon\Listeners\MonitorFailureRate;
use Laravel\Horizon\Tests\IntegrationTest;
use Mockery;

class FailureRateTest extends IntegrationTest
{
    public function test_event_is_fired_when_failures_exceed_threshold()
    {
        config(['horizon.failure_threshold' => 5]);
        Event::fake([HighFailureRateDetected::class]);

        $this->handleWithRecentFailures(10);

        Event::assertDispatched(HighFailureRateDetected::class, function ($event) {
            return $event->failedJobs === 10 && $event->threshold === 5;
        });
    }

    public function test_event_is_not_fired_when_below_threshold()
    {
        config(['horizon.failure_threshold' => 5]);
        Event::fake([HighFailureRateDetected::class]);

        $this->handleWithRecentFailures(3);

        Event::assertNotDispatched(HighFailureRateDetected::class);
    }

    public function test_monitoring_is_disabled_without_a_threshold()
    {
        config(['horizon.failure_threshold' => null]);
        Event::fake([HighFailureRateDetected::class]);

        $this->handleWithRecentFailures(100);

        Event::assertNotDispatched(HighFailureRateDetected::class);
    }

    /**
     * Run the failure-rate monitor with a stubbed recent failure count.
     *
     * @param  int  $failures
     * @return void
     */
    protected function handleWithRecentFailures($failures)
    {
        $jobs = Mockery::mock(JobRepository::class);
        $jobs->shouldReceive('countRecentlyFailed')->andReturn($failures);

        (new MonitorFailureRate($jobs))->handle();
    }
}
