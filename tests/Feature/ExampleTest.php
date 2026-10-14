<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
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
}
