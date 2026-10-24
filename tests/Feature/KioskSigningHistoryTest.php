<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\KioskSigningRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KioskSigningHistoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAEAAAAAYCAYAAABKtPtEAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAQUlEQVRYhe3QsRGAMAwDQCcbpGMD9h8nG9AxQuhyTIC583+nTlIEAACU1N5hHOfKKvKl+5p7d88s8gflDwAAoKoH38oEBtR+TtAAAAAASUVORK5CYII=';

    public function test_a_kiosk_signing_becomes_a_history_step_when_the_release_is_saved(): void
    {
        [$records, $document] = $this->signedAtKiosk();
        $signing = KioskSigningRequest::query()->sole();

        // Signing alone adds nothing yet.
        $this->assertFalse($document->activities()->where('description', 'Release signed')->exists());

        $this->travel(2)->minutes();
        $this->actingAs($records)->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'released', 'source_office' => 'SDO-Records Unit', 'released_to' => 'Ian Nico Perillo', 'recipient_signature' => self::SIGNATURE],
        ])->assertOk();

        $entry = $document->activities()->where('description', 'Release signed')->sole();
        $this->assertSame('Ian Nico Perillo', $entry->properties['signed_by']);
        $this->assertSame('Signing kiosk', $entry->properties['signed_on']);
        $this->assertSame($signing->id, $entry->properties['kiosk_signing_request_id']);
        // Dated when the client signed, so it comes just before the Release.
        $this->assertSame($signing->signed_at->toDateTimeString(), $entry->created_at->toDateTimeString());

        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertOk()
            ->assertDontSee('Ian Nico Perillo')
            ->assertDontSee('signed for the document/s')
            ->assertSee('Document registered');
        $this->actingAs($records)->get(route('platform.documents.workspace', $document))
            ->assertOk()
            ->assertSee('Signed for by the recipient')
            ->assertSee('Signing kiosk');

        // It belongs to the releasing office's history only, not to another route's.
        $workspace = route('platform.documents.workspace', $document);
        $this->get($workspace.'?history_page=1&office='.urlencode('SDO-Records Unit'))
            ->assertOk()->assertSee('Signed for by the recipient');
        $this->get($workspace.'?history_page=1&office='.urlencode('SDO-Budget Unit'))
            ->assertOk()->assertDontSee('Signed for by the recipient');
    }

    public function test_a_kiosk_signing_that_no_release_used_leaves_no_step(): void
    {
        [$records, $document] = $this->signedAtKiosk();

        // The clerk forwards the document instead of releasing it.
        $this->actingAs($records)->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'forwarded', 'source_office' => 'SDO-Records Unit', 'destinations' => ['SDO-Budget Unit']],
        ])->assertOk();

        $this->assertFalse($document->activities()->where('description', 'Release signed')->exists());
        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertOk()->assertDontSee('signed for the document/s');
    }

    public function test_backfill_adds_only_signings_a_release_used_and_only_once(): void
    {
        $requester = User::factory()->create();
        $released = Document::factory()->create();
        $unused = Document::factory()->create();
        $signedAt = now()->subDay()->startOfSecond();
        $usedSigning = $this->kioskSigning($released, $requester, $signedAt);
        $this->kioskSigning($unused, $requester, $signedAt);
        $released->movements()->create([
            'action' => 'released', 'from_office' => 'SDO-Records Unit', 'to_office' => 'SDO-Records Unit',
            'from_status' => 'Received', 'to_status' => 'Released', 'recipient_signature' => self::SIGNATURE,
        ]);
        $migration = require database_path('migrations/2026_10_22_134208_backfill_kiosk_release_signed_history.php');

        $migration->up();
        $migration->up();

        $entry = $released->activities()->where('description', 'Release signed')->sole();
        $this->assertSame($usedSigning->id, $entry->properties['kiosk_signing_request_id']);
        $this->assertSame($signedAt->toDateTimeString(), $entry->created_at->toDateTimeString());
        $this->assertSame(1, DB::table('activity_log')->where('description', 'Release signed')->count());
    }

    /**
     * @return array{0: User, 1: Document}
     */
    private function signedAtKiosk(): array
    {
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->incoming()->create(['current_office' => 'SDO-Records Unit']);
        $this->actingAs($records)->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Records Unit'],
        ])->assertOk();
        $this->postJson(route('platform.kiosk-signing.store', $document), ['source_office' => 'SDO-Records Unit'])->assertCreated();

        $kiosk = User::factory()->create(['name' => 'Records Kiosk', 'permissions' => ['platform.kiosk' => true]]);
        $this->actingAs($kiosk)->postJson(route('kiosk.sign', KioskSigningRequest::query()->sole()), [
            'recipient_name' => 'Ian Nico Perillo',
            'recipient_signature' => self::SIGNATURE,
        ])->assertOk();

        return [$records, $document->fresh()];
    }

    private function kioskSigning(Document $document, User $requester, Carbon $signedAt): KioskSigningRequest
    {
        return KioskSigningRequest::query()->create([
            'document_id' => $document->id,
            'requested_by' => $requester->id,
            'office' => 'SDO-Records Unit',
            'status' => KioskSigningRequest::STATUS_SIGNED,
            'recipient_name' => 'Juan Dela Cruz',
            'recipient_signature' => self::SIGNATURE,
            'signed_at' => $signedAt,
            'expires_at' => $signedAt->copy()->addMinutes(5),
        ]);
    }
}
