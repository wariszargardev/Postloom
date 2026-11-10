<?php

namespace App\Policies;

use App\Models\Media;
use App\Models\User;

class MediaPolicy
{
    public function view(User $user, Media $media): bool
    {
        return $media->workspace->user_id === $user->id;
    }

    public function update(User $user, Media $media): bool
    {
        return $media->workspace->user_id === $user->id;
    }

    public function delete(User $user, Media $media): bool
    {
        return $media->workspace->user_id === $user->id;
    }
}
