<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentType;
use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DocumentTypeSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_seeds_the_deped_document_types_without_duplicates(): void
    {
        $this->seed(DocumentTypeSeeder::class);
        $this->seed(DocumentTypeSeeder::class);

        $this->assertDatabaseCount('document_types', 135);
        $this->assertDatabaseHas('document_types', [
            'name' => 'Advisory',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('document_types', [
            'name' => 'Annual Implementation Plan (AIP)',
            'sort_order' => 2,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('document_types', [
            'name' => 'Complaint Letter',
            'sort_order' => 26,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('document_types', [
            'name' => 'Establishment of New Private School',
            'sort_order' => 41,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('document_types', [
            'name' => 'Others',
            'sort_order' => 135,
            'is_active' => true,
        ]);

        foreach (['Central Office Memorandum', 'Division Bulletin', 'Office Memorandum', 'DepEd Order', 'Division Order'] as $name) {
            $this->assertDatabaseHas('document_types', ['name' => $name, 'is_active' => true]);
        }

        $this->assertSame(135, DocumentType::query()->distinct()->count('name'));
    }

    public function test_it_preserves_document_types_not_owned_by_the_deped_list(): void
    {
        DocumentType::query()->create([
            'name' => 'Local Testing Type',
            'sort_order' => 999,
            'is_active' => false,
        ]);

        $this->seed(DocumentTypeSeeder::class);

        $this->assertDatabaseCount('document_types', 136);
        $this->assertDatabaseHas('document_types', [
            'name' => 'Local Testing Type',
            'sort_order' => 999,
            'is_active' => false,
        ]);
    }

    public function test_it_reuses_the_existing_form_six_jhs_type(): void
    {
        DocumentType::query()->create([
            'name' => 'Form 6-JHS (Travel Abroad, Maternity Leave, Paternity Leave etc.)',
            'sort_order' => 43,
            'is_active' => true,
        ]);

        $this->seed(DocumentTypeSeeder::class);

        $this->assertDatabaseCount('document_types', 135);
        $this->assertDatabaseMissing('document_types', [
            'name' => 'Form 6-JHS  (Travel Abroad, Maternity Leave, Paternity Leave etc.)',
        ]);
    }

    public function test_document_type_options_use_active_seeded_types_in_sort_order(): void
    {
        DocumentType::query()->create([
            'name' => 'Inactive Type',
            'sort_order' => 1,
            'is_active' => false,
        ]);

        $this->seed(DocumentTypeSeeder::class);

        $types = Document::typeOptions();

        $this->assertSame('Advisory', $types[0]);
        $this->assertSame('Others', $types[134]);
        $this->assertFalse(in_array('Inactive Type', $types, true));
    }
}
