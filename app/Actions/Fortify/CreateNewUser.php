<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\LogFeature;
use App\Models\User;
use App\Services\LogService;
use App\Services\RegistrationGate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private RegistrationGate $registrationGate) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        if (! $this->registrationGate->isOpen()) {
            LogService::startFeatureLogging(LogFeature::Auth, ['event' => 'registration_blocked']);
            LogService::warning('Registration blocked because an owner already exists', []);

            throw ValidationException::withMessages([
                'email' => __('Registration is closed.'),
            ]);
        }

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
    }
}
