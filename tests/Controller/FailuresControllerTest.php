<?php

namespace Laravel\Horizon\Tests\Controller;

use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Tests\ControllerTest;
use Laravel\Horizon\Tests\Feature\Jobs\FailingJob;

class FailuresControllerTest extends ControllerTest
{
    public function test_failed_jobs_are_grouped_by_exception()
    {
        Queue::push(new FailingJob);
        Queue::push(new FailingJob);
        Queue::push(new FailingJob);

        $this->work();
        $this->work();
        $this->work();

        $response = $this->actingAs(new Fakes\User)->getJson('/horizon/api/failures/groups');

        $response->assertOk();

        $this->assertSame(3, $response->json('total'));
        $this->assertSame(3, $response->json('scanned'));

        $groups = $response->json('groups');

        $this->assertCount(1, $groups);
        $this->assertSame('Exception', $groups[0]['exception']);
        $this->assertSame('Job Failed', $groups[0]['message']);
        $this->assertSame(3, $groups[0]['count']);
        $this->assertCount(3, $groups[0]['ids']);
        $this->assertContains('default', $groups[0]['queues']);
    }

    public function test_groups_are_empty_without_failures()
    {
        $response = $this->actingAs(new Fakes\User)->getJson('/horizon/api/failures/groups');

        $response->assertOk();
        $this->assertSame(0, $response->json('total'));
        $this->assertSame([], $response->json('groups'));
    }
}
