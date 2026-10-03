<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TermsAndPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_terms_and_conditions_page_loads_successfully(): void
    {
        $response = $this->get(route('legal.terms'));

        $response->assertStatus(200);
        $response->assertSee('Terms and Conditions of Service');
        $response->assertSee('14-Day Cancellation and Reschedule Policy');
        $response->assertSee('Weather Safety and Force Majeure');
    }

    public function test_privacy_policy_page_loads_successfully(): void
    {
        $response = $this->get(route('legal.privacy'));

        $response->assertStatus(200);
        $response->assertSee('Privacy Policy');
        $response->assertSee('Republic Act No. 10173');
    }
}
