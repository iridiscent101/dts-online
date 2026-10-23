<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Support\MailDomain;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\FakeMailDomain;
use Tests\TestCase;

class MailDomainCheckTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const MESSAGE = 'This email address cannot receive email. Check the part after @ (for example gmail.com), or leave it blank.';

    public function test_the_public_form_refuses_a_domain_that_cannot_receive_mail(): void
    {
        Notification::fake();

        $this->post(route('submission.store'), $this->submission('juan@gmial.com'))
            ->assertSessionHasErrors(['email' => self::MESSAGE]);
        $this->assertDatabaseCount('documents', 0);

        $this->post(route('submission.store'), $this->submission('juan@gmail.com'))->assertSessionHasNoErrors();
        $this->assertSame('juan@gmail.com', Document::query()->sole()->client_email);
    }

    public function test_a_badly_formed_address_gets_one_message_and_no_lookup(): void
    {
        $this->post(route('submission.store'), $this->submission('juan@gmail'))
            ->assertSessionHasErrors(['email' => 'Enter a valid email address, or leave it blank.']);

        $this->assertSame([], $this->mailDomains->lookups);
    }

    public function test_staff_registration_and_recovery_email_are_checked_too(): void
    {
        Notification::fake();
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);

        $this->actingAs($records)->post(route('platform.documents.create').'/save', ['document' => [
            'title' => 'Memo', 'type' => Document::typeOptions()[0], 'origin' => 'School', 'sender' => 'Juan',
            'client_email' => 'juan@nomail.example', 'received' => today('Asia/Manila')->toDateString(),
            'destinations' => ['SDO-Budget Unit'],
        ]])->assertSessionHasErrors(['document.client_email' => self::MESSAGE]);

        $this->post(route('platform.profile').'/save', [
            'user' => ['name' => $records->name, 'email' => $records->email, 'notification_email' => 'clerk@nomail.example'],
        ])->assertSessionHasErrors(['user.notification_email' => self::MESSAGE]);
        $this->assertNull($records->fresh()->notification_email);
    }

    public function test_an_unchanged_saved_address_is_not_looked_up_again(): void
    {
        $staff = User::factory()->create(['office' => 'Records Unit', 'permissions' => ['platform.index' => true, 'platform.documents.edit' => true]]);
        // Saved before its domain stopped taking mail.
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit', 'client_email' => 'old@nomail.example', 'received_at' => '2026-08-03']);
        $details = [
            'details_fingerprint' => $document->detailsFingerprint(), 'title' => 'Corrected title', 'type' => $document->document_type,
            'origin' => $document->origin, 'sender' => $document->sender, 'received' => '2026-08-03',
        ];

        // Editing other details with the email left as it is still works.
        $this->actingAs($staff)->post(route('platform.documents.edit', $document).'/save', ['document' => [...$details, 'client_email' => 'old@nomail.example']])
            ->assertSessionHasNoErrors();
        $this->assertSame([], $this->mailDomains->lookups);

        // Changing it to another dead domain is refused.
        $this->post(route('platform.documents.edit', $document).'/save', ['document' => [...$details, 'details_fingerprint' => $document->fresh()->detailsFingerprint(), 'client_email' => 'new@gmial.com']])
            ->assertSessionHasErrors(['document.client_email' => self::MESSAGE]);
    }

    public function test_lookups_are_cached_and_a_dns_outage_lets_addresses_through(): void
    {
        $domains = app(MailDomain::class);
        $this->assertTrue($domains->acceptsMail('a@deped.gov.ph'));
        $this->assertTrue($domains->acceptsMail('b@DepEd.gov.ph'));
        $this->assertSame(['deped.gov.ph'], $this->mailDomains->lookups);

        // When even a domain that always takes mail cannot be found, DNS is down: accept rather than turn people away.
        $offline = new FakeMailDomain;
        $offline->withoutMail = ['offline-check.ph', 'gmail.com'];
        $this->assertTrue($offline->acceptsMail('x@offline-check.ph'));

        // When DNS answers, a domain without mail is refused.
        $online = new FakeMailDomain;
        $online->withoutMail = ['no-mail-here.ph'];
        $this->assertFalse($online->acceptsMail('x@no-mail-here.ph'));
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
