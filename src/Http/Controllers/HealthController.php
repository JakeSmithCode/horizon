<?php

namespace Laravel\Horizon\Http\Controllers;

use Laravel\Horizon\Health\HealthCheck;

class HealthController extends Controller
{
    /**
     * Get the current health check results for the dashboard.
     *
     * @param  \Laravel\Horizon\Health\HealthCheck  $health
     * @return array
     */
    public function index(HealthCheck $health)
    {
        $checks = $health->run();

        return [
            'healthy' => $health->healthy($checks),
            'checks' => $checks,
        ];
    }
}
