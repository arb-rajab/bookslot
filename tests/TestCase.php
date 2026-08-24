<?php

namespace Tests;

use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\RefreshesTenantDatabase;

abstract class TestCase extends BaseTestCase
{
    use RefreshesTenantDatabase;

    protected function tearDown(): void
    {
        // Defensive: CurrentTenant is process-local static state, and Pest
        // runs every test in the same PHP process. TenantContext::run()
        // always restores the previous value itself, but a test that pokes
        // CurrentTenant directly (or throws before that restore) must never
        // leak tenant context into the next test.
        CurrentTenant::clear();

        parent::tearDown();
    }
}
