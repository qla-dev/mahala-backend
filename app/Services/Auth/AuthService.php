<?php

namespace App\Services\Auth;

use App\DataTransferObjects\Auth\LoginData;
use App\DataTransferObjects\Auth\RegisterData;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
    ) {
    }

    public function register(RegisterData $data): array
    {
        return DB::transaction(function () use ($data) {
            $user = $this->users->create([
                'first_name' => $data->firstName,
                'last_name' => $data->lastName,
                'username' => $data->username,
                'email' => $data->email,
                'password' => $data->password,
                'role' => 'user',
                'karma' => 0,
                'is_premium' => false,
            ]);

            return [
                'token' => $user->createToken('mobile')->plainTextToken,
                'user' => $user,
            ];
        });
    }

    public function login(LoginData $data): array
    {
        $user = $this->users->findByEmail($data->email);

        if (! $user || ! Hash::check($data->password, $user->password)) {
            throw new AuthenticationException('The provided credentials are incorrect.');
        }

        return [
            'token' => $user->createToken('mobile')->plainTextToken,
            'user' => $user,
        ];
    }
}
