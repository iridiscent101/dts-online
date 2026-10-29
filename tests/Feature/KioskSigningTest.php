<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\KioskSigningRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Orchid\Platform\Models\Role;
use RuntimeException;
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

        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertSee('Released to</dt><dd class="text-slate-800">Juan Dela Cruz</dd>', false)
            ->assertSee('Recipient signature</dt><dd class="text-slate-800">Signature received</dd>', false)
            ->assertDontSee(self::SIGNATURE);
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

    public function test_former_office_staff_cannot_retrieve_a_kiosk_signature_or_cancel_its_request(): void
    {
        [$records, $document] = $this->releasableDocument();
        $sent = $this->postJson(route('platform.kiosk-signing.store', $document), [
            'source_office' => 'SDO-Records Unit',
        ])->assertCreated();
        $signing = KioskSigningRequest::query()->sole();
        $this->actingAs($this->kioskAccount())->postJson(route('kiosk.sign', $signing), [
            'recipient_signature' => self::SIGNATURE,
        ])->assertOk();
        $records->offices()->detach();

        $this->actingAs($records)->getJson($sent->json('status_url'))->assertNotFound();
        $this->postJson($sent->json('cancel_url'))->assertNotFound();
    }

    public function test_kiosk_skips_requests_from_former_office_staff_and_rejects_their_signatures(): void
    {
        [$formerClerk, $oldDocument] = $this->releasableDocument();
        $this->postJson(route('platform.kiosk-signing.store', $oldDocument), [
            'source_office' => 'SDO-Records Unit',
        ])->assertCreated();
        $oldRequest = KioskSigningRequest::query()->sole();

        [, $currentDocument] = $this->releasableDocument();
        $this->postJson(route('platform.kiosk-signing.store', $currentDocument), [
            'source_office' => 'SDO-Records Unit',
        ])->assertCreated();
        $formerClerk->offices()->detach();

        $this->actingAs($this->kioskAccount())->getJson(route('kiosk.current'))
            ->assertOk()
            ->assertJsonPath('request.tracking_number', $currentDocument->tracking_number);
        $this->postJson(route('kiosk.sign', $oldRequest), [
            'recipient_signature' => self::SIGNATURE,
        ])->assertStatus(409);
        $this->assertSame(KioskSigningRequest::STATUS_PENDING, $oldRequest->fresh()->status);
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

    public function test_sending_a_replacement_invalidates_an_earlier_signed_request(): void
    {
        [$records, $document] = $this->releasableDocument();
        $url = route('platform.kiosk-signing.store', $document);
        $first = $this->postJson($url, ['source_office' => 'SDO-Records Unit'])->assertCreated();
        $signing = KioskSigningRequest::query()->sole();
        $this->actingAs($this->kioskAccount())->postJson(route('kiosk.sign', $signing), [
            'recipient_signature' => self::SIGNATURE,
        ])->assertOk();

        $this->actingAs($records)->postJson($url, ['source_office' => 'SDO-Records Unit'])->assertCreated();

        $this->assertSame(KioskSigningRequest::STATUS_CANCELLED, $signing->fresh()->status);
        $this->getJson($first->json('status_url'))
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('recipient_signature', null);
        $this->assertSame(1, KioskSigningRequest::query()->where('status', KioskSigningRequest::STATUS_PENDING)->count());
    }

    public function test_replacing_then_cancelling_a_signed_request_cannot_release_without_a_signature(): void
    {
        [$records, $document] = $this->releasableDocument();
        $kiosk = $this->kioskAccount();
        $storeUrl = route('platform.kiosk-signing.store', $document);
        $this->postJson($storeUrl, ['source_office' => 'SDO-Records Unit'])->assertCreated();
        $firstSigning = KioskSigningRequest::query()->sole();
        $this->actingAs($kiosk)->postJson(route('kiosk.sign', $firstSigning), [
            'recipient_signature' => self::SIGNATURE,
        ])->assertOk();

        $replacement = $this->actingAs($records)->postJson($storeUrl, ['source_office' => 'SDO-Records Unit'])->assertCreated();
        $this->postJson($replacement->json('cancel_url'))->assertOk();
        $this->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'released', 'source_office' => 'SDO-Records Unit'],
        ])->assertUnprocessable()->assertJsonValidationErrors('workflow.recipient_signature');

        $this->assertSame(DocumentMovement::STATUS_RECEIVED, $document->fresh()->status);
        $this->assertSame(KioskSigningRequest::STATUS_CANCELLED, $firstSigning->fresh()->status);
        $this->assertSame(1, $document->movements()->count());

        $this->postJson($storeUrl, ['source_office' => 'SDO-Records Unit'])->assertCreated();
        $newSigning = KioskSigningRequest::query()->latest('id')->firstOrFail();
        $this->actingAs($kiosk)->postJson(route('kiosk.sign', $newSigning), [
            'recipient_signature' => self::SIGNATURE,
        ])->assertOk();
        $this->actingAs($records)->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'released', 'source_office' => 'SDO-Records Unit', 'recipient_signature' => self::SIGNATURE],
        ])->assertOk();

        $this->assertSame(DocumentMovement::STATUS_RELEASED, $document->fresh()->status);
        $this->assertSame(self::SIGNATURE, $document->movements()->latest('id')->firstOrFail()->recipient_signature);
    }

    public function test_failed_replacement_keeps_the_original_kiosk_request_pending(): void
    {
        [$records, $document] = $this->releasableDocument();
        $store = route('platform.kiosk-signing.store', $document);
        $this->actingAs($records)->postJson($store, ['source_office' => 'SDO-Records Unit'])->assertCreated();
        $original = KioskSigningRequest::query()->sole();
        Event::listen('eloquent.created: '.KioskSigningRequest::class, static function (): void {
            throw new RuntimeException('The replacement could not be saved.');
        });

        $this->postJson($store, ['source_office' => 'SDO-Records Unit'])->assertInternalServerError();

        $this->assertSame(KioskSigningRequest::STATUS_PENDING, $original->fresh()->status);
        $this->assertSame(1, KioskSigningRequest::query()->count());
    }

    public function test_release_waits_for_a_pending_kiosk_signature_or_its_cancellation(): void
    {
        [$records, $document] = $this->releasableDocument();
        $sent = $this->postJson(route('platform.kiosk-signing.store', $document), [
            'source_office' => 'SDO-Records Unit',
        ])->assertCreated();

        $this->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'released', 'source_office' => 'SDO-Records Unit'],
        ])->assertUnprocessable()->assertJsonValidationErrors('workflow.recipient_signature');

        $this->assertSame(DocumentMovement::STATUS_RECEIVED, $document->fresh()->status);
        $this->assertSame(KioskSigningRequest::STATUS_PENDING, KioskSigningRequest::query()->sole()->status);

        $this->postJson($sent->json('cancel_url'))->assertOk()->assertJsonPath('status', 'cancelled');
        $this->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'released', 'source_office' => 'SDO-Records Unit'],
        ])->assertOk();

        $this->actingAs($this->kioskAccount())
            ->getJson(route('kiosk.current'))
            ->assertJsonPath('request', null);
        $this->postJson(route('kiosk.sign', KioskSigningRequest::query()->sole()), [
            'recipient_signature' => self::SIGNATURE,
        ])->assertStatus(409);
        $this->actingAs($records)->getJson($sent->json('status_url'))->assertJsonPath('status', 'cancelled');
    }

    public function test_release_cannot_discard_or_replace_a_signed_kiosk_signature(): void
    {
        [$records, $document] = $this->releasableDocument();
        $sent = $this->postJson(route('platform.kiosk-signing.store', $document), [
            'source_office' => 'SDO-Records Unit',
        ])->assertCreated();
        $signing = KioskSigningRequest::query()->sole();
        $this->actingAs($this->kioskAccount())->postJson(route('kiosk.sign', $signing), [
            'recipient_signature' => self::SIGNATURE,
        ])->assertOk();

        $url = route('platform.documents.movements.store', $document);
        $this->actingAs($records)->postJson($url, [
            'workflow' => ['action' => 'released', 'source_office' => 'SDO-Records Unit'],
        ])->assertUnprocessable()->assertJsonValidationErrors('workflow.recipient_signature');
        $this->assertSame(DocumentMovement::STATUS_RECEIVED, $document->fresh()->status);
        $this->assertSame(KioskSigningRequest::STATUS_SIGNED, $signing->fresh()->status);

        $this->postJson($url, [
            'workflow' => ['action' => 'released', 'source_office' => 'SDO-Records Unit', 'recipient_signature' => self::SIGNATURE],
        ])->assertOk();

        $this->assertSame(self::SIGNATURE, $document->movements()->latest('id')->firstOrFail()->recipient_signature);
    }

    public function test_forwarding_a_document_cancels_its_pending_kiosk_request(): void
    {
        [$records, $document] = $this->releasableDocument();
        $sent = $this->postJson(route('platform.kiosk-signing.store', $document), [
            'source_office' => 'SDO-Records Unit',
        ])->assertCreated();

        $this->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => [
                'action' => 'forwarded',
                'source_office' => 'SDO-Records Unit',
                'destinations' => ['SDO-Legal Unit'],
            ],
        ])->assertOk();

        $this->actingAs($this->kioskAccount())
            ->getJson(route('kiosk.current'))
            ->assertOk()
            ->assertJsonPath('request', null);
        $this->actingAs($records)->getJson($sent->json('status_url'))->assertJsonPath('status', 'cancelled');
    }

    public function test_adding_another_route_invalidates_a_signed_release_request(): void
    {
        [$records, $document] = $this->releasableDocument();
        $budget = User::factory()->create(['office' => 'SDO-Budget Unit', 'permissions' => ['platform.index' => true]]);
        $movementUrl = route('platform.documents.movements.store', $document);
        $this->postJson($movementUrl, [
            'workflow' => ['action' => 'forwarded', 'source_office' => 'SDO-Records Unit', 'destination' => 'SDO-Budget Unit'],
        ])->assertOk();
        $this->actingAs($budget)->postJson($movementUrl, [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Budget Unit'],
        ])->assertOk();
        $this->postJson($movementUrl, [
            'workflow' => ['action' => 'forwarded', 'source_office' => 'SDO-Budget Unit', 'destination' => 'SDO-Records Unit'],
        ])->assertOk();
        $this->actingAs($records)->postJson($movementUrl, [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Records Unit'],
        ])->assertOk();
        $this->postJson(route('platform.kiosk-signing.store', $document), [
            'source_office' => 'SDO-Records Unit',
        ])->assertCreated();
        $signing = KioskSigningRequest::query()->sole();
        $this->actingAs($this->kioskAccount())->postJson(route('kiosk.sign', $signing), [
            'recipient_signature' => self::SIGNATURE,
        ])->assertOk();
        $this->actingAs($records);

        $this->postJson(route('platform.documents.additional-routes.store', $document), [
            'source_office' => 'SDO-Records Unit',
            'destination_office' => 'SDO-Legal Unit',
            'reason' => 'Legal review is also needed.',
        ])->assertOk();

        $this->assertSame(KioskSigningRequest::STATUS_CANCELLED, $signing->fresh()->status);
        $this->assertNull($signing->fresh()->signed_at);
        $this->assertNull($signing->fresh()->recipient_signature);
        $this->actingAs($this->kioskAccount())->getJson(route('kiosk.current'))->assertJsonPath('request', null);
        $this->postJson(route('kiosk.sign', $signing), ['recipient_signature' => self::SIGNATURE])->assertStatus(409);
    }

    public function test_forwarding_after_kiosk_signing_invalidates_the_unreleased_signature(): void
    {
        [$records, $document] = $this->releasableDocument();
        $sent = $this->postJson(route('platform.kiosk-signing.store', $document), [
            'source_office' => 'SDO-Records Unit',
        ])->assertCreated();
        $signing = KioskSigningRequest::query()->sole();
        $this->actingAs($this->kioskAccount())->postJson(route('kiosk.sign', $signing), [
            'recipient_signature' => self::SIGNATURE,
        ])->assertOk();

        $this->actingAs($records)->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'forwarded', 'source_office' => 'SDO-Records Unit', 'destination' => 'SDO-Legal Unit'],
        ])->assertOk();

        $this->assertSame(KioskSigningRequest::STATUS_CANCELLED, $signing->fresh()->status);
        $this->assertNull($signing->fresh()->signed_at);
        $this->assertNull($signing->fresh()->recipient_signature);
        $this->getJson($sent->json('status_url'))
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('recipient_signature', null);
    }

    public function test_cancelled_signature_does_not_block_a_later_release(): void
    {
        [$records, $document] = $this->releasableDocument();
        $this->postJson(route('platform.kiosk-signing.store', $document), [
            'source_office' => 'SDO-Records Unit',
        ])->assertCreated();
        $signing = KioskSigningRequest::query()->sole();
        $this->actingAs($this->kioskAccount())->postJson(route('kiosk.sign', $signing), [
            'recipient_signature' => self::SIGNATURE,
        ])->assertOk();

        $legal = User::factory()->create(['office' => 'SDO-Legal Unit', 'permissions' => ['platform.index' => true]]);
        $movementUrl = route('platform.documents.movements.store', $document);
        $this->actingAs($records)->postJson($movementUrl, [
            'workflow' => ['action' => 'forwarded', 'source_office' => 'SDO-Records Unit', 'destination' => 'SDO-Legal Unit'],
        ])->assertOk();
        $this->assertSame(KioskSigningRequest::STATUS_CANCELLED, $signing->fresh()->status);
        $this->actingAs($legal)->postJson($movementUrl, [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Legal Unit'],
        ])->assertOk();
        $this->postJson($movementUrl, [
            'workflow' => ['action' => 'forwarded', 'source_office' => 'SDO-Legal Unit', 'destination' => 'SDO-Records Unit'],
        ])->assertOk();
        $this->actingAs($records)->postJson($movementUrl, [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Records Unit'],
        ])->assertOk();
        $this->postJson($movementUrl, [
            'workflow' => ['action' => 'released', 'source_office' => 'SDO-Records Unit'],
        ])->assertOk();

        $this->assertNull($document->movements()->latest('id')->firstOrFail()->recipient_signature);
    }

    public function test_removing_a_document_cancels_its_pending_kiosk_request(): void
    {
        [$records, $document] = $this->releasableDocument();
        $sent = $this->postJson(route('platform.kiosk-signing.store', $document), [
            'source_office' => 'SDO-Records Unit',
        ])->assertCreated();
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $admin = User::factory()->create(['office' => null]);
        $admin->replaceRoles([$adminRole->id]);

        $this->actingAs($admin)
            ->post(route('platform.documents.destroy', $document))
            ->assertRedirectToRoute('platform.documents');

        $this->assertSoftDeleted($document);
        $this->actingAs($this->kioskAccount())
            ->getJson(route('kiosk.current'))
            ->assertOk()
            ->assertJsonPath('request', null);
        $this->actingAs($records)->getJson($sent->json('status_url'))->assertNotFound();
    }

    public function test_kiosk_ignores_a_pending_request_for_an_already_removed_document(): void
    {
        [, $document] = $this->releasableDocument();
        $this->postJson(route('platform.kiosk-signing.store', $document), [
            'source_office' => 'SDO-Records Unit',
        ])->assertCreated();
        $signing = KioskSigningRequest::query()->sole();
        $document->delete();

        $this->actingAs($this->kioskAccount())
            ->getJson(route('kiosk.current'))
            ->assertOk()
            ->assertJsonPath('request', null);
        $this->postJson(route('kiosk.sign', $signing), [
            'recipient_signature' => self::SIGNATURE,
        ])->assertStatus(409);
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
            ->assertSee('<dts-signature-pad', false)
            ->assertSee('<dts-face-check', false)
            ->assertHeader('Permissions-Policy', 'camera=(self), geolocation=(), microphone=()');
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

    public function test_an_administrator_can_open_the_kiosk_and_still_use_the_staff_pages(): void
    {
        $admin = User::factory()->create();
        $admin->replaceRoles([Role::query()->firstOrCreate(['slug' => User::ADMIN_ROLE_SLUG], ['name' => 'Admin', 'permissions' => []])->id]);

        $this->actingAs($admin->fresh())->get(route('kiosk'))->assertOk()->assertSee('Please wait');
        $this->getJson(route('kiosk.current'))->assertOk();
        // Not sent to /kiosk like the kiosk account: the dashboard still opens.
        $this->get(route('platform.main'))->assertOk();
    }

    public function test_release_form_offers_send_to_kiosk(): void
    {
        [$records, $document] = $this->releasableDocument();

        $this->actingAs($records)->get(route('platform.documents.workspace', $document))
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
