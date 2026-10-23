<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ?string $vercel = null;

    public function createApplication()
    {
        // Set before the application boots, as Vercel does with its own environment variable.
        if ($this->vercel !== null) {
            putenv('VERCEL='.$this->vercel);
            $_ENV['VERCEL'] = $_SERVER['VERCEL'] = $this->vercel;
        }

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        putenv('VERCEL');
        unset($_ENV['VERCEL'], $_SERVER['VERCEL']);

        parent::tearDown();
    }

    public function test_on_vercel_the_clients_own_address_is_used(): void
    {
        $this->vercel = '1';
        $this->refreshApplication();
        Route::get('/__client-ip', fn () => request()->ip());

        // Two staff members behind the same Vercel edge are told apart by the address Vercel forwards.
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5'])
            ->get('/__client-ip')->assertSeeText('203.0.113.5');
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'])
            ->get('/__client-ip')->assertSeeText('198.51.100.7');

        // A forwarded host is never trusted, so links cannot be pointed at another site.
        Route::get('/__host', fn () => request()->getHost());
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'dts.example.gov.ph', 'HTTP_X_FORWARDED_HOST' => 'evil.test'])
            ->get('http://dts.example.gov.ph/__host')->assertSeeText('dts.example.gov.ph')->assertDontSeeText('evil.test');
    }

    public function test_a_server_reached_directly_ignores_a_forwarded_address(): void
    {
        Route::get('/__client-ip', fn () => request()->ip());

        // Without a trusted proxy, a client cannot fake its address to dodge the sign-in limits.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5'])
            ->get('/__client-ip')->assertSeeText('10.0.0.9');
    }
}
