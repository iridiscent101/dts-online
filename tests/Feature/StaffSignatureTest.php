<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Orchid\Access\Impersonation;
use Tests\TestCase;

class StaffSignatureTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_staff_can_upload_view_and_replace_only_their_own_signature(): void
    {
        Storage::fake('local');
        $staff = User::factory()->create(['permissions' => ['platform.index' => true]]);
        $otherStaff = User::factory()->create();

        $this->actingAs($staff)
            ->post(route('platform.profile.signature.store'), [
                'signature' => UploadedFile::fake()->image('first.png', 400, 200),
            ])
            ->assertRedirectToRoute('platform.profile');

        $firstPath = $staff->fresh()->signature_path;
        Storage::disk('local')->assertExists($firstPath);
        $this->assertNull($otherStaff->fresh()->signature_path);

        $this->get(route('platform.profile.signature.show'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->post(route('platform.profile.signature.store'), [
            'signature' => UploadedFile::fake()->image('second.jpg', 500, 250),
        ])->assertRedirectToRoute('platform.profile');

        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($staff->fresh()->signature_path);
        $this->assertNull($otherStaff->fresh()->signature_path);
    }

    public function test_signature_upload_rejects_invalid_files_and_guests(): void
    {
        Storage::fake('local');
        $staff = User::factory()->create(['permissions' => ['platform.index' => true]]);

        $this->actingAs($staff)
            ->post(route('platform.profile.signature.store'), [
                'signature' => UploadedFile::fake()->create('note.pdf', 20, 'application/pdf'),
            ])
            ->assertSessionHasErrors('signature');

        $this->assertNull($staff->fresh()->signature_path);

        auth()->logout();
        $this->post(route('platform.profile.signature.store'), [
            'signature' => UploadedFile::fake()->image('signature.png'),
        ])->assertRedirectToRoute('platform.login');

        $this->get(route('platform.profile.signature.show'))
            ->assertRedirectToRoute('platform.login');
    }

    public function test_impersonating_administrator_cannot_upload_another_staff_members_signature(): void
    {
        Storage::fake('local');
        $administrator = User::factory()->create();
        $staff = User::factory()->create(['permissions' => ['platform.index' => true]]);

        $this->actingAs($staff)
            ->withSession([Impersonation::SESSION_NAME => $administrator->id])
            ->post(route('platform.profile.signature.store'), [
                'signature' => UploadedFile::fake()->image('forged.png'),
            ])
            ->assertForbidden();

        $this->assertNull($staff->fresh()->signature_path);
    }
}
