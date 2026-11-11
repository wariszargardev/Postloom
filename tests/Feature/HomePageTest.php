<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('shows the Postloom home page to guests with a login link', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Welcome')->where('auth.user', null));
});

it('offers registration only while no owner exists', function () {
    $this->get(route('home'))->assertInertia(fn ($page) => $page->where('canRegister', true));

    User::factory()->create();

    $this->get(route('home'))->assertInertia(fn ($page) => $page->where('canRegister', false));
});

it('shows the home page to the logged-in owner', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Welcome')->where('auth.user.id', $user->id));
});
