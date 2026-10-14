<?php

namespace Database\Factories;

use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (Document $document): void {
            if (! Schema::hasTable('document_office_assignments')) {
                return;
            }

            $document->officeAssignments()->updateOrCreate(
                ['office' => $document->current_office],
                ['status' => $document->status],
            );
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $receivedAt = fake()->dateTimeBetween('-90 days', 'now');

        return [
            'tracking_number' => sprintf('DTS-%s-%04d', now()->year, fake()->unique()->numberBetween(1, 9999)),
            'subject' => Str::headline(fake()->unique()->words(6, true)),
            'document_type' => fake()->randomElement(Document::TYPES),
            'origin' => fake()->company(),
            'sender' => fake()->company(),
            'current_office' => fake()->randomElement(Document::OFFICES),
            // Archived documents are locked closed records, so tests ask for one with archived() rather than by chance.
            'status' => fake()->randomElement([
                'Awaiting receipt',
                'Received',
                'Forwarded',
            ]),
            'received_at' => $receivedAt,
            'due_at' => fake()->optional(0.75)->dateTimeBetween($receivedAt, '+30 days'),
            'priority' => fake()->randomElement(['Standard', 'Standard', 'Extended', 'Urgent']),
        ];
    }

    public function incoming(): static
    {
        return $this->state(fn (): array => ['status' => 'Awaiting receipt']);
    }

    public function outgoing(): static
    {
        return $this->state(fn (): array => ['status' => 'Forwarded']);
    }

    public function received(): static
    {
        return $this->state(fn (): array => ['status' => 'Received']);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['status' => 'Archived', 'due_at' => null]);
    }
}
