<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    public function create($attributes = [], ?Model $parent = null): Model|EloquentCollection
    {
        if (is_array($attributes) && array_key_exists('office', $attributes)) {
            $officeName = $attributes['office'];
            unset($attributes['office']);

            return $this->afterCreating(function (User $user) use ($officeName): void {
                if (filled($officeName)) {
                    if (! Office::query()->exists()) {
                        Office::query()->insertOrIgnore(array_map(
                            fn (string $name): array => ['name' => $name, 'sort_order' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
                            Document::OFFICES,
                        ));
                    }

                    $office = Office::query()->firstOrCreate(['name' => $officeName]);
                    $user->offices()->syncWithoutDetaching([$office->id]);
                }
            })->create($attributes, $parent);
        }

        return parent::create($attributes, $parent);
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
