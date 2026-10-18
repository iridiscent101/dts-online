<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\KioskSigningRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Orchid\Platform\Models\Role;
use Tests\TestCase;

class KioskSigningTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAEAAAAAYCAYAAABKtPtEAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAQUlEQVRYhe3QsRGAMAwDQCcbpGMD9h8nG9AxQuhyTIC583+nTlIEAACU1N5hHOfKKvKl+5p7d88s8gflDwAAoKoH38oEBtR+TtAAAAAASUVORK5CYII=';

    public function test_recipient_signs_on_the_kiosk_and_the_clerk_releases_with_it(): void
    {
        $this->getJson(route('kiosk.current'))->assertUnauthorized();

        [$records, $document] = $this->releasableDocument();
        $kiosk = $this->kioskAccount();

        $sent = $this->actingAs($records)->postJson(route('platform.kiosk-signing.store', $document), [
            'source_office' => 'SDO-Records Unit',
            'recipient_name' => 'Juan',
        ])->assertCreated()->assertJsonPath('status', 'pending');
        $this->getJson($sent->json('status_url'))->assertOk()->assertJsonPath('status', 'pending')->assertJsonPath('recipient_signature', null);

        // The kiosk sees only the tracking number and type, never the title.
        $current = $this->actingAs($kiosk)->getJson(route('kiosk.current'))
            ->assertOk()
            ->assertJsonPath('request.tracking_number', $document->tracking_number)
            ->assertJsonPath('request.document_type', $document->document_type)
            ->assertJsonPath('request.recipient_name', 'Juan')
            ->assertDontSee($document->subject);

        $this->postJson($current->json('request.sign_url'), ['recipient_name' => 'Juan Dela Cruz'])
            ->assertUnprocessable()->assertJsonValidationErrors('recipient_signature');
        $this->postJson($current->json('request.sign_url'), ['recipient_name' => 'Juan Dela Cruz', 'recipient_signature' => 'data:image/png;base64,bm90IGEgcG5n'])
            ->assertUnprocessable()->assertJsonValidationErrors('recipient_signature');
        $this->postJson($current->json('request.sign_url'), ['recipient_name' => ' Juan Dela Cruz ', 'recipient_signature' => self::SIGNATURE])
            ->assertOk();

        // Signed requests leave the kiosk; the clerk's form receives the name and signature.
        $this->getJson(route('kiosk.current'))->assertOk()->assertJsonPath('request', null);
        $result = $this->actingAs($records)->getJson($sent->json('status_url'))
            ->assertOk()
            ->assertJsonPath('status', 'signed')
            ->assertJsonPath('recipient_name', 'Juan Dela Cruz')
            ->assertJsonPath('recipient_signature', self::SIGNATURE);
        $this->assertNotSame(self::SIGNATURE, KioskSigningRequest::query()->toBase()->value('recipient_signature'));

        $this->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => [
                'action' => 'released',
                'source_office' => 'SDO-Records Unit',
                'released_to' => $result->json('recipient_name'),
                'recipient_signature' => $result->json('recipient_signature'),
            ],
        ])->assertOk();

        $release = $document->movements()->latest('id')->first();
        $this->assertSame(DocumentMovement::ACTION_RELEASED, $release->action);
        $this->assertSame('Juan Dela Cruz', $release->released_to);
        $this->assertSame(self::SIGNATURE, $release->recipient_signature);
    }

    public function test_only_a_clerk_who_may_release_can_send_and_only_they_can_read_it(): void
    {
        [$records, $document] = $this->releasableDocument();
        $otherClerk = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $legal = User::factory()->create(['office' => 'SDO-Legal Unit', 'permissions' => ['platform.index' => true]]);
        $notYetReceived = Document::factory()->incoming()->create(['current_office' => 'SDO-Records Unit']);

        $this->actingAs($records)->postJson(route('platform.kiosk-signing.store', $notYetReceived), ['source_office' => 'SDO-Records Unit'])
            ->assertForbidden();
        $this->actingAs($legal)->postJson(route('platform.kiosk-signing.store', $document), ['source_office' => 'SDO-Records Unit'])
            ->assertNotFound();

        $sent = $this->actingAs($records)->postJson(route('platform.kiosk-signing.store', $document), ['source_office' => 'SDO-Records Unit'])
            ->assertCreated();
        $this->actingAs($otherClerk)->getJson($sent->json('status_url'))->assertNotFound();
        $this->postJson($sent->json('cancel_url'))->assertNotFound();
        $this->assertSame(0, KioskSigningRequest::query()->where('status', KioskSigningRequest::STATUS_CANCELLED)->count());
    }

    public function test_cancelled_replaced_and_expired_requests_cannot_be_signed(): void
    {
        [$records, $document] = $this->releasableDocument();
        $kiosk = $this->kioskAccount();
        $store = route('platform.kiosk-signing.store', $document);

        $first = $this->actingAs($records)->postJson($store, ['source_office' => 'SDO-Records Unit'])->assertCreated();
        $second = $this->postJson($store, ['source_office' => 'SDO-Records Unit'])->assertCreated();
        $this->getJson($first->json('status_url'))->assertJsonPath('status', 'cancelled');

        $current = $this->actingAs($kiosk)->getJson(route('kiosk.current'))->assertOk();
        $this->assertStringEndsWith('/'.KioskSigningRequest::query()->latest('id')->value('id').'/sign', $current->json('request.sign_url'));

        $this->actingAs($records)->postJson($second->json('cancel_url'))->assertOk()->assertJsonPath('status', 'cancelled');
        $this->actingAs($kiosk)->getJson(route('kiosk.current'))->assertJsonPath('request', null);
        $this->postJson($current->json('request.sign_url'), ['recipient_name' => 'Juan Dela Cruz', 'recipient_signature' => self::SIGNATURE])
            ->assertStatus(409);

        $third = $this->actingAs($records)->postJson($store, ['source_office' => 'SDO-Records Unit'])->assertCreated();
        $this->travel(KioskSigningRequest::LIFETIME_MINUTES + 1)->minutes();
        $this->getJson($third->json('status_url'))->assertJsonPath('status', 'expired');
        $this->actingAs($kiosk)->getJson(route('kiosk.current'))->assertJsonPath('request', null);
    }

    public function test_kiosk_page_is_only_for_the_kiosk_account_and_the_kiosk_account_only_sees_it(): void
    {
        $this->get(route('kiosk'))->assertRedirect(route('platform.login'));

        [$records] = $this->releasableDocument();
        $kiosk = $this->kioskAccount();
        $this->actingAs($records)->get(route('kiosk'))->assertForbidden();
        $this->actingAs($records)->getJson(route('kiosk.current'))->assertForbidden();

        $this->actingAs($kiosk)->get(route('kiosk'))
            ->assertOk()
            ->assertSee('Please wait')
            ->assertSee('<dts-signature-pad', false);
        // Signing in at /admin/login lands on the dashboard: the kiosk account is sent to its screen instead.
        $this->get(route('platform.main'))->assertRedirect(route('kiosk'));
        $this->get(route('platform.documents'))->assertRedirect(route('kiosk'));
    }

    public function test_the_recipient_may_sign_at_the_kiosk_without_typing_a_name(): void
    {
        [$records, $document] = $this->releasableDocument();
        $sent = $this->postJson(route('platform.kiosk-signing.store', $document), ['source_office' => 'SDO-Records Unit'])->assertCreated();

        $current = $this->actingAs($this->kioskAccount())->getJson(route('kiosk.current'))->assertJsonPath('request.recipient_name', null);
        $this->postJson($current->json('request.sign_url'), ['recipient_name' => '   ', 'recipient_signature' => self::SIGNATURE])->assertOk();

        $this->actingAs($records)->getJson($sent->json('status_url'))
            ->assertJsonPath('status', 'signed')
            ->assertJsonPath('recipient_name', null)
            ->assertJsonPath('recipient_signature', self::SIGNATURE);
    }

    public function test_a_user_given_the_kiosk_role_in_users_is_a_kiosk(): void
    {
        $role = Role::query()->where('slug', 'kiosk')->firstOrFail();
        $this->assertSame(['platform.kiosk' => true], $role->permissions);

        $user = User::factory()->create(['name' => 'Records Kiosk']);
        $user->replaceRoles([$role->id]);

        $this->actingAs($user->fresh())->get(route('platform.main'))->assertRedirect(route('kiosk'));
        $this->get(route('kiosk'))->assertOk()->assertSee('Please wait');
        $this->getJson(route('kiosk.current'))->assertOk()->assertJsonPath('request', null);
    }

    public function test_release_form_offers_send_to_kiosk(): void
    {
        [$records, $document] = $this->releasableDocument();

        $this->actingAs($records)->get(route('platform.documents.view', $document))
            ->assertOk()
            ->assertSee('data-kiosk-send', false)
            ->assertSee('data-kiosk-url="'.route('platform.kiosk-signing.store', $document).'"', false);
    }

    /**
     * @return array{0: User, 1: Document}
     */
    private function releasableDocument(): array
    {
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->incoming()->create(['current_office' => 'SDO-Records Unit', 'subject' => 'Private request title']);
        $this->actingAs($records)->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Records Unit'],
        ])->assertOk();

        return [$records, $document->fresh()];
    }

    private function kioskAccount(): User
    {
        return User::factory()->create(['name' => 'Records Kiosk', 'permissions' => ['platform.kiosk' => true]]);
    }
}
