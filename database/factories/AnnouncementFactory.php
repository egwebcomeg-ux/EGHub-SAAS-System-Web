<?php

namespace Database\Factories;

use App\Models\Announcement;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    /**
     * A live announcement for every restaurant.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'body' => fake()->sentence(12),
            'link' => null,
            'level' => 'info',
            'plan_id' => null,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addWeek(),
        ];
    }

    /** Only for the restaurants on $plan. */
    public function forPlan(Plan $plan): static
    {
        return $this->state(['plan_id' => $plan->id]);
    }

    public function warning(): static
    {
        return $this->state(['level' => 'warning']);
    }

    public function ended(): static
    {
        return $this->state(['starts_at' => now()->subWeeks(2), 'ends_at' => now()->subDay()]);
    }

    public function scheduled(): static
    {
        return $this->state(['starts_at' => now()->addDay(), 'ends_at' => now()->addWeek()]);
    }
}
