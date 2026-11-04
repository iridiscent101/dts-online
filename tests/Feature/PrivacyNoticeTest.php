<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivacyNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_full_privacy_notice_has_its_own_page(): void
    {
        config(['dts.contact.email' => 'records@example.test']);

        $this->get(route('privacy.notice'))
            ->assertOk()
            ->assertSee('<title>Privacy notice | DocuTrackS</title>', false)
            ->assertSeeInOrder(['Who we are', 'What we collect', 'Why we use it', 'Who can see it', 'Email and phone updates', 'Cookies', 'How long we keep it', 'Your rights', 'Contact us'])
            ->assertSee('Data Privacy Act of 2012')
            ->assertSee('National Privacy Commission')
            ->assertSee('records@example.test');
    }

    public function test_the_send_form_opens_the_full_notice_in_a_dialog(): void
    {
        $this->get(route('submission.create'))
            ->assertOk()
            ->assertSee('href="'.route('privacy.notice').'" data-open-privacy', false)
            ->assertSee('id="privacy-dialog"', false)
            ->assertSee('Who can see it')
            // The form itself no longer repeats the notice: only the heading, the link and the consent box.
            ->assertDontSee('<p class="mt-2 text-sm text-slate-700">We use your name', false);

        // The tracking page embeds the same form, so its dialog carries the notice too.
        $this->get(route('tracking.index'))
            ->assertOk()
            ->assertSee('id="privacy-dialog"', false);
    }

    public function test_the_attachment_line_follows_the_upload_setting(): void
    {
        config(['app.document_uploads_enabled' => false]);
        $this->get(route('privacy.notice'))->assertDontSee('The file you attach');

        config(['app.document_uploads_enabled' => true]);
        $this->get(route('privacy.notice'))->assertSee('The file you attach');
    }
}
