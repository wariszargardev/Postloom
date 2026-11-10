<?php

use App\Http\Controllers\CalendarController;
use App\Http\Controllers\LinkedInConnectController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\SocialAccountController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::post('workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
    Route::patch('workspaces/{workspace}', [WorkspaceController::class, 'update'])->name('workspaces.update');
    Route::delete('workspaces/{workspace}', [WorkspaceController::class, 'destroy'])->name('workspaces.destroy');
    Route::post('workspaces/{workspace}/switch', [WorkspaceController::class, 'switch'])->name('workspaces.switch');

    Route::get('accounts', [SocialAccountController::class, 'index'])->name('accounts.index');
    Route::delete('accounts/{socialAccount}', [SocialAccountController::class, 'destroy'])->name('accounts.destroy');

    Route::get('accounts/linkedin/redirect', [LinkedInConnectController::class, 'redirect'])->name('accounts.linkedin.redirect');
    Route::get('accounts/linkedin/callback', [LinkedInConnectController::class, 'callback'])->name('accounts.linkedin.callback');

    Route::get('media', [MediaController::class, 'index'])->name('media.index');
    Route::post('media', [MediaController::class, 'store'])->name('media.store');
    Route::get('media/{media}/file', [MediaController::class, 'show'])->name('media.show');
    Route::get('media/{media}/thumbnail', [MediaController::class, 'thumbnail'])->name('media.thumbnail');
    Route::patch('media/{media}', [MediaController::class, 'update'])->name('media.update');
    Route::delete('media/{media}', [MediaController::class, 'destroy'])->name('media.destroy');

    Route::get('posts', [PostController::class, 'index'])->name('posts.index');
    Route::get('posts/create', [PostController::class, 'create'])->name('posts.create');
    Route::post('posts', [PostController::class, 'store'])->name('posts.store');
    Route::get('posts/{post}/edit', [PostController::class, 'edit'])->name('posts.edit');
    Route::put('posts/{post}', [PostController::class, 'update'])->name('posts.update');
    Route::post('posts/{post}/unschedule', [PostController::class, 'unschedule'])->name('posts.unschedule');
    Route::post('posts/{post}/duplicate', [PostController::class, 'duplicate'])->name('posts.duplicate');
    Route::post('posts/{post}/retry', [PostController::class, 'retry'])->name('posts.retry');
    Route::delete('posts/{post}', [PostController::class, 'destroy'])->name('posts.destroy');

    Route::get('calendar', [CalendarController::class, 'index'])->name('calendar.index');
    Route::get('calendar/events', [CalendarController::class, 'events'])->name('calendar.events');
    Route::patch('posts/{post}/reschedule', [CalendarController::class, 'reschedule'])->name('posts.reschedule');
});

require __DIR__.'/settings.php';
