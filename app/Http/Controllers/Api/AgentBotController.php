<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AgentBotController extends Controller
{
    private const FIELDS = ['id', 'username', 'name', 'bot', 'region'];
    private const REGION = 'in:ba,cro,srb,slo,any';

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'region' => ['sometimes', self::REGION],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = User::where('bot', true)->orderBy('id');
        if (isset($filters['region'])) {
            $query->where('region', $filters['region']);
        }

        return response()->json($query->paginate(100, self::FIELDS));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'users' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'users.*' => ['required', 'array:username,name,region'],
            'users.*.username' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{2,49}$/D', 'distinct'],
            'users.*.name' => ['required', 'string', 'max:120'],
            'users.*.region' => ['sometimes', self::REGION],
        ]);

        try {
            return DB::transaction(function () use ($data) {
                $result = [];
                $created = 0;
                foreach ($data['users'] as $profile) {
                    $username = $profile['username'];
                    $region = $profile['region'] ?? 'any';
                    $user = User::where('username', $username)->first();
                    if ($user) {
                        abort_unless($user->bot && $user->name === $profile['name'] && $user->region === $region,
                            409, "Korisničko ime je zauzeto: {$username}");
                    } else {
                        $user = new User();
                        $user->username = $username;
                        $user->name = $profile['name'];
                        $user->region = $region;
                        $user->bot = true;
                        $user->email = $username.'@bots.mahala.invalid';
                        $user->password = Str::random(64);
                        $user->save();
                        $created++;
                    }
                    $result[] = $user->only(self::FIELDS);
                }

                return response()->json(['created' => $created, 'data' => $result], $created ? 201 : 200);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => 'Korisničko ime ili email je zauzet.'], 409);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $profile = $request->validate([
            'name' => ['required_without:region', 'string', 'max:120'],
            'region' => ['required_without:name', self::REGION],
        ]);
        $user = User::where('bot', true)->findOrFail($id);
        $user->forceFill($profile)->save();

        return response()->json(['data' => $user->only(self::FIELDS)]);
    }
}
