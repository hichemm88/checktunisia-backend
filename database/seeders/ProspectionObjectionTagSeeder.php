<?php

namespace Database\Seeders;

use App\Models\Prospection\ObjectionTag;
use Illuminate\Database\Seeder;

class ProspectionObjectionTagSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'Je fais les fiches en 2 min',
            'Prix',
            "Et si la police n'accepte pas",
            'Trop compliqué',
            'Verra plus tard',
            'Autre',
        ] as $label) {
            ObjectionTag::firstOrCreate(['label' => $label], ['active' => true]);
        }
    }
}
