<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmailAddressValidationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_client_email_needs_a_proper_domain(): void
    {
        Notification::fake();

        foreach (['juan@gmail', 'juan@localhost'] as $incomplete) {
            $this->post(route('submission.store'), $this->submission($incomplete))->assertSessionHasErrors('email');
        }
        $this->assertDatabaseCount('documents', 0);

        // The box itself is marked, for screen readers and with a red border, not only the message under it.
        $this->from(route('submission.create'))->followingRedirects()
            ->post(route('submission.store'), $this->submission('juan@gmail'))
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('border-red-700', false)
            ->assertSee('value="juan@gmail"', false);
        $this->get(route('submission.create'))->assertDontSee('aria-invalid="true"', false);

        $this->post(route('submission.store'), $this->submission('juan@gmail.com'))->assertSessionHasNoErrors();
        $this->post(route('submission.store'), $this->submission('juan@deped.gov.ph'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('documents', 2);
    }

    public function test_the_recovery_email_needs_a_proper_domain(): void
    {
        Notification::fake();
        $user = User::factory()->create(['permissions' => ['platform.index' => true]]);

        $this->actingAs($user)->post(route('platform.profile').'/save', [
            'user' => ['name' => $user->name, 'email' => $user->email, 'notification_email' => 'clerk@gmail'],
        ])->assertSessionHasErrors('user.notification_email');
        $this->assertNull($user->fresh()->notification_email);
    }

    /**
     * @return array<string, mixed>
     */
    private function submission(string $email): array
    {
        return [
            'submission_key' => (string) Str::uuid(),
            'name' => 'Juan Dela Cruz',
            'office' => 'Bayombong Central School',
            'email' => $email,
            'delivery_method' => Document::DELIVERY_METHODS[0],
            'title' => 'Leave application',
            'type' => Document::typeOptions()[0],
            'privacy_consent' => '1',
        ];
    }
}
