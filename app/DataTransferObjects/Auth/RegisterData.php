<?php

namespace App\DataTransferObjects\Auth;

readonly class RegisterData
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $username,
        public string $email,
        public string $password,
    ) {
    }
}
