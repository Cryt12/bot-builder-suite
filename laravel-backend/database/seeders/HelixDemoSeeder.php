<?php

namespace Database\Seeders;

use App\Models\Chatbot;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class HelixDemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'demo@helix.local'],
            [
                'name' => 'Helix Demo',
                'password' => Hash::make('password'),
                'role' => 'admin',
            ],
        );

        Chatbot::firstOrCreate(
            [
                'user_id' => $user->id,
                'name' => 'Acme Support Bot',
            ],
            [
                'welcome_message' => 'Hi! Ask me anything about Acme.',
                'system_prompt' => 'You are Acme Support Bot. Answer using only the knowledge base context. If the answer is missing, say you do not have that information yet.',
                'primary_color' => '#a970ff',
                'bubble_position' => 'right',
                'tone' => 'friendly',
                'language' => 'auto',
                'collect_email' => true,
                'is_active' => true,
            ],
        );
    }
}
