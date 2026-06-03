<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:50', 'alpha_dash', 'unique:users,username'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $user = User::query()->create([
            'name' => $validated['username'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        return response()->json([
            'message' => 'Registracija je uspjesna.',
            'token' => $user->createToken('auth_token')->plainTextToken,
            'user' => $this->formatUser($user),
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
        }

        return response()->json([
            'message' => 'Google prijava je uspjeÅ¡na.',
            'token' => $user->createToken('auth_token')->plainTextToken,
            'user' => $this->formatUser($user->refresh()),
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

    private function generateGoogleUsername(string $email, string $name): string
    {
        $base = Str::slug(Str::before($email, '@'), '_')
            ?: Str::slug($name, '_')
            ?: 'mahalac';
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
}
