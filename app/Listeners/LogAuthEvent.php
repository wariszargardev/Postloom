<?php

namespace App\Listeners;

use App\Enums\LogFeature;
use App\Models\User;
use App\Services\LogService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LogAuthEvent
{
    public function __construct(private Request $request) {}

    public function handle(Login|Failed|Logout|Lockout|Registered|PasswordReset $event): void
    {
        $eventName = Str::snake(class_basename($event));

        LogService::startFeatureLogging(LogFeature::Auth, [
            'event' => $eventName,
            'ip' => $this->request->ip(),
        ]);

        match (true) {
            $event instanceof Login => LogService::info('User logged in', [
                ...$this->userContext($event->user),
                'remember' => $event->remember,
            ]),
            $event instanceof Failed => LogService::warning('Login failed', [
                'email' => $this->maskEmail($event->credentials['email'] ?? null),
                'user_exists' => $event->user !== null,
            ]),
            $event instanceof Logout => LogService::info('User logged out', $this->userContext($event->user)),
            $event instanceof Lockout => LogService::warning('Too many login attempts, login locked', [
                'email' => $this->maskEmail($event->request->input('email')),
            ]),
            $event instanceof Registered => LogService::info('Owner account registered', $this->userContext($event->user)),
            $event instanceof PasswordReset => LogService::info('Password reset', $this->userContext($event->user)),
        };
    }

    /**
     * @return array{user_id: int|string|null, email: string|null}
     */
    private function userContext(?Authenticatable $user): array
    {
        return [
            'user_id' => $user?->getAuthIdentifier(),
            'email' => $this->maskEmail($user instanceof User ? $user->email : null),
        ];
    }

    private function maskEmail(mixed $email): ?string
    {
        if (! is_string($email) || ! str_contains($email, '@')) {
            return null;
        }

        [$name, $domain] = explode('@', $email, 2);

        return Str::substr($name, 0, 2).'***@'.$domain;
    }
}
