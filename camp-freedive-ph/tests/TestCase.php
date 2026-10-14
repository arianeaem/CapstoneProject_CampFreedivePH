<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /** Password of the demo staff accounts created by the seeders in this test (random per test). */
    protected string $demoPassword;

    protected function setUp(): void
    {
        parent::setUp();

        $this->demoPassword = Str::password(16);
        config(['seeding.demo_password' => $this->demoPassword]);

        // No test may reach a real external API (Open-Meteo, PayMongo, ...): stub it with Http::fake()
        Http::preventStrayRequests();
    }
}
