<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    /**
     * Unauthenticated API requests get a JSON 401, whatever the Accept header.
     */
    public function test_unauthenticated_api_requests_receive_a_json_401(): void
    {
        $response = $this->get('/api/v1/user');

        $response->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }
}
