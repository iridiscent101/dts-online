<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ErrorHandlingTest extends TestCase
{
    public function test_http_errors_render_custom_error_pages(): void
    {
        config(['app.debug' => false]);

        Route::get('/__test-error-403', fn () => abort(403));
        Route::get('/__test-error-500', fn () => throw new RuntimeException('Exploded internals'));

        $this->get('/__test-error-403')
            ->assertForbidden()
            ->assertSee('Access denied')
            ->assertSee('Return to dashboard');

        $this->get('/__test-error-404')
            ->assertNotFound()
            ->assertSee('Page not found')
            ->assertSee('Return to dashboard');

        $this->get('/__test-error-500')
            ->assertInternalServerError()
            ->assertSee('Something went wrong')
            ->assertDontSee('Exploded internals');
    }
}
