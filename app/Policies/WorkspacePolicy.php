<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    public function update(User $user, Workspace $workspace): bool
    {
        return $workspace->user_id === $user->id;
    }

    public function switch(User $user, Workspace $workspace): bool
    {
        return $workspace->user_id === $user->id;
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $workspace->user_id === $user->id;
    }
}
