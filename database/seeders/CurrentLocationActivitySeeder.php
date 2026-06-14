<?php

namespace Database\Seeders;

use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\Notification;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CurrentLocationActivitySeeder extends Seeder
{
    private const APPLE_USERNAME = 'apple-test-user';

    private const CURRENT_SCOPE_IDS = [
        'mahala-sarajevo' => 'sarajevo-71000',
        'novi-grad' => '10871',
        'dobrinja' => 'user-dobrinja',
        'c5' => 'user-c5',
    ];

    private const GENERAL_TOPICS = [
        'glavna',
        'eventi',
        'spotted',
        'posao',
        'ljubimci',
        'izgubljeno-i-nadjeno',
        'politika',
        'nocna-smjena',
        'gaming',
        'sport',
        'prodajem-i-kupujem',
        'dating',
    ];

    private const LOCAL_TOPICS = [
        'novi-grad-kvart-ideje',
        'dobrinja-lift-i-ulaz',
        'c5-mikro-dojave',
        'c5-basket-termin',
    ];

    public function run(): void
    {
        DB::transaction(function () {
            $dummyUsers = $this->seedDummyUsers();
            $appleUser = $this->seedAppleUser();
            $allUsers = $dummyUsers->concat([$appleUser])->values();
            $seedContents = collect([
                ...array_column($this->dummyPostDrafts(), 'content'),
                ...array_column($this->applePostDrafts(), 'content'),
            ]);

            Post::query()
                ->whereIn('author_user_id', $allUsers->pluck('id'))
                ->whereIn('content', $seedContents->all())
                ->get()
                ->each(fn (Post $post) => $post->delete());

            $createdPosts = collect();

            foreach ($this->dummyPostDrafts() as $index => $draft) {
                $createdPosts->push($this->createPostWithActivity(
                    draft: $draft,
                    author: $dummyUsers[$index % $dummyUsers->count()],
                    voters: $allUsers,
                    appleUser: $appleUser,
                    index: $index,
                ));
            }

            foreach ($this->applePostDrafts() as $index => $draft) {
                $createdPosts->push($this->createPostWithActivity(
                    draft: $draft,
                    author: $appleUser,
                    voters: $dummyUsers,
                    appleUser: $appleUser,
                    index: $index + 20,
                ));
            }

            $this->seedAppleCommentVotes($appleUser, $createdPosts);
            $this->seedAppleNotifications($appleUser, $createdPosts, $dummyUsers);
        });
    }

    private function seedDummyUsers()
    {
        return collect($this->bosnianNames())
            ->values()
            ->map(function (string $name, int $index) {
                $username = $this->usernameForName($name, $index + 1);

                $user = User::query()->updateOrCreate(
                    ['username' => $username],
                    [
                        'name' => $name,
                        'email' => "{$username}@seed.mahala.test",
                        'email_verified_at' => now(),
                        'password' => Hash::make('password'),
                    ],
                );

                $this->ensureSettings($user);

                return $user;
            });
    }

    private function seedAppleUser(): User
    {
        $user = User::query()->updateOrCreate(
            ['username' => self::APPLE_USERNAME],
            [
                'name' => 'Apple Test User',
                'email' => 'apple-test-user@seed.mahala.test',
                'email_verified_at' => now(),
                'password' => Hash::make('password'),
            ],
        );

        $this->ensureSettings($user);

        return $user;
    }

    private function ensureSettings(User $user): void
    {
        UserSetting::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'notifications_comments' => true,
                'notifications_votes' => true,
                'notifications_location' => true,
                'notifications_startup_mahalas' => true,
                'locale' => 'bs',
                'pro_status' => 0,
            ],
        );
    }

    private function createPostWithActivity(array $draft, User $author, $voters, User $appleUser, int $index): Post
    {
        $createdAt = Carbon::now()->subHours(($index * 4) % 168)->subMinutes(($index * 11) % 60);
        $post = Post::query()->create([
            'topic_id' => $draft['topic_id'],
            'author_user_id' => $author->id,
            'mahala_id' => $draft['mahala_id'],
            'content' => $draft['content'],
            'image_uri' => null,
            'is_anonymous' => false,
            'status' => 1,
            'hidden' => false,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $eligibleVoters = $voters
            ->reject(fn (User $user) => (int) $user->id === (int) $author->id)
            ->values()
            ->take(100);

        foreach ($eligibleVoters as $voteIndex => $user) {
            PostVote::query()->create([
                'post_id' => $post->id,
                'user_id' => $user->id,
                'value' => $voteIndex % 9 === 0 ? -1 : 1,
                'created_at' => $createdAt->copy()->addMinutes($voteIndex + 1),
                'updated_at' => $createdAt->copy()->addMinutes($voteIndex + 1),
            ]);
        }

        $this->createComments($post, $author, $voters, $appleUser, $index, $createdAt);

        return $post;
    }

    private function createComments(Post $post, User $author, $users, User $appleUser, int $postIndex, Carbon $createdAt): void
    {
        $commentCount = 25 + (($postIndex * 7) % 21);
        $rootComments = [];
        $commentTexts = $this->commentTexts();

        for ($i = 0; $i < $commentCount; $i++) {
            $commentAuthor = $users[($postIndex * 13 + $i * 5) % $users->count()];

            if ((int) $commentAuthor->id === (int) $author->id) {
                $commentAuthor = $users[($postIndex * 13 + $i * 5 + 1) % $users->count()];
            }

            $parent = null;

            if ($i > 3 && $i % 4 === 0 && $rootComments !== []) {
                $parent = $rootComments[($i + $postIndex) % count($rootComments)];
            }

            if ($i > 8 && $i % 9 === 0 && (int) $author->id !== (int) $appleUser->id) {
                $commentAuthor = $appleUser;
            }

            $comment = Comment::query()->create([
                'post_id' => $post->id,
                'parent_id' => $parent?->id,
                'author' => $commentAuthor->id,
                'content' => $commentTexts[($postIndex * 17 + $i) % count($commentTexts)],
                'is_anonymous' => false,
                'status' => 1,
                'created_at' => $createdAt->copy()->addMinutes(140 + $i * 3),
                'updated_at' => $createdAt->copy()->addMinutes(140 + $i * 3),
            ]);

            if (!$parent) {
                $rootComments[] = $comment;
            }
        }
    }

    private function seedAppleNotifications(User $appleUser, $posts, $dummyUsers): void
    {
        Notification::query()
            ->where('user_id', $appleUser->id)
            ->whereIn('related_post_id', $posts->pluck('id'))
            ->delete();

        $applePosts = $posts
            ->filter(fn (Post $post) => (int) $post->author_user_id === (int) $appleUser->id)
            ->values();
        $otherPosts = $posts
            ->reject(fn (Post $post) => (int) $post->author_user_id === (int) $appleUser->id)
            ->values();
        $now = Carbon::now();

        for ($i = 0; $i < 30; $i++) {
            $post = $applePosts[$i % $applePosts->count()];
            $fromUser = $dummyUsers[($i * 3) % $dummyUsers->count()];

            Notification::query()->create([
                'user_id' => $appleUser->id,
                'from_user_id' => $fromUser->id,
                'type' => Notification::TYPE_VOTE,
                'vote_value' => $i % 10 === 0 ? -1 : 1,
                'title' => 'vote',
                'body' => 'post_vote',
                'related_post_id' => $post->id,
                'related_comment_id' => null,
                'read_at' => null,
                'created_at' => $now->copy()->subMinutes($i * 3),
                'updated_at' => $now->copy()->subMinutes($i * 3),
            ]);
        }

        for ($i = 0; $i < 20; $i++) {
            $post = $applePosts[$i % $applePosts->count()];
            $comment = $post->comments()->where('author', '!=', $appleUser->id)->oldest()->skip($i % 8)->first();
            $fromUser = $comment?->authorUser ?? $dummyUsers[($i * 7) % $dummyUsers->count()];

            Notification::query()->create([
                'user_id' => $appleUser->id,
                'from_user_id' => $fromUser?->id,
                'type' => Notification::TYPE_COMMENT,
                'vote_value' => null,
                'title' => 'comment',
                'body' => 'post_comment',
                'related_post_id' => $post->id,
                'related_comment_id' => $comment?->id,
                'read_at' => null,
                'created_at' => $now->copy()->subMinutes(100 + $i * 4),
                'updated_at' => $now->copy()->subMinutes(100 + $i * 4),
            ]);
        }

        for ($i = 0; $i < 10; $i++) {
            $post = $otherPosts[$i % $otherPosts->count()];
            $comment = $post->comments()->where('author', $appleUser->id)->first()
                ?? $post->comments()->oldest()->skip($i)->first();
            $reply = $post->comments()->whereNotNull('parent_id')->oldest()->skip($i)->first() ?? $comment;
            $fromUser = $dummyUsers[($i * 11) % $dummyUsers->count()];

            Notification::query()->create([
                'user_id' => $appleUser->id,
                'from_user_id' => $fromUser->id,
                'type' => Notification::TYPE_COMMENT_REPLY,
                'vote_value' => null,
                'title' => 'comment_reply',
                'body' => 'comment_reply',
                'related_post_id' => $post->id,
                'related_comment_id' => $reply?->id ?? $comment?->id,
                'read_at' => null,
                'created_at' => $now->copy()->subMinutes(190 + $i * 5),
                'updated_at' => $now->copy()->subMinutes(190 + $i * 5),
            ]);
        }
    }

    private function seedAppleCommentVotes(User $appleUser, $posts): void
    {
        $comments = Comment::query()
            ->whereIn('post_id', $posts->pluck('id'))
            ->where('author', '!=', $appleUser->id)
            ->oldest()
            ->limit(30)
            ->get();

        foreach ($comments as $index => $comment) {
            CommentVote::query()->updateOrCreate(
                [
                    'reply_id' => $comment->id,
                    'user_id' => $appleUser->id,
                ],
                [
                    'value' => $index % 8 === 0 ? -1 : 1,
                    'created_at' => $comment->created_at?->copy()->addMinutes(2) ?? now(),
                    'updated_at' => $comment->created_at?->copy()->addMinutes(2) ?? now(),
                ],
            );
        }
    }

    private function dummyPostDrafts(): array
    {
        return $this->buildPostDrafts([
            'Je li jos nekome nestalo vode oko zgrade? Kod mene pritisak samo kaplje, pa da znam da li je do ulaza ili sire.',
            'Danas su djeca sama skupila plastiku oko igralista. Ako neko ima rukavice viska, ostavite kod klupe do 18h.',
            'Kod semafora prema tramvajskoj ima rupa koja se ne vidi po kisi. Pazite biciklom i romobilom.',
            'Ako neko zna majstora za interfon koji stvarno dodje kad kaze, javite. Nas zvoni pola noci sam od sebe.',
            'Veceras u 20h ima mali basket, fali nam dvoje da se igra normalno. Nije bitno znanje, samo dobra volja.',
            'Na parkingu iza marketa stoji siva jakna na ogradi. Neko je vjerovatno zaboravio poslije skole.',
            'Vidjen mali crni pas bez ogrlice kod pekare. Prati ljude, djeluje mirno, ali je uplasen.',
            'Ima li neko preporuku za instrukcije iz matematike za osmi razred u blizini? Treba strpljiva osoba.',
            'Novi red voznje kao da je pravljen bez ljudi koji rade smjene. Jutarnji bus opet pun do vrata.',
            'Ko je sinoc svirao gitaru ispod C5, svaka cast. Prvi put da buka zvuci kao poklon.',
            'U ulazu se skuplja potpis za dodatnu rasvjetu iza zgrade. Papir je kod oglasne table.',
            'Treba mi polovna tastatura za klinca, osnovno da radi za skolu. Ako neko prodaje, pisite.',
            'Danas na igralistu turnir u malom fudbalu, roditelji vec navijaju kao finale lige.',
            'Ako ste izgubili karticu za teretanu, ostavljena je kod trafike pored stanice.',
            'Spotted: djevojka sa zelenim kisobranom kod tramvaja jutros, vratila si mi rukavicu. Hvala ti.',
            'Ko radi nocnu smjenu, pekara kod kruznog ima svjeze kifle i poslije ponoci. Mala pobjeda.',
            'Ima li neko iskustvo sa vrticem kod skole? Zanima me kako rjesavaju adaptaciju prve sedmice.',
            'Kod garaza se opet ostavlja kabasti otpad mimo termina. Hajmo barem jednom dogovoreno.',
            'Sutra ujutro cistimo snijeg ispred ulaza ako padne kako najavljuju. Lopate su u podrumu.',
            'Ako nekome treba drustvo za setnju psa navece, mi smo obicno kod parka oko 21h.',
        ]);
    }

    private function applePostDrafts(): array
    {
        return $this->buildPostDrafts([
            'Testiram koliko brzo mahala vidi novu objavu: kod C5 trenutno slobodna tri parking mjesta.',
            'Na Dobrinji se opet prica o maloj biblioteci u haustoru. Ja donosim prve knjige ako ima zainteresovanih.',
            'Spotted: momak sa plavom kapom kod Konzuma, ispala ti je karta. Predao sam je radnici.',
            'Ima li ekipa za kratku partiju basketa veceras? Mogu donijeti loptu i markere.',
            'Ako neko trazi posao preko ljeta, kafic pored stanice trazi pomoc u drugoj smjeni.',
            'U parku je neko ostavio zdjelicu vode za pse. Lijep mali potez, samo da je ne sklonimo slucajno.',
            'Za izgubljeno i nadjeno: kljucevi sa crvenim privjeskom su kod mene, nadjeni ispred ulaza.',
            'Politika parkinga nam je postala ozbiljnija od dnevnika. Treba normalan dogovor, ne rat ceduljama.',
            'Nocna smjena javlja: sve mirno osim jednog alarma koji se pali svako deset minuta.',
            'Gaming ekipa, ima li ko za lokalni FC turnir u nedjelju? Bez nervoze, samo zezanje.',
            'Sport update: teren iza skole je suh i rasvjeta radi. Termin poslije 19h izgleda slobodan.',
            'Prodajem mali sto za balkon, ocuvan, samo mi vise ne stane. Preuzimanje na Dobrinji.',
            'Dating tema bez drame: gdje ljudi ovdje normalno izadju na kafu bez preglasne muzike?',
            'Kod zgrade se cuje cudan zvuk iz lifta, ako se jos kome desilo neka napise prije nego zovemo servis.',
            'Novi Grad ideja: jos jedna kanta kod stanice bi spasila pola trotoara poslije velikog odmora.',
            'C5 dojava: dostavljac je ostavio pogresnu kesu ispred ulaza B. Kod mene je dok se vlasnik javi.',
            'Glavna stvar danas: komsija popravio klupu bez velike price. Takve objave treba vise gurati.',
            'Eventi: mala svirka kod platoa u petak ako vrijeme posluzi. Neko zna tacan sat?',
            'Ljubimci: bijela maca se vrti oko podruma dva dana. Ako je necija, djeluje gladno ali pitomo.',
            'Posao/ucenje: trazim nekoga ko zna Excel da objasni par stvari za sat vremena, placam kafu i cas.',
        ]);
    }

    private function buildPostDrafts(array $contents): array
    {
        $scopeIds = array_values(self::CURRENT_SCOPE_IDS);
        $topicPool = [
            ...self::GENERAL_TOPICS,
            ...self::LOCAL_TOPICS,
        ];

        return collect($contents)
            ->values()
            ->map(fn (string $content, int $index) => [
                'mahala_id' => $scopeIds[$index % count($scopeIds)],
                'topic_id' => $topicPool[$index % count($topicPool)],
                'content' => $content,
            ])
            ->all();
    }

    private function commentTexts(): array
    {
        return [
            'Vidim isto, nije samo kod tebe.',
            'Hvala za dojavu, bas sam krenuo tamo.',
            'Moze, javim i komsiji iz mog ulaza.',
            'Ovo je dobra ideja ako se dogovorimo bez galame.',
            'Ja mogu pomoci poslije posla oko 18h.',
            'Provjerio sam, jos uvijek je tamo.',
            'Kod nas je slicno bilo prosle sedmice.',
            'Ako treba broj majstora, posaljem u komentar.',
            'Realno, ovo se moze rijesiti za jedan dan.',
            'Samo da neko ne skloni prije nego vlasnik dodje.',
            'Meni odgovara vikend, radnim danom tesko.',
            'Bravo za inicijativu, ovako mahala i treba da radi.',
            'Ja sam za, ali hajmo tacan termin napisati.',
            'Moze li neko slikati lokaciju da znamo gdje tacno?',
            'Nije hitno, ali bi bilo super da se popravi uskoro.',
            'Ima jos ljudi koji su pitali za ovo.',
            'Ako treba potpis, racunajte i mene.',
            'Malo sale, ali stvarno nam treba bolji dogovor.',
            'Cuo sam isto od komsije sa treceg sprata.',
            'Ovo je korisna informacija, hvala.',
            'Nisam siguran, ali mislim da je nadlezna opcina.',
            'Ja mogu donijeti rukavice i dvije kese.',
            'Neka neko javi kad se rijesi.',
            'Super, taman da se upoznamo uz posao.',
            'Ako bude kise, prebacimo za sutra.',
            'Upravo prosao, stanje je isto.',
            'Dogovoreno, vidimo se tamo.',
            'Dobra prica, treba vise ovakvih objava.',
            'Meni ovo djeluje kao posao za upravitelja.',
            'Ne bih cekao predugo, moze se pogorsati.',
            'Imam kontakt, ali prvo da provjerim je li slobodan.',
            'Komunikacija nam je pola rjesenja ovdje.',
            'Hajmo normalno, bez prozivanja ljudi.',
            'Ovo je bas komsijski, svaka cast.',
            'Ako neko ima dodatne informacije neka dopise.',
            'Mogu potvrditi, vidio sam jutros.',
            'Meni je ovo okej prijedlog.',
            'Bolje da skupimo sve u jednu poruku.',
            'Neka ostane ovdje da ljudi vide.',
            'Javljam se ako nadjem vlasnika.',
        ];
    }

    private function bosnianNames(): array
    {
        return [
            'Amina', 'Lejla', 'Selma', 'Emina', 'Amra', 'Azra', 'Dzejla', 'Lamija', 'Ajla', 'Merjem',
            'Sara', 'Nejra', 'Naida', 'Aida', 'Belma', 'Dzenana', 'Adna', 'Nedzla', 'Samra', 'Alma',
            'Mirela', 'Sanela', 'Jasmina', 'Elma', 'Medina', 'Sumeja', 'Ilma', 'Asja', 'Nadja', 'Zehra',
            'Fatima', 'Meliha', 'Sabina', 'Indira', 'Eldina', 'Irma', 'Maida', 'Dalila', 'Nermina', 'Dijana',
            'Amila', 'Esma', 'Ines', 'Mina', 'Armina', 'Sena', 'Lana', 'Rijana', 'Kanita', 'Zerina',
            'Amar', 'Emir', 'Tarik', 'Nedim', 'Adnan', 'Haris', 'Kenan', 'Mirza', 'Dino', 'Armin',
            'Faruk', 'Samir', 'Edin', 'Aldin', 'Anes', 'Benjamin', 'Dzenan', 'Eldar', 'Faris', 'Hamza',
            'Irfan', 'Jasmin', 'Kerim', 'Mahmut', 'Mehmed', 'Mirsad', 'Nermin', 'Omer', 'Rijad', 'Sanel',
            'Semir', 'Adis', 'Vedad', 'Zijad', 'Alen', 'Damir', 'Enis', 'Senad', 'Asmir', 'Bakir',
            'Denis', 'Elvir', 'Fehim', 'Ismar', 'Jusuf', 'Miralem', 'Rasim', 'Said', 'Tahir', 'Zlatan',
        ];
    }

    private function usernameForName(string $name, int $index): string
    {
        $base = strtolower($name);

        return sprintf('seed-%s-%03d', preg_replace('/[^a-z0-9]+/', '-', $base), $index);
    }
}
