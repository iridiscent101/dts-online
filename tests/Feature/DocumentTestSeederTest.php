<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Database\Seeders\DocumentTestSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentTestSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_seeds_documents_with_private_viewer_files_without_duplicates(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->seed(DocumentTestSeeder::class);
        $this->seed(DocumentTestSeeder::class);

        $this->assertDatabaseCount('documents', 40);
        $this->assertDatabaseCount('attachmentable', 40);
        $this->assertDatabaseCount('attachments', 40);

        $firstDocument = Document::query()
            ->where('tracking_number', 'DTS-2026-TEST-001')
            ->with('attachments')
            ->firstOrFail();

        $this->assertSame($user->id, $firstDocument->registered_by);
        $this->assertSame(['test_1.pdf'], $firstDocument->attachments->pluck('original_name')->all());
        $this->assertSame(
            0,
            Document::query()->withCount('attachments')->get()->where('attachments_count', '!=', 1)->count(),
        );
        $this->assertDatabaseHas('attachments', ['original_name' => 'test_!.xlsx']);

        foreach ($firstDocument->attachments as $attachment) {
            $this->assertSame('local', $attachment->disk);
            Storage::disk('local')->assertExists($attachment->physicalPath());
        }

        $this->assertSame(10, Document::query()->where('status', 'Awaiting receipt')->count());
        $this->assertSame(10, Document::query()->where('status', 'Received')->count());
        $this->assertSame(10, Document::query()->where('status', 'Forwarded')->count());
        $this->assertSame(10, Document::query()->where('status', 'Archived')->count());
    }
}
