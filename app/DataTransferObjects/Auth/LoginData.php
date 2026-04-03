<?php

namespace App\DataTransferObjects\Auth;

readonly class LoginData
{
    public function __construct(
        public string $email,
        public string $password,
    ) {
    }
}
