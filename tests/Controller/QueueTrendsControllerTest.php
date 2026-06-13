<?php

namespace Laravel\Horizon\Tests\Controller;

use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Tests\ControllerTest;

class QueueTrendsControllerTest extends ControllerTest
{
    public function test_queue_trends_return_throughput_series_per_queue()
    {
        $metrics = resolve(MetricsRepository::class);
        $metrics->incrementQueue('default', 5);
        $metrics->snapshot();
        $metrics->incrementQueue('default', 5);
        $metrics->snapshot();

        $response = $this->actingAs(new Fakes\User)->getJson('/horizon/api/metrics/queue-trends');

        $response->assertOk();

        $data = $response->json();

        $this->assertArrayHasKey('default', $data);
        $this->assertIsArray($data['default']);
        $this->assertNotEmpty($data['default']);
    }

    public function test_queue_trends_are_empty_without_metrics()
    {
        $response = $this->actingAs(new Fakes\User)->getJson('/horizon/api/metrics/queue-trends');

        $response->assertOk();
        $this->assertSame([], $response->json());
    }
}
