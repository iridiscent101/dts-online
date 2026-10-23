<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_the_application_homepage_is_the_document_tracking_page(): void
    {
        $response = $this->get('/');

        $response
            ->assertOk()
            ->assertSee('Track a document')
            ->assertSee('Track document');
    }

    public function test_login_page_names_the_records_unit_without_public_links(): void
    {
        $this->get(route('platform.login'))
            ->assertOk()
            ->assertSee('SDO Nueva Vizcaya Records Unit')
            ->assertDontSee('Not a staff member?')
            ->assertDontSee(route('submission.create'), false);
    }
}
