<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_versioned_health_endpoint_returns_ok(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'service' => 'likindy-ai-office-api',
                'version' => 'v1',
                'checks' => ['database' => 'ok'],
            ]);
    }

    public function test_versioned_health_endpoint_hides_database_failure_details(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->with('SELECT 1')
            ->andThrow(new RuntimeException('database connection details'));

        $this->getJson('/api/v1/health')
            ->assertServiceUnavailable()
            ->assertExactJson([
                'status' => 'unavailable',
                'service' => 'likindy-ai-office-api',
                'version' => 'v1',
                'checks' => ['database' => 'unavailable'],
            ])
            ->assertDontSee('database connection details');
    }
}
