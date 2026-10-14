<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

class ContentSecurityPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_public_pages_only_run_scripts_from_this_site_or_with_the_response_nonce(): void
    {
        $document = Document::factory()->create();
        $response = $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))->assertOk();

        $policy = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($policy);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9]+)'/", $policy);
        preg_match("/'nonce-([A-Za-z0-9]+)'/", $policy, $nonce);
        $scriptSources = str($policy)->after('script-src')->before(';')->toString();
        $this->assertStringNotContainsString('unsafe-inline', $scriptSources);
        $this->assertStringNotContainsString('unsafe-eval', $scriptSources);
        $this->assertStringContainsString("frame-ancestors 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);

        // Every inline script on the page carries this response's nonce.
        preg_match_all('/<script(?![^>]*\bsrc=)([^>]*)>/i', $response->getContent(), $inlineScripts);
        $this->assertNotEmpty($inlineScripts[1]);
        foreach ($inlineScripts[1] as $attributes) {
            $this->assertStringContainsString('nonce="'.$nonce[1].'"', $attributes);
        }

        $this->get(route('submission.create'))->assertOk()->assertHeader('Content-Security-Policy');
    }

    public function test_staff_pages_send_the_policy_and_a_new_nonce_each_time(): void
    {
        $user = User::factory()->create(['permissions' => ['platform.index' => true]]);

        $first = $this->actingAs($user)->get(route('platform.main'))->assertOk()->headers->get('Content-Security-Policy');
        $second = $this->get(route('platform.main'))->assertOk()->headers->get('Content-Security-Policy');

        $this->assertNotNull($first);
        $this->assertNotSame($first, $second);
        $this->get(route('platform.login'))->assertHeader('Content-Security-Policy');
    }

    public function test_report_only_mode_logs_without_blocking_and_off_sends_nothing(): void
    {
        config(['app.csp_mode' => 'report-only']);
        $this->get(route('submission.create'))
            ->assertHeader('Content-Security-Policy-Report-Only')
            ->assertHeaderMissing('Content-Security-Policy');

        config(['app.csp_mode' => 'off']);
        $this->get(route('submission.create'))
            ->assertHeaderMissing('Content-Security-Policy')
            ->assertHeaderMissing('Content-Security-Policy-Report-Only')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_an_ipv6_vite_dev_server_is_allowed_by_scheme_because_csp_cannot_name_it(): void
    {
        $hotFile = storage_path('framework/testing-vite.hot');
        file_put_contents($hotFile, 'http://[::1]:5173');
        Vite::useHotFile($hotFile);

        try {
            $policy = $this->get(route('submission.create'))->assertOk()->headers->get('Content-Security-Policy');
        } finally {
            unlink($hotFile);
        }

        $this->assertStringNotContainsString('[::1]', $policy);
        $this->assertMatchesRegularExpression("/style-src 'self' 'unsafe-inline' http:;/", $policy);
        $this->assertMatchesRegularExpression("/connect-src 'self' http: ws:;/", $policy);
    }
}
