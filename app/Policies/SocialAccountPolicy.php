<?php

namespace App\Policies;

use App\Models\SocialAccount;
use App\Models\User;

class SocialAccountPolicy
{
    public function delete(User $user, SocialAccount $socialAccount): bool
    {
        return $socialAccount->workspace->user_id === $user->id;
    }
}
