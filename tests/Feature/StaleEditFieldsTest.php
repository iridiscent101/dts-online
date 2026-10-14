<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class StaleEditFieldsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_edit_modal_and_edit_page_send_the_details_fingerprint_once(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true, 'platform.documents.edit' => true],
        ]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit', 'registered_by' => $user->id]);
        $detailsField = 'name="document[details_fingerprint]" value="'.$document->detailsFingerprint().'"';

        $workspace = $this->actingAs($user)->get(route('platform.documents.workspace', $document))->assertOk();
        $this->assertSame(1, substr_count($workspace->getContent(), $detailsField));

        $editPage = $this->get(route('platform.documents.edit', $document))->assertOk();
        $this->assertSame(1, substr_count($editPage->getContent(), $detailsField));
    }
}
