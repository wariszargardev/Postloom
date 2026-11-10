<?php

use App\Http\Controllers\LinkedInConnectController;
use App\Http\Controllers\MediaController;
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
});

require __DIR__.'/settings.php';
