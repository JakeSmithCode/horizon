<?php

namespace Laravel\Horizon\Tests\Controller;

use Laravel\Horizon\Tests\ControllerTest;

class HealthControllerTest extends ControllerTest
{
    public function test_health_endpoint_returns_checks()
    {
        $response = $this->actingAs(new Fakes\User)->getJson('/horizon/api/health');

        $response->assertOk();
        $response->assertJsonStructure([
            'healthy',
            'checks' => [
                ['name', 'status', 'message'],
            ],
        ]);

        $names = collect($response->json('checks'))->pluck('name');

        $this->assertTrue($names->contains('Redis Connection'));
        $this->assertTrue($names->contains('Master Supervisor'));
    }

    public function test_health_endpoint_is_unhealthy_when_horizon_is_inactive()
    {
        $response = $this->actingAs(new Fakes\User)->getJson('/horizon/api/health');

        // With no master supervisor running, the installation is not fully healthy.
        $response->assertJson(['healthy' => false]);
    }
}
