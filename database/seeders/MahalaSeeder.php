<?php

namespace Database\Seeders;

use App\Models\Mahala;
use Illuminate\Database\Seeder;

class MahalaSeeder extends Seeder
{
    public function run(): void
    {
        $mahalas = [
            [
                'id' => 'user-dobrinja',
                'name' => 'Dobrinja',
                'level' => 1,
                'latitude' => 43.8302776,
                'longitude' => 18.3470619,
                'coordinates' => [
                    ['latitude' => 43.8358201, 'longitude' => 18.3435813],
                    ['latitude' => 43.8335460, 'longitude' => 18.3376063],
                    ['latitude' => 43.8222048, 'longitude' => 18.3514629],
                    ['latitude' => 43.8271918, 'longitude' => 18.3666189],
                    ['latitude' => 43.8336535, 'longitude' => 18.3461465],
                ],
                'holes' => [],
            ],
            [
                'id' => 'user-bascarsija',
                'name' => 'Bascarsija',
                'level' => 1,
                'latitude' => 43.8592315,
                'longitude' => 18.4277221,
                'coordinates' => [
                    ['latitude' => 43.8595930, 'longitude' => 18.4205498],
                    ['latitude' => 43.8607659, 'longitude' => 18.4256100],
                    ['latitude' => 43.8599891, 'longitude' => 18.4315555],
                    ['latitude' => 43.8568444, 'longitude' => 18.4268789],
                    ['latitude' => 43.8565258, 'longitude' => 18.4203596],
                ],
                'holes' => [],
            ],
            [
                'id' => 'user-aerodromsko-naselje',
                'name' => 'Aerodromsko naselje',
                'level' => 2,
                'latitude' => 43.8288961,
                'longitude' => 18.3385307,
                'coordinates' => [
                    ['latitude' => 43.8315772, 'longitude' => 18.3365874],
                    ['latitude' => 43.8294600, 'longitude' => 18.3351694],
                    ['latitude' => 43.8264240, 'longitude' => 18.3402933],
                    ['latitude' => 43.8279048, 'longitude' => 18.3416972],
                    ['latitude' => 43.8288535, 'longitude' => 18.3426569],
                ],
                'holes' => [],
            ],
        ];

        foreach ($mahalas as $mahala) {
            Mahala::query()->updateOrCreate(
                ['id' => $mahala['id']],
                $mahala,
            );
        }
    }
}
