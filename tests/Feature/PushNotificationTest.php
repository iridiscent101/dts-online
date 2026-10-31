<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentPushSubscription;
use App\Models\User;
use App\Notifications\DocumentNotificationDispatcher;
use App\Services\FirebaseMessaging;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PushNotificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const TOKEN = 'fcm-device-token-abcdefghijklmnopqrstuvwxyz-0123456789';

    private const TEST_PRIVATE_KEY = <<<'PEM'
    -----BEGIN PRIVATE KEY-----
    MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQDmuD+sFqXTxnW5
    q21AwGFBKEkK/4/+4x2aIOaj/V2YYDJ3+fbIRnI2kS9iMrPLV/agh0E5xjvn6z78
    D/sRyJyfrfS7c9MVyyRthH7v66hbs/DAqXjVyrBWiI2ebya4O/Zmxle9y+AwmIXK
    J5xFQ8+otPHSnYa6RDtqQ7ntaXPQOZ7LKA5rYYMuPw9GkYEnCt1AIR7luRE2iYxC
    iaHli+KZIKxZdtsqBg0IzS6LhcMU2kzu3Bw8kzoSYq8qg7f1iab3sChp2Lik7tA8
    PwCR8cgHia5fkIrW3aWZLeE+ziqK/E105oGtntpmD/ivHNooAgGiVFfuZAh5kG1/
    F+T42iO7AgMBAAECggEAL/O5YBXw7zv/OdC0S1HwpK8vxzZogixH+cG+zfSZ4FKW
    CrOMJAHoOQkh1QH+tlOY8bJKzywxRTlyhMb5gq+0RcfM5qzjAVOQRabVDx5mhnC2
    qARRFfYjgkitTnGBlab4xlGQ6OSXuuHoZKSo6Qh3/3NkE0ckOlB2HNborLxPEsaR
    /jV7CUVM4cgFq+uIspQSI1nvSht8HFYWNhdRtqlQflaLJJT7rTpUZGjE/KDXMXB5
    H8IlqfaMhFKL+RsyrEFDMOMH9QVCwhmZnwKXobP3JhAmMiG2Gjqyf2B4zrobbGJl
    OM31dgREvgufgOv3Svt/Eu6gkxzxBjw1d8m1jFpDGQKBgQD24ns5a7jSGuotpQlJ
    AOKLVACTpUOhcGSev5tApyA7FtJ/aasgdbqH6vGPxKrmpPPpfbJOR1rZrlPVcfrJ
    qGq8hnYj/gudRc/6Koq0E6E6AldCmUw+NjxAzNZdqfG4xKL8JkLQtrki6mwRlEwf
    00AAAZgD80uZ1m/tv8kexS64ZQKBgQDvPPp3sEiOD0+0UYnv018+LiHPV+/0NBai
    N6Fh5umrKWNKoTHfYx8T/1UjWIP/CFRmofyAd0/Ss5ONjZ+x9l3U2Ddjk6Q216SV
    SrQ6+KyrYHUeKqs2sgTlohelk7dcK7E6F5sPQcecyfM5W6qW+sYQniqaza2e0rED
    /Erlb+jZnwKBgBcLGp9Ia67IrlTmU5CJ0ZtZktg1E7Pszqd2kOIe90YsoiccUMmt
    02Q+TJ+zppPyj4WaotrllBg/Sjng8pyB6LyB/GLtol3ilz/tWpbmQiUs9MIVluUz
    oLoXpGaHpzUsvV21oITkmTEmGkIlylkqGY4ml+6+da79MeNKBFan5u11AoGAbtSo
    EDS5mB5dxWKU7WbV07+ZaC4L+uz6o1H0UJ+iFupIlnVQ3nVUG3aJYaOLGuYn+5gF
    mxoq/6izrbszhUVmOMml91qZk9KY65S9JCi6DXbVKxGHWgJkpGFQZSOmqWuQYiI3
    cnz91ZP1OthNkj5A7cfoYujwCwj+eqMxq1MJ428CgYEA3SStZHE8+v5UdubC4hkt
    QOqbA2ryB3+vE9hb8AmEhNBnabPUQgvitYr1KPU/qYCLuV/nsU0jZB/uLdWFkHNp
    yMzQHxaHrVJOv+qqV7x7knTM28CNrZ2GxQ0lLtFXFgBjMQlnyTvdric4D50HGne7
    4GI7UJfw3bFK+7AXCuTpWJI=
    -----END PRIVATE KEY-----
    PEM;

    /** A throwaway service account with a freshly generated key, so the real signing code runs. */
    private function configureFirebase(): void
    {
        // A throwaway key made only for these tests (it is not tied to any Firebase project).
        $privateKey = self::TEST_PRIVATE_KEY;
        config([
            'services.firebase.project_id' => 'docutracks-test',
            'services.firebase.credentials' => null,
            'services.firebase.credentials_base64' => base64_encode(json_encode([
                'type' => 'service_account',
                'project_id' => 'docutracks-test',
                'client_email' => 'push@docutracks-test.iam.gserviceaccount.com',
                'private_key' => $privateKey,
                'token_uri' => 'https://oauth2.googleapis.com/token',
            ])),
        ]);
        Cache::flush();
        $this->app->forgetInstance(FirebaseMessaging::class);
    }

    private function clientDocument(string $tracking = 'SDONV11122233344'): Document
    {
        return Document::factory()->received()->create(['tracking_number' => $tracking, 'subject' => 'Travel request']);
    }

    public function test_a_client_turns_updates_on_and_off_for_a_tracking_number_without_signing_in(): void
    {
        $document = $this->clientDocument();

        $this->postJson(route('tracking.updates.store', 'sdonv11122233344'), ['token' => self::TOKEN])
            ->assertOk()->assertJsonPath('message', 'You will get updates on SDONV11122233344 on this phone.');
        $this->assertDatabaseHas('document_push_subscriptions', ['document_id' => $document->id, 'token' => self::TOKEN]);

        // Turning it on again does not add a second row.
        $this->postJson(route('tracking.updates.store', $document->tracking_number), ['token' => self::TOKEN])->assertOk();
        $this->assertSame(1, DocumentPushSubscription::query()->count());

        $this->deleteJson(route('tracking.updates.destroy', $document->tracking_number), ['token' => self::TOKEN])->assertOk();
        $this->assertDatabaseMissing('document_push_subscriptions', ['token' => self::TOKEN]);
    }

    public function test_unknown_tracking_numbers_and_bad_tokens_are_refused(): void
    {
        $this->clientDocument();

        $this->postJson(route('tracking.updates.store', 'SDONV99999999999'), ['token' => self::TOKEN])->assertNotFound();
        $this->postJson(route('tracking.updates.store', 'not-a-number'), ['token' => self::TOKEN])->assertNotFound();
        $this->postJson(route('tracking.updates.store', 'SDONV11122233344'), ['token' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->assertSame(0, DocumentPushSubscription::query()->count());
    }

    public function test_one_document_accepts_only_a_few_phones(): void
    {
        $document = $this->clientDocument();
        foreach (range(1, DocumentPushSubscription::MAX_PER_DOCUMENT) as $phone) {
            $document->pushSubscriptions()->create(['token' => self::TOKEN.'-'.$phone]);
        }

        $this->postJson(route('tracking.updates.store', $document->tracking_number), ['token' => self::TOKEN.'-new'])
            ->assertUnprocessable()->assertJsonValidationErrors('token');
        // A phone already on the list can still refresh its token.
        $this->postJson(route('tracking.updates.store', $document->tracking_number), ['token' => self::TOKEN.'-1'])->assertOk();
    }

    public function test_the_tracking_page_offers_updates_on_this_phone(): void
    {
        $document = $this->clientDocument();

        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertOk()
            ->assertSee('Get updates on this phone')
            ->assertSee('data-url="'.route('tracking.updates.store', $document->tracking_number).'"', false)
            ->assertSee('name="csrf-token"', false);
    }

    public function test_right_after_submitting_the_prompt_is_highlighted_and_shown_once(): void
    {
        $document = $this->clientDocument();

        $html = $this->withSession(['submitted' => true])
            ->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Document submitted.', $html, 'banner');
        $this->assertStringContainsString('Want updates on this phone?', $html, 'prompt');
        $this->assertLessThan(strpos($html, 'Want updates on this phone?'), strpos($html, 'Document submitted.'), 'prompt comes after the banner');
        $this->assertSame(1, substr_count($html, 'data-client-updates '));

        // A later visit (the "submitted" message is shown once) shows the regular box instead.
        $this->flushSession();
        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertOk()
            ->assertDontSee('Want updates on this phone?')
            ->assertSee('Get a notification on this phone when your document moves or is ready.');
    }

    public function test_firebase_signs_in_with_the_service_account_and_sends_the_push(): void
    {
        $this->configureFirebase();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'google-access-token', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/docutracks-test/messages/1']),
        ]);

        $result = app(FirebaseMessaging::class)->send(self::TOKEN, 'New document', 'Body text', 'https://example.test/open');

        $this->assertSame(FirebaseMessaging::SENT, $result);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'oauth2.googleapis.com/token')
            && $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
            && substr_count((string) $request['assertion'], '.') === 2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://fcm.googleapis.com/v1/projects/docutracks-test/messages:send'
            && $request->hasHeader('Authorization', 'Bearer google-access-token')
            && $request['message']['token'] === self::TOKEN
            && $request['message']['notification']['title'] === 'New document'
            && $request['message']['webpush']['fcm_options']['link'] === 'https://example.test/open');
    }

    public function test_without_a_service_account_nothing_is_sent(): void
    {
        config(['services.firebase.credentials' => null, 'services.firebase.credentials_base64' => null]);
        Http::fake();

        $this->assertFalse(app(FirebaseMessaging::class)->configured());
        $this->assertSame(FirebaseMessaging::FAILED, app(FirebaseMessaging::class)->send(self::TOKEN, 'Title', 'Body'));
        Http::assertNothingSent();
    }

    public function test_routing_pushes_to_the_clients_phones_and_drops_dead_tokens(): void
    {
        $this->withoutDefer();
        $this->configureFirebase();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'google-access-token', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::sequence()
                ->push(['name' => 'projects/docutracks-test/messages/1'])
                ->push(['error' => ['code' => 404, 'message' => 'Requested entity was not found.', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
        ]);
        $actor = User::factory()->create(['office' => 'SDO-Records Unit']);
        $document = $this->clientDocument();
        $otherDocument = $this->clientDocument('SDONV55566677788');
        $document->pushSubscriptions()->create(['token' => self::TOKEN.'-phone']);
        $document->pushSubscriptions()->create(['token' => self::TOKEN.'-old-phone']);
        $otherDocument->pushSubscriptions()->create(['token' => self::TOKEN.'-someone-else']);

        app(DocumentNotificationDispatcher::class)->assigned($document, 'forwarded', $actor, ['SDO-Budget Unit', 'SDO-Legal Unit']);

        $pushes = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'fcm.googleapis.com'))->values();
        $this->assertSame([self::TOKEN.'-phone', self::TOKEN.'-old-phone'], $pushes->map(fn ($pair) => $pair[0]['message']['token'])->all());
        $message = $pushes->first()[0]['message'];
        $this->assertSame('Your document SDONV11122233344 was routed', $message['notification']['title']);
        $this->assertSame('It was routed to the SDO-Budget Unit and SDO-Legal Unit.', $message['notification']['body']);
        $this->assertSame(route('tracking.index', ['tracking' => 'SDONV11122233344']), $message['webpush']['fcm_options']['link']);

        // The dead phone is removed; the working one is marked used; another document's phone got nothing.
        $this->assertDatabaseMissing('document_push_subscriptions', ['token' => self::TOKEN.'-old-phone']);
        $this->assertNotNull(DocumentPushSubscription::query()->where('token', self::TOKEN.'-phone')->value('last_used_at'));
        $this->assertDatabaseHas('document_push_subscriptions', ['token' => self::TOKEN.'-someone-else', 'last_used_at' => null]);
    }

    public function test_release_pushes_to_the_client_even_without_an_email_address(): void
    {
        $this->withoutDefer();
        $this->configureFirebase();
        config(['mail.document_assignment_notifications_enabled' => false]);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'google-access-token', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/docutracks-test/messages/1']),
        ]);
        $document = $this->clientDocument();
        $document->update(['client_email' => null]);
        $document->pushSubscriptions()->create(['token' => self::TOKEN]);

        app(DocumentNotificationDispatcher::class)->finished($document, 'released', 'SDO-Records Unit');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'fcm.googleapis.com')
            && $request['message']['notification']['title'] === 'Your document SDONV11122233344 was released'
            && $request['message']['notification']['body'] === 'It was released by the SDO-Records Unit.');
    }
}
