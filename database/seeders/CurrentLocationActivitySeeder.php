<?php

namespace Database\Seeders;

use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\Notification;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\PostView;
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
        'sarajevo' => 'sarajevo-71000',
        'mahala-sarajevo' => 'sarajevo-71000',
        'novi-grad' => '10871',
        'dobrinja' => 'user-dobrinja',
        'dobrinja-1' => 'user-dobrinja-1',
        'dobrinja-2' => 'user-dobrinja-2',
        'dobrinja-3' => 'user-dobrinja-3',
        'c5' => 'user-c5',
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

            $this->seedMissingCurrentScopePostVotes($allUsers);
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
            'image_uri' => $this->dummyImageUri($index),
            'is_anonymous' => $this->shouldSeedPostAsAnonymous($index),
            'status' => 1,
            'hidden' => false,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $this->seedPostVotes($post, $author, $voters, $index, $createdAt);
        $this->seedPostViews($post, $author, $voters, $index, $createdAt);

        $this->createComments($post, $author, $voters, $appleUser, $index, $createdAt);

        return $post;
    }

    private function seedPostViews(Post $post, ?User $author, $viewers, int $postIndex, Carbon $createdAt): void
    {
        if (!$post->image_uri) {
            return;
        }

        $eligibleViewers = $viewers
            ->reject(fn (User $user) => $author && (int) $user->id === (int) $author->id)
            ->values();

        if ($eligibleViewers->isEmpty()) {
            return;
        }

        $maxViewers = $eligibleViewers->count();
        $viewCount = min($maxViewers, $this->imagePostViewCountForPost($postIndex));
        $rotation = ($postIndex * 29 + 7) % $maxViewers;
        $orderedViewers = $eligibleViewers
            ->slice($rotation)
            ->concat($eligibleViewers->slice(0, $rotation))
            ->values()
            ->take($viewCount);

        foreach ($orderedViewers as $viewIndex => $user) {
            $viewedAt = $createdAt->copy()->addMinutes(8 + $viewIndex);

            PostView::query()->forceCreate([
                'post_id' => $post->id,
                'user_id' => $user->id,
                'created_at' => $viewedAt,
                'updated_at' => $viewedAt,
            ]);
        }
    }

    private function seedMissingCurrentScopePostVotes($voters): void
    {
        $seedScopeIds = array_values(array_unique(self::CURRENT_SCOPE_IDS));

        Post::query()
            ->whereIn('mahala_id', $seedScopeIds)
            ->whereDoesntHave('votes')
            ->oldest()
            ->get()
            ->each(function (Post $post, int $index) use ($voters) {
                $createdAt = $post->created_at instanceof Carbon
                    ? $post->created_at->copy()
                    : Carbon::now()->subHours($index + 1);

                $this->seedPostVotes(
                    post: $post,
                    author: $post->author,
                    voters: $voters,
                    postIndex: $index + 100,
                    createdAt: $createdAt,
                );
            });
    }

    private function seedPostVotes(Post $post, ?User $author, $voters, int $postIndex, Carbon $createdAt): void
    {
        $eligibleVoters = $voters
            ->reject(fn (User $user) => $author && (int) $user->id === (int) $author->id)
            ->values();

        if ($eligibleVoters->isEmpty()) {
            return;
        }

        $maxVoters = $eligibleVoters->count();
        $voteCount = min($maxVoters, 18 + (($postIndex * 23 + 11) % 73));
        $rotation = ($postIndex * 17 + 5) % $maxVoters;
        $orderedVoters = $eligibleVoters
            ->slice($rotation)
            ->concat($eligibleVoters->slice(0, $rotation))
            ->values()
            ->take($voteCount);
        $downvoteRatios = [0.04, 0.07, 0.10, 0.14, 0.18, 0.23, 0.29];
        $downvoteCount = min(
            $voteCount - 1,
            max(1, (int) round($voteCount * $downvoteRatios[$postIndex % count($downvoteRatios)])),
        );
        $downvoteSlots = $this->downvoteSlots($voteCount, $downvoteCount, $postIndex);

        foreach ($orderedVoters as $voteIndex => $user) {
            $votedAt = $createdAt->copy()->addMinutes($voteIndex + 1);

            PostVote::query()->forceCreate([
                'post_id' => $post->id,
                'user_id' => $user->id,
                'value' => in_array($voteIndex, $downvoteSlots, true) ? -1 : 1,
                'created_at' => $votedAt,
                'updated_at' => $votedAt,
            ]);
        }
    }

    private function downvoteSlots(int $voteCount, int $downvoteCount, int $postIndex): array
    {
        $slots = [];
        $offset = ($postIndex * 3 + 1) % $voteCount;

        for ($i = 0; $i < $downvoteCount; $i++) {
            $slot = ((int) floor((($i + 0.5) * $voteCount) / $downvoteCount) + $offset) % $voteCount;

            while (in_array($slot, $slots, true)) {
                $slot = ($slot + 1) % $voteCount;
            }

            $slots[] = $slot;
        }

        sort($slots);

        return $slots;
    }

    private function createComments(Post $post, User $author, $users, User $appleUser, int $postIndex, Carbon $createdAt): void
    {
        $commentCount = $this->commentCountForPost($postIndex);
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
                'is_anonymous' => $this->shouldSeedCommentAsAnonymous($postIndex, $i),
                'status' => 1,
                'created_at' => $createdAt->copy()->addMinutes(140 + $i * 3),
                'updated_at' => $createdAt->copy()->addMinutes(140 + $i * 3),
            ]);

            if (!$parent) {
                $rootComments[] = $comment;
            }
        }
    }

    private function commentCountForPost(int $postIndex): int
    {
        return 12 + ($postIndex % 40);
    }

    private function imagePostViewCountForPost(int $postIndex): int
    {
        return 35 + $postIndex;
    }

    private function shouldSeedPostAsAnonymous(int $index): bool
    {
        return in_array($index % 5, [1, 4], true);
    }

    private function shouldSeedCommentAsAnonymous(int $postIndex, int $commentIndex): bool
    {
        return (($postIndex * 3 + $commentIndex) % 5) < 2;
    }

    private function dummyImageUri(int $index): ?string
    {
        if (($index % 10) >= 6) {
            return null;
        }

        return sprintf('https://picsum.photos/seed/mahala-%02d/1200/900', $index + 1);
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
            'Je li još nekome nestalo vode oko zgrade? Kod mene pritisak samo kaplje, pa da znam da li je do ulaza ili šire.',
            'Danas su djeca sama skupila plastiku oko igrališta. Ako neko ima rukavice viška, ostavite kod klupe do 18h.',
            'Kod semafora prema tramvajskoj ima rupa koja se ne vidi po kiši. Pazite biciklom i romobilom.',
            'Ako neko zna majstora za interfon koji stvarno dođe kad kaže, javite. Naš zvoni pola noći sam od sebe.',
            'Večeras u 20h ima mali basket, fali nam dvoje da se igra normalno. Nije bitno znanje, samo dobra volja.',
            'Na parkingu iza marketa stoji siva jakna na ogradi. Neko je vjerovatno zaboravio poslije škole.',
            'Viđen mali crni pas bez ogrlice kod pekare. Prati ljude, djeluje mirno, ali je uplašen.',
            'Ima li neko preporuku za instrukcije iz matematike za osmi razred u blizini? Treba strpljiva osoba.',
            'Novi red vožnje kao da je pravljen bez ljudi koji rade smjene. Jutarnji bus opet pun do vrata.',
            'Ko je sinoć svirao gitaru ispod C5, svaka čast. Prvi put da buka zvuči kao poklon.',
            'U ulazu se skuplja potpis za dodatnu rasvjetu iza zgrade. Papir je kod oglasne table.',
            'Treba mi polovna tastatura za klinca, osnovno da radi za školu. Ako neko prodaje, pišite.',
            'Danas na igralištu turnir u malom fudbalu, roditelji već navijaju kao finale lige.',
            'Ako ste izgubili karticu za teretanu, ostavljena je kod trafike pored stanice.',
            'Djevojka sa zelenim kišobranom kod tramvaja jutros mi je vratila rukavicu. Hvala ti.',
            'Ko radi noćnu smjenu, pekara kod kružnog ima svježe kifle i poslije ponoći. Mala pobjeda.',
            'Ima li neko iskustvo sa vrtićem kod škole? Zanima me kako rješavaju adaptaciju prve sedmice.',
            'Kod garaža se opet ostavlja kabasti otpad mimo termina. Hajmo barem jednom dogovoreno.',
            'Sutra ujutro čistimo snijeg ispred ulaza ako padne kako najavljuju. Lopate su u podrumu.',
            'Ako nekome treba društvo za šetnju psa navečer, mi smo obično kod parka oko 21h.',
        ], $this->dummyPostTopicMap());
    }

    private function applePostDrafts(): array
    {
        return $this->buildPostDrafts([
            'Kod C5 su trenutno slobodna tri parking mjesta, da testiram koliko brzo mahala vidi novu objavu.',
            'Na Dobrinji se opet priča o maloj biblioteci u haustoru. Ja donosim prve knjige ako ima zainteresovanih.',
            'Momku sa plavom kapom kod Konzuma je ispala karta. Predao sam je radnici.',
            'Ima li ekipa za kratku partiju basketa večeras? Mogu donijeti loptu i markere.',
            'Ako neko traži posao preko ljeta, kafić pored stanice traži pomoć u drugoj smjeni.',
            'U parku je neko ostavio zdjelicu vode za pse. Lijep mali potez, samo da je ne sklonimo slučajno.',
            'Ključevi sa crvenim privjeskom su kod mene, nađeni su ispred ulaza.',
            'Politika parkinga nam je postala ozbiljnija od dnevnika. Treba normalan dogovor, ne rat ceduljama.',
            'Sve je mirno osim jednog alarma koji se pali svako deset minuta.',
            'Ima li ko za lokalni FC turnir u nedjelju? Bez nervoze, samo zezanje.',
            'Teren iza škole je suh i rasvjeta radi. Termin poslije 19h izgleda slobodan.',
            'Prodajem mali sto za balkon, očuvan, samo mi više ne stane. Preuzimanje na Dobrinji.',
            'Gdje ljudi ovdje normalno izađu na kafu bez preglasne muzike?',
            'Kod zgrade se čuje čudan zvuk iz lifta, ako se još kome desilo neka napiše prije nego zovemo servis.',
            'Još jedna kanta kod stanice bi spasila pola trotoara poslije velikog odmora.',
            'Dostavljač je ostavio pogrešnu kesu ispred ulaza B. Kod mene je dok se vlasnik javi.',
            'Komšija je popravio klupu bez velike priče. Takve objave treba više gurati.',
            'Mala svirka je kod platoa u petak ako vrijeme posluži. Neko zna tačan sat?',
            'Bijela maca se vrti oko podruma dva dana. Ako je nečija, djeluje gladno ali pitomo.',
            'Tražim nekoga ko zna Excel da objasni par stvari za sat vremena, plaćam kafu i čas.',
        ], $this->applePostTopicMap());
    }

    private function buildPostDrafts(array $contents, array $topicMap): array
    {
        return collect($contents)
            ->values()
            ->map(function (string $content, int $index) use ($topicMap) {
                [$scopeKey, $topicId] = $topicMap[$index] ?? ['sarajevo', 'glavna'];

                return [
                    'mahala_id' => self::CURRENT_SCOPE_IDS[$scopeKey],
                    'topic_id' => $topicId,
                    'content' => $content,
                ];
            })
            ->all();
    }

    private function dummyPostTopicMap(): array
    {
        return [
            ['dobrinja', 'dobrinja-lift-i-ulaz'],
            ['dobrinja-2', 'dobrinja-2-haustor-dogovor'],
            ['novi-grad', 'novi-grad-kvart-ideje'],
            ['dobrinja', 'dobrinja-lift-i-ulaz'],
            ['c5', 'c5-basket-termin'],
            ['dobrinja', 'izgubljeno-i-nadjeno'],
            ['dobrinja', 'ljubimci'],
            ['novi-grad', 'posao'],
            ['sarajevo', 'politika'],
            ['dobrinja-3', 'dobrinja-3-garazna-scena'],
            ['dobrinja-2', 'dobrinja-2-haustor-dogovor'],
            ['dobrinja', 'prodajem-i-kupujem'],
            ['dobrinja', 'sport'],
            ['dobrinja', 'izgubljeno-i-nadjeno'],
            ['sarajevo', 'spotted'],
            ['c5', 'nocna-smjena'],
            ['dobrinja-1', 'dobrinja-1-djeciji-ritam'],
            ['dobrinja-2', 'dobrinja-2-haustor-dogovor'],
            ['dobrinja-2', 'dobrinja-2-haustor-dogovor'],
            ['dobrinja', 'ljubimci'],
        ];
    }

    private function applePostTopicMap(): array
    {
        return [
            ['c5', 'c5-mikro-dojave'],
            ['dobrinja-2', 'dobrinja-2-haustor-dogovor'],
            ['dobrinja', 'spotted'],
            ['c5', 'c5-basket-termin'],
            ['novi-grad', 'posao'],
            ['dobrinja', 'ljubimci'],
            ['dobrinja', 'izgubljeno-i-nadjeno'],
            ['novi-grad', 'politika'],
            ['c5', 'nocna-smjena'],
            ['c5', 'gaming'],
            ['c5', 'sport'],
            ['dobrinja', 'prodajem-i-kupujem'],
            ['sarajevo', 'dating'],
            ['dobrinja', 'dobrinja-lift-i-ulaz'],
            ['novi-grad', 'novi-grad-kvart-ideje'],
            ['c5', 'c5-mikro-dojave'],
            ['sarajevo', 'glavna'],
            ['sarajevo', 'eventi'],
            ['dobrinja', 'ljubimci'],
            ['novi-grad', 'posao'],
        ];
    }

    private function commentTexts(): array
    {
        return [
            'Vidim isto, nije samo kod tebe.',
            'Hvala za dojavu, baš sam krenuo tamo.',
            'Može, javim i komšiji iz mog ulaza.',
            'Ovo je dobra ideja ako se dogovorimo bez galame.',
            'Ja mogu pomoći poslije posla oko 18h.',
            'Provjerio sam, još uvijek je tamo.',
            'Kod nas je slično bilo prošle sedmice.',
            'Ako treba broj majstora, pošaljem u komentar.',
            'Realno, ovo se može riješiti za jedan dan.',
            'Samo da neko ne skloni prije nego vlasnik dođe.',
            'Meni odgovara vikend, radnim danom teško.',
            'Bravo za inicijativu, ovako mahala i treba da radi.',
            'Ja sam za, ali hajmo tačan termin napisati.',
            'Može li neko slikati lokaciju da znamo gdje tačno?',
            'Nije hitno, ali bi bilo super da se popravi uskoro.',
            'Ima još ljudi koji su pitali za ovo.',
            'Ako treba potpis, računajte i mene.',
            'Malo šale, ali stvarno nam treba bolji dogovor.',
            'Čuo sam isto od komšije sa trećeg sprata.',
            'Ovo je korisna informacija, hvala.',
            'Nisam siguran, ali mislim da je nadležna općina.',
            'Ja mogu donijeti rukavice i dvije kese.',
            'Neka neko javi kad se riješi.',
            'Super, taman da se upoznamo uz posao.',
            'Ako bude kiše, prebacimo za sutra.',
            'Upravo prošao, stanje je isto.',
            'Dogovoreno, vidimo se tamo.',
            'Dobra priča, treba više ovakvih objava.',
            'Meni ovo djeluje kao posao za upravitelja.',
            'Ne bih čekao predugo, može se pogoršati.',
            'Imam kontakt, ali prvo da provjerim je li slobodan.',
            'Komunikacija nam je pola rješenja ovdje.',
            'Hajmo normalno, bez prozivanja ljudi.',
            'Ovo je baš komšijski, svaka čast.',
            'Ako neko ima dodatne informacije neka dopiše.',
            'Mogu potvrditi, vidio sam jutros.',
            'Meni je ovo okej prijedlog.',
            'Bolje da skupimo sve u jednu poruku.',
            'Neka ostane ovdje da ljudi vide.',
            'Javljam se ako nađem vlasnika.',
        ];
    }

    private function bosnianNames(): array
    {
        return [
            'amina', 'lejla', 'selma', 'emina', 'amra', 'azra', 'džejla', 'lamija', 'ajla', 'merjem',
            'sara', 'nejra', 'naida', 'aida', 'belma', 'dženana', 'adna', 'nedžla', 'samra', 'alma',
            'mirela', 'sanela', 'jasmina', 'elma', 'medina', 'sumeja', 'ilma', 'asja', 'nađa', 'zehra',
            'fatima', 'meliha', 'sabina', 'indira', 'eldina', 'irma', 'maida', 'dalila', 'nermina', 'dijana',
            'amila', 'esma', 'ines', 'mina', 'armina', 'sena', 'lana', 'rijana', 'kanita', 'zerina',
            'amar', 'emir', 'tarik', 'nedim', 'adnan', 'haris', 'kenan', 'mirza', 'dino', 'armin',
            'faruk', 'samir', 'edin', 'aldin', 'anes', 'benjamin', 'dženan', 'eldar', 'faris', 'hamza',
            'irfan', 'jasmin', 'kerim', 'mahmut', 'mehmed', 'mirsad', 'nermin', 'omer', 'rijad', 'sanel',
            'semir', 'adis', 'vedad', 'zijad', 'alen', 'damir', 'enis', 'senad', 'asmir', 'bakir',
            'denis', 'elvir', 'fehim', 'ismar', 'jusuf', 'miralem', 'rasim', 'said', 'tahir', 'zlatan',
        ];
    }

    private function usernameForName(string $name, int $index): string
    {
        $base = strtr(strtolower($name), [
            'č' => 'c', 'ć' => 'c', 'đ' => 'dj', 'š' => 's', 'ž' => 'z',
            'dž' => 'dz',
        ]);

        $username = trim((string) preg_replace('/[^a-z0-9]+/', '-', $base), '-');

        return $username !== '' ? $username : sprintf('korisnik-%03d', $index);
    }
}
