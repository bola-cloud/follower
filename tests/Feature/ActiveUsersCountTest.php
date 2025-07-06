<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ActiveUsersCountTest extends TestCase
{
    /**
     * Test when the presence channel exists and users are active.
     */
    public function test_active_users_count_returns_correct_value()
    {
        // Mock Soketi response
        Http::fake([
            'http://127.0.0.1:6001/api/v1/apps/*/channels/presence-active-users' => Http::response([
                'subscription_count' => 5
            ], 200)
        ]);

        $response = $this->getJson('/api/active-users-count');

        $response->assertOk()
                 ->assertJson([
                     'active_users_count' => 5
                 ]);
    }

    /**
     * Test when the presence channel does not exist yet (404).
     */
    public function test_active_users_count_returns_zero_if_channel_not_found()
    {
        // Mock Soketi 404 response
        Http::fake([
            'http://127.0.0.1:6001/api/v1/apps/*/channels/presence-active-users' => Http::response(null, 404)
        ]);

        $response = $this->getJson('/api/active-users-count');

        $response->assertOk()
                 ->assertJson([
                     'active_users_count' => 0
                 ]);
    }

    /**
     * Test when Soketi returns an error.
     */
    public function test_active_users_count_handles_server_error()
    {
        // Mock Soketi error response
        Http::fake([
            'http://127.0.0.1:6001/api/v1/apps/*/channels/presence-active-users' => Http::response('Internal Server Error', 500)
        ]);

        $response = $this->getJson('/api/active-users-count');

        $response->assertStatus(500)
                 ->assertJsonStructure(['error', 'details']);
    }
}
