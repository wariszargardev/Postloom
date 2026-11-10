<?php

namespace App\Services;

use App\Models\User;

class RegistrationGate
{
    public function isOpen(): bool
    {
        return config('postloom.allow_registration') || User::query()->doesntExist();
    }
}
