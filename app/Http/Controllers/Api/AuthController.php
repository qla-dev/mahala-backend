<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const REGISTRATION_CODE_TTL_MINUTES = 10;

    public function sendRegistrationCode(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
        ]);

        $email = Str::lower($validated['email']);
        $code = (string) random_int(1000, 9999);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'token' => Hash::make($code),
                'created_at' => now(),
            ],
        );

        $this->sendRegistrationVerificationEmail($email, $code);

        return response()->json([
            'message' => 'Verifikacijski kod je poslan.',
        ]);
    }

    public function register(Request $request)
    {
        if ($request->filled('username')) {
            $request->merge([
                'username' => Str::lower($request->input('username')),
            ]);
        }

        $validated = $request->validate([
            'username' => ['sometimes', 'nullable', 'string', 'min:3', 'max:50', 'alpha_dash', 'unique:users,username'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'code' => ['required', 'digits:4'],
        ]);

        $email = Str::lower($validated['email']);
        $verification = DB::table('password_reset_tokens')->where('email', $email)->first();

        if (!$verification) {
            throw ValidationException::withMessages([
                'code' => ['Prvo zatrazi verifikacijski kod.'],
            ]);
        }

        if (Carbon::parse($verification->created_at)->addMinutes(self::REGISTRATION_CODE_TTL_MINUTES)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            throw ValidationException::withMessages([
                'code' => ['Verifikacijski kod je istekao. Zatrazi novi kod.'],
            ]);
        }

        if (!Hash::check($validated['code'], $verification->token)) {
            throw ValidationException::withMessages([
                'code' => ['Verifikacijski kod nije ispravan.'],
            ]);
        }

        $username = isset($validated['username'])
            ? Str::lower($validated['username'])
            : $this->generateGoogleUsername($email, Str::before($email, '@'));

        $user = User::query()->create([
            'name' => $username,
            'username' => $username,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => $validated['password'],
        ]);

        DB::table('password_reset_tokens')->where('email', $email)->delete();

        return response()->json([
            'message' => 'Registracija je uspjesna.',
            'token' => $user->createToken('auth_token')->plainTextToken,
            'user' => $this->formatUser($user),
            'is_new_user' => true,
        ], 201);
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required_without:username', 'nullable', 'string'],
            'username' => ['required_without:email', 'nullable', 'string'],
            'password' => ['required', 'string'],
        ]);

        $identifier = $validated['email'] ?? $validated['username'] ?? null;
        $user = User::query()
            ->where('email', $identifier)
            ->orWhere('username', $identifier)
            ->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email, korisničko ime ili lozinka nisu ispravni.'],
                'password' => ['Email, korisničko ime ili lozinka nisu ispravni.'],
            ]);
        }

        return response()->json([
            'message' => 'Prijava je uspješna.',
            'token' => $user->createToken('auth_token')->plainTextToken,
            'user' => $this->formatUser($user),
        ]);
    }

    public function google(Request $request)
    {
        $validated = $request->validate([
            'id_token' => ['required', 'string'],
        ]);

        $googleUser = $this->verifyGoogleIdToken($validated['id_token']);
        $email = $googleUser['email'] ?? null;
        $googleId = $googleUser['sub'] ?? null;

        if (!$email || !$googleId) {
            throw ValidationException::withMessages([
                'id_token' => ['Google nalog nije vratio ispravan email.'],
            ]);
        }

        $user = User::query()
            ->where('google_id', $googleId)
            ->orWhere('email', $email)
            ->first();
        $isNewUser = false;

        if ($user) {
            if ($user->google_id && $user->google_id !== $googleId) {
                throw ValidationException::withMessages([
                    'id_token' => ['Ovaj email je veÄ‡ povezan sa drugim Google nalogom.'],
                ]);
            }

            $user->forceFill([
                'google_id' => $user->google_id ?: $googleId,
                'email_verified_at' => $user->email_verified_at ?: now(),
            ])->save();
        } else {
            $name = trim((string) ($googleUser['name'] ?? '')) ?: Str::before($email, '@');
            $user = User::query()->create([
                'name' => $name,
                'username' => $this->generateGoogleUsername($email, $name),
                'email' => $email,
                'google_id' => $googleId,
                'email_verified_at' => now(),
                'password' => Str::random(48),
            ]);
            $isNewUser = true;
        }

        return response()->json([
            'message' => 'Google prijava je uspjeÅ¡na.',
            'token' => $user->createToken('auth_token')->plainTextToken,
            'user' => $this->formatUser($user->refresh()),
            'is_new_user' => $isNewUser,
        ]);
    }

    public function apple(Request $request)
    {
        $validated = $request->validate([
            'identity_token' => ['required', 'string'],
            'full_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $appleUser = $this->verifyAppleIdentityToken($validated['identity_token']);
        $email = $appleUser['email'] ?? null;
        $appleId = $appleUser['sub'] ?? null;

        if (!$appleId) {
            throw ValidationException::withMessages([
                'identity_token' => ['Apple nalog nije vratio ispravan identifikator.'],
            ]);
        }

        $userQuery = User::query()->where('apple_id', $appleId);

        if ($email) {
            $userQuery->orWhere('email', $email);
        }

        $user = $userQuery->first();
        $isNewUser = false;

        if ($user) {
            if ($user->apple_id && $user->apple_id !== $appleId) {
                throw ValidationException::withMessages([
                    'identity_token' => ['Ovaj email je veÄ‡ povezan sa drugim Apple nalogom.'],
                ]);
            }

            $user->forceFill([
                'apple_id' => $user->apple_id ?: $appleId,
                'email_verified_at' => $user->email_verified_at ?: now(),
            ])->save();
        } else {
            if (!$email) {
                throw ValidationException::withMessages([
                    'identity_token' => ['Apple nalog nije vratio email za kreiranje profila.'],
                ]);
            }

            $name = trim((string) ($validated['full_name'] ?? '')) ?: Str::before($email, '@');
            $user = User::query()->create([
                'name' => $name,
                'username' => $this->generateGoogleUsername($email, $name),
                'email' => $email,
                'apple_id' => $appleId,
                'email_verified_at' => now(),
                'password' => Str::random(48),
            ]);
            $isNewUser = true;
        }

        return response()->json([
            'message' => 'Apple prijava je uspjeÅ¡na.',
            'token' => $user->createToken('auth_token')->plainTextToken,
            'user' => $this->formatUser($user->refresh()),
            'is_new_user' => $isNewUser,
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $this->formatUser($request->user()),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Uspješno si odjavljen.',
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        if ($request->filled('username')) {
            $request->merge([
                'username' => Str::lower($request->input('username')),
            ]);
        }

        $validated = $request->validate([
            'username' => [
                'sometimes',
                'string',
                'min:3',
                'max:50',
                'alpha_dash',
                Rule::unique('users', 'username')->ignore($user->id),
            ],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        if (array_key_exists('username', $validated) && is_string($validated['username'])) {
            $validated['username'] = Str::lower($validated['username']);
        }

        $user->fill($validated)->save();

        return response()->json([
            'message' => 'Profil je uspjesno azuriran.',
            'user' => $this->formatUser($user->refresh()),
        ]);
    }

    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        $user = $request->user();

        if (!Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Trenutna lozinka nije ispravna.'],
            ]);
        }

        $user->forceFill([
            'password' => $validated['password'],
        ])->save();

        return response()->json([
            'message' => 'Lozinka je uspjesno promijenjena.',
        ]);
    }

    private function formatUser(User $user): array
    {
        $settings = $user->settings()->firstOrCreate([], [
            'notifications_app' => true,
            'notifications' => true,
            'notifications_app_location' => true,
            'notifications_app_comments' => true,
            'notifications_app_votes' => true,
            'notifications_location' => true,
            'notifications_comments' => true,
            'notifications_votes' => true,
            'locale' => 'bs',
            'pro_status' => UserSetting::PRO_INACTIVE,
        ]);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'settings' => [
                'notifications_app' => $settings->notifications_app,
                'notifications' => $settings->notifications,
                'notifications_app_location' => $settings->notifications_app_location,
                'notifications_app_comments' => $settings->notifications_app_comments,
                'notifications_app_votes' => $settings->notifications_app_votes,
                'notifications_location' => $settings->notifications_location,
                'notifications_comments' => $settings->notifications_comments,
                'notifications_votes' => $settings->notifications_votes,
                'locale' => $settings->locale,
                'pro_status' => $settings->pro_status,
                'pro_started_at' => $settings->pro_started_at,
                'pro_ends_at' => $settings->pro_ends_at,
            ],
        ];
    }

    private function verifyGoogleIdToken(string $idToken): array
    {
        $clientIds = config('services.google.client_ids', []);

        if ($clientIds === []) {
            throw ValidationException::withMessages([
                'id_token' => ['Google prijava nije konfigurisana.'],
            ]);
        }

        $response = Http::asJson()->get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $idToken,
        ]);

        if (!$response->ok()) {
            throw ValidationException::withMessages([
                'id_token' => ['Google token nije ispravan.'],
            ]);
        }

        $payload = $response->json();

        if (!in_array($payload['aud'] ?? null, $clientIds, true)) {
            throw ValidationException::withMessages([
                'id_token' => ['Google token nije namijenjen ovoj aplikaciji.'],
            ]);
        }

        if (($payload['email_verified'] ?? null) !== true && ($payload['email_verified'] ?? null) !== 'true') {
            throw ValidationException::withMessages([
                'id_token' => ['Google email nije verifikovan.'],
            ]);
        }

        return $payload;
    }

    private function verifyAppleIdentityToken(string $identityToken): array
    {
        $clientIds = config('services.apple.client_ids', []);

        if ($clientIds === []) {
            throw ValidationException::withMessages([
                'identity_token' => ['Apple prijava nije konfigurisana.'],
            ]);
        }

        $parts = explode('.', $identityToken);

        if (count($parts) !== 3) {
            throw ValidationException::withMessages([
                'identity_token' => ['Apple token nije ispravan.'],
            ]);
        }

        $header = json_decode($this->base64UrlDecode($parts[0]), true);
        $payload = json_decode($this->base64UrlDecode($parts[1]), true);
        $signature = $this->base64UrlDecode($parts[2]);

        if (!is_array($header) || !is_array($payload) || ($header['alg'] ?? null) !== 'RS256') {
            throw ValidationException::withMessages([
                'identity_token' => ['Apple token nije ispravan.'],
            ]);
        }

        $keysResponse = Http::asJson()->get('https://appleid.apple.com/auth/keys');

        if (!$keysResponse->ok()) {
            throw ValidationException::withMessages([
                'identity_token' => ['Apple kljuÄevi nisu dostupni.'],
            ]);
        }

        $key = collect($keysResponse->json('keys', []))->first(
            fn (array $candidate) => ($candidate['kid'] ?? null) === ($header['kid'] ?? null),
        );

        if (!$key || !$this->verifyJwtSignature($parts[0] . '.' . $parts[1], $signature, $key)) {
            throw ValidationException::withMessages([
                'identity_token' => ['Apple token nije ispravan.'],
            ]);
        }

        if (($payload['iss'] ?? null) !== 'https://appleid.apple.com') {
            throw ValidationException::withMessages([
                'identity_token' => ['Apple token nije ispravan.'],
            ]);
        }

        if (!in_array($payload['aud'] ?? null, $clientIds, true)) {
            throw ValidationException::withMessages([
                'identity_token' => ['Apple token nije namijenjen ovoj aplikaciji.'],
            ]);
        }

        if (($payload['exp'] ?? 0) < time()) {
            throw ValidationException::withMessages([
                'identity_token' => ['Apple token je istekao.'],
            ]);
        }

        return $payload;
    }

    private function verifyJwtSignature(string $signedPayload, string $signature, array $jwk): bool
    {
        $pem = $this->jwkToPem($jwk);

        if (!$pem) {
            return false;
        }

        return openssl_verify($signedPayload, $signature, $pem, OPENSSL_ALGO_SHA256) === 1;
    }

    private function jwkToPem(array $jwk): ?string
    {
        if (($jwk['kty'] ?? null) !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
            return null;
        }

        $modulus = $this->base64UrlDecode($jwk['n']);
        $exponent = $this->base64UrlDecode($jwk['e']);

        $components = $this->asn1Sequence([
            $this->asn1Integer($modulus),
            $this->asn1Integer($exponent),
        ]);

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($this->asn1Sequence([
                $this->asn1Sequence([
                    $this->asn1ObjectIdentifier('1.2.840.113549.1.1.1'),
                    "\x05\x00",
                ]),
                $this->asn1BitString($components),
            ])), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4)) ?: '';
    }

    private function asn1Length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $bytes = '';

        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private function asn1Integer(string $value): string
    {
        $value = ltrim($value, "\x00");

        if ($value === '' || (ord($value[0]) & 0x80)) {
            $value = "\x00" . $value;
        }

        return "\x02" . $this->asn1Length(strlen($value)) . $value;
    }

    private function asn1Sequence(array $items): string
    {
        $value = implode('', $items);

        return "\x30" . $this->asn1Length(strlen($value)) . $value;
    }

    private function asn1BitString(string $value): string
    {
        $value = "\x00" . $value;

        return "\x03" . $this->asn1Length(strlen($value)) . $value;
    }

    private function asn1ObjectIdentifier(string $oid): string
    {
        $parts = array_map('intval', explode('.', $oid));
        $value = chr($parts[0] * 40 + $parts[1]);

        foreach (array_slice($parts, 2) as $part) {
            $encoded = chr($part & 0x7f);
            $part >>= 7;

            while ($part > 0) {
                $encoded = chr(0x80 | ($part & 0x7f)) . $encoded;
                $part >>= 7;
            }

            $value .= $encoded;
        }

        return "\x06" . $this->asn1Length(strlen($value)) . $value;
    }

    private function generateGoogleUsername(string $email, string $name): string
    {
        $base = Str::slug(Str::before($email, '@'), '_')
            ?: Str::slug($name, '_')
            ?: 'mahalac';
        $base = Str::lower($base);
        $base = Str::limit($base, 42, '');
        $username = $base;
        $counter = 1;

        while (User::query()->where('username', $username)->exists()) {
            $suffix = "_{$counter}";
            $username = Str::limit($base, 50 - strlen($suffix), '') . $suffix;
            $counter += 1;
        }

        return $username;
    }

    private function sendRegistrationVerificationEmail(string $email, string $code): void
    {
        $html = view('emails.registration-code', [
            'code' => $code,
            'expiresInMinutes' => self::REGISTRATION_CODE_TTL_MINUTES,
        ])->render();

        Mail::mailer('verification')->html($html, function ($message) use ($email) {
            $message->to($email)
                ->from(
                    config('mail.verification_from.address'),
                    config('mail.verification_from.name'),
                )
                ->subject('MAHALA verifikacijski kod');
        });
    }
}
