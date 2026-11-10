<?php

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Log::shouldReceive('channel')->with('postloom')->andReturnSelf();
});

it('logs a successful login with a masked email', function () {
    $user = User::factory()->create(['email' => 'waris@example.com']);

    Log::shouldReceive('info')->once()->with('User logged in', [
        'user_id' => $user->id,
        'email' => 'wa***@example.com',
        'remember' => false,
    ]);

    $this->post(route('login.store'), [
        'email' => 'waris@example.com',
        'password' => 'password',
    ]);

    expect(Context::get('feature'))->toBe('auth')
        ->and(Context::get('event'))->toBe('login');
});

it('logs a failed login without the password', function () {
    Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context) {
        return $message === 'Login failed'
            && $context === ['email' => 'wa***@example.com', 'user_exists' => false];
    });

    event(new Failed('web', null, ['email' => 'waris@example.com', 'password' => 'secret-password']));

    expect(Context::get('event'))->toBe('failed');
});

it('logs a logout', function () {
    $user = User::factory()->create(['email' => 'waris@example.com']);

    Log::shouldReceive('info')->once()->with('User logged out', [
        'user_id' => $user->id,
        'email' => 'wa***@example.com',
    ]);

    $this->actingAs($user)->post(route('logout'));
});

it('logs a lockout', function () {
    $request = Request::create('/login', 'POST', ['email' => 'waris@example.com']);

    Log::shouldReceive('warning')->once()->with('Too many login attempts, login locked', [
        'email' => 'wa***@example.com',
    ]);

    event(new Lockout($request));
});

it('logs the owner registration', function () {
    Log::shouldReceive('info')->once()->with('Owner account registered', Mockery::on(
        fn (array $context) => $context['email'] === 'te***@example.com' && $context['user_id'] !== null
    ));
    Log::shouldReceive('info')->once()->with('User logged in', Mockery::any());

    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);
});

it('logs a password reset', function () {
    $user = User::factory()->create(['email' => 'waris@example.com']);

    Log::shouldReceive('info')->once()->with('Password reset', [
        'user_id' => $user->id,
        'email' => 'wa***@example.com',
    ]);

    event(new PasswordReset($user));
});
