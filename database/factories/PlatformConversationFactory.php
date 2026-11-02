<?php

namespace Database\Factories;

use App\Models\PlatformConversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformConversation>
 */
class PlatformConversationFactory extends Factory
{
    /**
     * Someone who wrote a minute ago: their 24-hour window is open.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel' => PlatformConversation::WHATSAPP,
            'phone' => '2010'.fake()->unique()->numerify('########'),
            'name' => fake()->name(),
            'status' => 'open',
            'last_message_at' => now()->subMinute(),
            'last_inbound_at' => now()->subMinute(),
        ];
    }
}
