<?php

namespace Laravel\Horizon\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Tests\IntegrationTest;

class UsageCommandTest extends IntegrationTest
{
    public function test_usage_command_reports_counts_as_json()
    {
        Queue::push(new Jobs\BasicJob);
        $this->work();

        Artisan::call('horizon:usage', ['--json' => true]);

        $rows = json_decode(Artisan::output(), true);

        $this->assertIsArray($rows);

        $categories = collect($rows)->keyBy('category');

        $this->assertArrayHasKey('Recent jobs', $categories);
        $this->assertSame(1, $categories['Recent jobs']['count']);
        $this->assertSame(config('horizon.trim.recent'), $categories['Recent jobs']['retention_minutes']);
    }

    public function test_usage_command_runs_for_an_empty_install()
    {
        $this->artisan('horizon:usage')->assertExitCode(0);
    }
}
