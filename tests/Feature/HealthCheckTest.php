<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_up_route_reports_the_application_as_alive(): void
    {
        $this->get('/up')->assertOk();
    }
}
