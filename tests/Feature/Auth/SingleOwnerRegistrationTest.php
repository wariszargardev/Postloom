<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('shows the register page and sign-up link while no owner exists', function () {
    $this->get(route('register'))->assertOk();

    $this->get(route('login'))
        ->assertInertia(fn ($page) => $page->where('canRegister', true));
});

it('sends the register page to login once an owner exists', function () {
    User::factory()->create();

    $this->get(route('register'))->assertRedirect(route('login'));

    $this->get(route('login'))
        ->assertInertia(fn ($page) => $page->where('canRegister', false));
});

it('blocks a second registration on the server', function () {
    User::factory()->create();

    $this->post(route('register.store'), [
        'name' => 'Second User',
        'email' => 'second@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors(['email' => 'Registration is closed.']);

    $this->assertGuest();
    expect(User::query()->count())->toBe(1);
});

it('reopens registration when the env switch is on', function () {
    User::factory()->create();
    config(['postloom.allow_registration' => true]);

    $this->get(route('register'))->assertOk();

    $this->post(route('register.store'), [
        'name' => 'Second User',
        'email' => 'second@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('calendar.index', absolute: false));

    expect(User::query()->count())->toBe(2);
});
