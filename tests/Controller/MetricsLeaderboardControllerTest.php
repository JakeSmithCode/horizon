<?php

namespace Laravel\Horizon\Tests\Controller;

use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Tests\ControllerTest;

class MetricsLeaderboardControllerTest extends ControllerTest
{
    public function test_slowest_jobs_are_ranked_by_runtime()
    {
        $metrics = resolve(MetricsRepository::class);
        $metrics->incrementJob('App\\Jobs\\SlowJob', 500);
        $metrics->incrementJob('App\\Jobs\\FastJob', 5);
        $metrics->snapshot();

        $response = $this->actingAs(new Fakes\User)->getJson('/horizon/api/leaderboard/slowest-jobs');

        $response->assertOk();

        $data = $response->json();

        $this->assertNotEmpty($data);
        $this->assertSame('App\\Jobs\\SlowJob', $data[0]['job']);
        $this->assertGreaterThan($data[1]['runtime'], $data[0]['runtime']);
    }

    public function test_leaderboard_is_empty_without_metrics()
    {
        $response = $this->actingAs(new Fakes\User)->getJson('/horizon/api/leaderboard/slowest-jobs');

        $response->assertOk();
        $this->assertSame([], $response->json());
    }

    public function test_leaderboard_handles_measured_jobs_without_a_snapshot()
    {
        // A job that has been measured but not yet snapshotted has no snapshot
        // record; the endpoint must fall back to live metrics without erroring.
        $metrics = resolve(MetricsRepository::class);
        $metrics->incrementJob('App\\Jobs\\PendingSnapshotJob', 250);

        $response = $this->actingAs(new Fakes\User)->getJson('/horizon/api/leaderboard/slowest-jobs');

        $response->assertOk();
        $this->assertSame('App\\Jobs\\PendingSnapshotJob', $response->json('0.job'));
    }
}
