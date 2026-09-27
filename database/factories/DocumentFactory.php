<?php

namespace Database\Factories;

use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
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
            'status' => fake()->randomElement(Document::STATUSES),
            'received_at' => $receivedAt,
            'due_at' => fake()->optional(0.75)->dateTimeBetween($receivedAt, '+30 days'),
            'priority' => fake()->randomElement(['Normal', 'Normal', 'High', 'Urgent']),
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
