<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentMovement>
 */
class DocumentMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $fromOffice = fake()->randomElement(Document::OFFICES);
        $toOffice = fake()->randomElement(array_values(array_diff(Document::OFFICES, [$fromOffice])));

        return [
            'document_id' => Document::factory(),
            'action' => 'forward',
            'from_office' => $fromOffice,
            'to_office' => $toOffice,
            'from_status' => 'Received',
            'to_status' => 'Forwarded',
            'remarks' => fake()->optional()->sentence(),
            'acted_by' => User::factory(),
        ];
    }
}
