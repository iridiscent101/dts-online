<?php

namespace Tests\Feature;

use App\Models\Document;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class RemovedSubmissionResendTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_resending_a_removed_submission_gives_a_fresh_form_and_a_note(): void
    {
        Notification::fake();
        $data = $this->submission((string) Str::uuid());
        $this->post(route('submission.store'), $data)->assertSessionHasNoErrors();
        Document::query()->sole()->delete();

        $this->post(route('submission.store'), $data)
            ->assertRedirect(route('submission.create'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('submission_notice');

        $this->assertSame(0, Document::query()->count());
        $this->followingRedirects()->post(route('submission.store'), $data)
            ->assertOk()
            ->assertSee('This form was already sent, and that submission was removed by the Records Unit.');

        // A new form (new key) goes through normally.
        $this->post(route('submission.store'), $this->submission((string) Str::uuid()))->assertSessionHasNoErrors();
        $this->assertSame(1, Document::query()->count());
    }

    public function test_removed_spam_still_counts_against_the_email_limit(): void
    {
        Notification::fake();
        config()->set('dts.client_email.submissions_per_address_per_day', 2);

        foreach (range(1, 2) as $ignored) {
            $this->post(route('submission.store'), [...$this->submission((string) Str::uuid()), 'email' => 'victim@example.com'])->assertSessionHasNoErrors();
        }
        Document::query()->get()->each->delete();

        $this->post(route('submission.store'), [...$this->submission((string) Str::uuid()), 'email' => 'victim@example.com'])
            ->assertSessionHasErrors('email');
    }

    /**
     * @return array<string, mixed>
     */
    private function submission(string $key): array
    {
        return [
            'submission_key' => $key,
            'name' => 'Juan Dela Cruz',
            'office' => 'Bayombong Central School',
            'delivery_method' => Document::DELIVERY_METHODS[0],
            'title' => 'Leave application',
            'type' => Document::typeOptions()[0],
            'privacy_consent' => '1',
        ];
    }
}
