<?php

namespace Database\Seeders;

use App\Models\Topic;
use Illuminate\Database\Seeder;

class InnovativeTopicSeeder extends Seeder
{
    private const SARAJEVO_TOPIC_SCOPE_ID = 'sarajevo-71000';

    private const NOVI_GRAD_SARAJEVO_POLYGON_ID = '10871';

    private const C5_MAHALA_ID = 'user-c5';

    private const DOBRINJA_MAHALA_ID = 'user-dobrinja';

    private const DOBRINJA_1_MAHALA_ID = 'user-dobrinja-1';

    private const DOBRINJA_2_MAHALA_ID = 'user-dobrinja-2';

    private const DOBRINJA_3_MAHALA_ID = 'user-dobrinja-3';

    public function run(): void
    {
        $topics = [
            [
                'mahala_id' => self::SARAJEVO_TOPIC_SCOPE_ID,
                'name' => 'Sarajevo servis',
                'slug' => 'sarajevo-servis',
                'description' => 'Gradske dojave za saobraćaj, kvarove, radove i korisne info kroz Sarajevo.',
                'color_hex' => '#7c3aed',
            ],
            [
                'mahala_id' => self::NOVI_GRAD_SARAJEVO_POLYGON_ID,
                'name' => 'Parking patrola',
                'slug' => 'novi-grad-parking-patrola',
                'description' => 'Slobodna mjesta, blokirani prolazi i brze dojave oko parkinga.',
                'color_hex' => '#f97316',
            ],
            [
                'mahala_id' => self::NOVI_GRAD_SARAJEVO_POLYGON_ID,
                'name' => 'Kvart ideje',
                'slug' => 'novi-grad-kvart-ideje',
                'description' => 'Prijedlozi za klupe, rasvjetu, zelenilo i bolji svakodnevni život.',
                'color_hex' => '#06b6d4',
            ],
            [
                'mahala_id' => self::DOBRINJA_MAHALA_ID,
                'name' => 'Lift i ulaz',
                'slug' => 'dobrinja-lift-i-ulaz',
                'description' => 'Kvarovi, majstori, domari i sitne zgradske akcije.',
                'color_hex' => '#64748b',
            ],
            [
                'mahala_id' => self::DOBRINJA_1_MAHALA_ID,
                'name' => 'Dječiji ritam',
                'slug' => 'dobrinja-1-djeciji-ritam',
                'description' => 'Igrališta, treninzi, sekcije i sigurne rute za djecu.',
                'color_hex' => '#22c55e',
            ],
            [
                'mahala_id' => self::DOBRINJA_2_MAHALA_ID,
                'name' => 'Haustor dogovor',
                'slug' => 'dobrinja-2-haustor-dogovor',
                'description' => 'Komšijske akcije, čišćenje, obavijesti i dogovori po ulazima.',
                'color_hex' => '#eab308',
            ],
            [
                'mahala_id' => self::DOBRINJA_3_MAHALA_ID,
                'name' => 'Garažna scena',
                'slug' => 'dobrinja-3-garazna-scena',
                'description' => 'Muzika, probe, mali biznisi i kreativci iz komšiluka.',
                'color_hex' => '#a855f7',
            ],
            [
                'mahala_id' => self::C5_MAHALA_ID,
                'name' => 'C5 mikro dojave',
                'slug' => 'c5-mikro-dojave',
                'description' => 'Brze dojave za parking, buku, radove i stvari koje se vide iz kvarta.',
                'color_hex' => '#2dd4bf',
            ],
            [
                'mahala_id' => self::C5_MAHALA_ID,
                'name' => 'Basket termin',
                'slug' => 'c5-basket-termin',
                'description' => 'Dogovori za basket, rekreaciju i ekipe koje traže još jednog igrača.',
                'color_hex' => '#ef4444',
            ],
        ];

        foreach ($topics as $topic) {
            Topic::query()->updateOrCreate(
                ['slug' => $topic['slug']],
                [
                    ...$topic,
                    'created_by_user_id' => null,
                    'is_premium' => false,
                    'is_system' => false,
                    'status' => 1,
                ],
            );
        }
    }
}
