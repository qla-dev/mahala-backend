<?php

namespace Database\Seeders;

use App\Models\Post;
use Illuminate\Database\Seeder;

class InnovativePostSeeder extends Seeder
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
        $posts = [
            [
                'mahala_id' => self::SARAJEVO_TOPIC_SCOPE_ID,
                'topic_slug' => 'glavna',
                'content' => 'Kod glavne saobraćajnice je gužva zbog radova, bolje zaobići narednih pola sata.',
            ],
            [
                'mahala_id' => self::NOVI_GRAD_SARAJEVO_POLYGON_ID,
                'topic_slug' => 'posao',
                'content' => 'Iza zgrade ima par slobodnih mjesta, ali jedan auto blokira ulaz u garažu.',
            ],
            [
                'mahala_id' => self::NOVI_GRAD_SARAJEVO_POLYGON_ID,
                'topic_slug' => 'novi-grad-kvart-ideje',
                'content' => 'Bilo bi dobro dodati još jednu klupu pored igrališta, uvijek se roditelji skupljaju tu.',
            ],
            [
                'mahala_id' => self::DOBRINJA_MAHALA_ID,
                'topic_slug' => 'dobrinja-lift-i-ulaz',
                'content' => 'Lift opet staje na četvrtom spratu. Ako još neko ima isti problem, zovemo servis zajedno.',
            ],
            [
                'mahala_id' => self::DOBRINJA_1_MAHALA_ID,
                'topic_slug' => 'dobrinja-1-djeciji-ritam',
                'content' => 'Na igralištu poslije 18h ima slobodan termin za malu raju, lopta je već tu.',
            ],
            [
                'mahala_id' => self::DOBRINJA_2_MAHALA_ID,
                'topic_slug' => 'dobrinja-2-haustor-dogovor',
                'content' => 'Dogovor za čišćenje ulaza u subotu u 10. Ko može neka ponese rukavice.',
            ],
            [
                'mahala_id' => self::DOBRINJA_3_MAHALA_ID,
                'topic_slug' => 'dobrinja-3-garazna-scena',
                'content' => 'Večeras se čuje proba iz garaže, zvuči kao da se sprema mali kvartovski nastup.',
            ],
            [
                'mahala_id' => self::C5_MAHALA_ID,
                'topic_slug' => 'c5-mikro-dojave',
                'content' => 'Kod C5 ulaza je ostavljena kutija sa knjigama, slobodno uzmite ili dodajte svoje.',
            ],
            [
                'mahala_id' => self::C5_MAHALA_ID,
                'topic_slug' => 'c5-basket-termin',
                'content' => 'Fali nam jedan za basket u 20h. Teren je slobodan ako se skupimo na vrijeme.',
            ],
        ];

        foreach ($posts as $post) {
            $channelId = "{$post['topic_slug']}-{$post['mahala_id']}";

            Post::query()->updateOrCreate(
                [
                    'channel_id' => $channelId,
                    'content' => $post['content'],
                ],
                [
                    'channel_id' => $channelId,
                    'author_user_id' => null,
                    'mahala_id' => $post['mahala_id'],
                    'content' => $post['content'],
                    'image_uri' => null,
                    'is_anonymous' => true,
                    'status' => 1,
                    'hidden' => false,
                ],
            );
        }
    }
}
