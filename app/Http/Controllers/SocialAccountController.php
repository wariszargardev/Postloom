<?php

namespace App\Http\Controllers;

use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Services\SocialAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SocialAccountController extends Controller
{
    public function __construct(private SocialAccountService $socialAccountService) {}

    public function index(Request $request): Response
    {
        $accounts = $request->user()->currentWorkspace
            ->socialAccounts()
            ->orderBy('provider')
            ->orderBy('name')
            ->get()
            ->map(fn (SocialAccount $account): array => [
                'id' => $account->id,
                'provider' => $account->provider->value,
                'account_type' => $account->account_type->label(),
                'name' => $account->name,
                'handle' => $account->handle,
                'avatar_url' => $account->avatar_url,
                'status' => $account->status()->value,
                'status_label' => $account->status()->label(),
                'token_expires_at' => $account->token_expires_at?->toIso8601String(),
                'connected_at' => $account->connected_at?->toIso8601String(),
            ]);

        $providers = collect(SocialProvider::cases())->map(fn (SocialProvider $provider): array => [
            'value' => $provider->value,
            'label' => $provider->label(),
            'is_available' => $provider->isAvailable(),
            'availability_note' => $provider->availabilityNote(),
        ]);

        return Inertia::render('accounts/Index', [
            'accounts' => $accounts,
            'providers' => $providers,
        ]);
    }

    public function destroy(SocialAccount $socialAccount): RedirectResponse
    {
        Gate::authorize('delete', $socialAccount);

        $this->socialAccountService->disconnect($socialAccount);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account disconnected.')]);

        return to_route('accounts.index');
    }
}
