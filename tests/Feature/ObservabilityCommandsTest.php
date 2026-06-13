<?php

namespace Laravel\Horizon\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Tests\IntegrationTest;

class ObservabilityCommandsTest extends IntegrationTest
{
    public function test_stats_command_reports_inactive_status_for_idle_horizon()
    {
        $this->artisan('horizon:stats')
            ->expectsOutputToContain('inactive')
            ->assertExitCode(0);
    }

    public function test_stats_command_can_emit_json()
    {
        Queue::push(new Jobs\BasicJob);
        $this->work();

        $output = $this->runCommand('horizon:stats', ['--json' => true]);

        $stats = json_decode($output, true);

        $this->assertIsArray($stats);
        $this->assertSame('inactive', $stats['status']);
        $this->assertSame(1, $stats['throughput']);
        $this->assertArrayHasKey('jobsPerMinute', $stats);
        $this->assertArrayHasKey('wait', $stats);
    }

    public function test_diagnose_command_warns_when_horizon_is_inactive()
    {
        // Redis is reachable in the test environment and extensions are present,
        // so an idle Horizon should report warnings (exit 1) rather than critical.
        $this->artisan('horizon:diagnose')->assertExitCode(1);
    }

    public function test_diagnose_command_json_structure_is_well_formed()
    {
        $output = $this->runCommand('horizon:diagnose', ['--json' => true]);

        $payload = json_decode($output, true);

        $this->assertIsArray($payload);
        $this->assertFalse($payload['healthy']);
        $this->assertNotEmpty($payload['checks']);

        foreach ($payload['checks'] as $check) {
            $this->assertArrayHasKey('name', $check);
            $this->assertArrayHasKey('status', $check);
            $this->assertArrayHasKey('message', $check);
            $this->assertContains($check['status'], ['ok', 'warning', 'critical']);
        }

        // Redis and extension checks should pass in the test environment.
        $statuses = collect($payload['checks'])->keyBy('name');
        $this->assertSame('ok', $statuses['Redis Connection']['status']);
        $this->assertSame('ok', $statuses['PHP Extensions']['status']);
    }

    public function test_prometheus_command_emits_openmetrics_text()
    {
        Queue::push(new Jobs\BasicJob);
        $this->work();

        $output = $this->runCommand('horizon:prometheus');

        $this->assertStringContainsString('# HELP horizon_up', $output);
        $this->assertStringContainsString('# TYPE horizon_up gauge', $output);
        $this->assertStringContainsString('horizon_up 0', $output);
        $this->assertStringContainsString('horizon_throughput_total 1', $output);
        $this->assertStringContainsString('horizon_recent_jobs', $output);
    }

    public function test_prometheus_command_respects_namespace_option()
    {
        $output = $this->runCommand('horizon:prometheus', ['--namespace' => 'queues']);

        $this->assertStringContainsString('queues_up ', $output);
        $this->assertStringNotContainsString('horizon_up ', $output);
    }

    public function test_prometheus_command_can_write_to_a_file()
    {
        $path = tempnam(sys_get_temp_dir(), 'horizon_metrics_');

        $this->artisan('horizon:prometheus', ['--file' => $path])->assertExitCode(0);

        $this->assertStringContainsString('horizon_up', file_get_contents($path));

        @unlink($path);
    }

    /**
     * Run an Artisan command and capture its textual output.
     *
     * @param  string  $command
     * @param  array  $parameters
     * @return string
     */
    protected function runCommand($command, array $parameters = [])
    {
        \Illuminate\Support\Facades\Artisan::call($command, $parameters);

        return \Illuminate\Support\Facades\Artisan::output();
    }
}
