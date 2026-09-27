<?php

namespace Database\Seeders;

use App\Models\Document;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Orchid\Attachment\File;
use RuntimeException;

class DocumentTestSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $userId = User::query()->value('id');
        $baseDate = CarbonImmutable::create(2026, 9, 14);

        $this->removeLegacyGroupedDocuments();

        foreach ($this->fileDefinitions() as $offset => $file) {
            $sequence = $offset + 1;
            $receivedAt = $baseDate->addDays($offset);
            $status = Document::STATUSES[$offset % count(Document::STATUSES)];

            $document = Document::query()->updateOrCreate(
                ['tracking_number' => sprintf('DTS-2026-TEST-%03d', $sequence)],
                [
                    'subject' => sprintf('Test %s document %d', $file['label'], $file['number']),
                    'document_type' => $file['document_type'],
                    'external_reference' => sprintf('TEST-FILE-%03d', $sequence),
                    'description' => "Seeded document for testing {$file['name']} in the file viewer.",
                    'origin' => 'Seeder Test Files',
                    'sender' => 'Test Records Officer',
                    'current_office' => Document::OFFICES[$offset % count(Document::OFFICES)],
                    'status' => $status,
                    'received_at' => $receivedAt,
                    'due_at' => $status === 'Archived' ? null : $receivedAt->addDays(7),
                    'priority' => ['Normal', 'High', 'Urgent'][$offset % 3],
                    'remarks' => 'Created by DocumentTestSeeder for local acceptance testing.',
                    'registered_by' => $userId,
                ],
            );

            if ($document->attachments()->where('original_name', $file['name'])->exists()) {
                continue;
            }

            $path = base_path('test_files/'.$file['name']);

            if (! is_file($path)) {
                throw new RuntimeException("Missing test attachment: {$path}");
            }

            $attachment = (new File(
                new UploadedFile($path, $file['name'], null, null, true),
                'local',
                'documents',
            ))->load();

            $attachment->update(['user_id' => $userId]);
            $document->attachments()->attach($attachment);
        }
    }

    /**
     * @return array<int, array{name: string, number: int, label: string, document_type: string}>
     */
    private function fileDefinitions(): array
    {
        $types = [
            'pdf' => ['label' => 'PDF', 'document_type' => 'Report'],
            'docx' => ['label' => 'Word', 'document_type' => 'Memorandum'],
            'png' => ['label' => 'image', 'document_type' => 'Other'],
            'xlsx' => ['label' => 'Excel', 'document_type' => 'Report'],
        ];

        $files = [];

        for ($number = 1; $number <= 10; $number++) {
            foreach ($types as $extension => $metadata) {
                $files[] = [
                    'name' => $number === 1 && $extension === 'xlsx'
                        ? 'test_!.xlsx'
                        : "test_{$number}.{$extension}",
                    'number' => $number,
                    'label' => $metadata['label'],
                    'document_type' => $metadata['document_type'],
                ];
            }
        }

        return $files;
    }

    private function removeLegacyGroupedDocuments(): void
    {
        Document::query()
            ->where('tracking_number', 'like', 'DTS-2026-SEED-%')
            ->with('attachments')
            ->get()
            ->each(function (Document $document): void {
                foreach ($document->attachments as $attachment) {
                    $attachment->delete();
                }

                $document->delete();
            });
    }
}
