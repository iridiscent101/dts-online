<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PlatformSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_platform_responses_include_defensive_security_headers(): void
    {
        $user = User::factory()->create([
            'permissions' => ['platform.index' => true],
        ]);

        $this->actingAs($user)
            ->get(route('platform.main'))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'same-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), geolocation=(), microphone=()');
    }

    public function test_orchid_demonstration_routes_are_not_exposed(): void
    {
        $user = User::factory()->create([
            'permissions' => ['platform.index' => true],
        ]);

        $this->actingAs($user)->get('/admin/example')->assertNotFound();
        $this->get('/admin/examples/form/fields')->assertNotFound();
    }

    public function test_attachment_routes_reject_non_numeric_attachment_identifiers(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->create([
            'current_office' => $user->office,
        ]);

        $this->actingAs($user)
            ->get("/admin/documents/{$document->getKey()}/attachments/not-a-number/content")
            ->assertNotFound();
    }
}
