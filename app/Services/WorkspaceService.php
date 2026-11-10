<?php

namespace App\Services;

use App\Enums\LogFeature;
use App\Models\User;
use App\Models\Workspace;

class WorkspaceService
{
    public const DEFAULT_NAME = 'Personal';

    public function ensureCurrent(User $user): Workspace
    {
        $current = $user->currentWorkspace;

        if ($current !== null && $current->user_id === $user->id) {
            return $current;
        }

        $workspace = $user->workspaces()->oldest('id')->first();

        if ($workspace === null) {
            $workspace = $user->workspaces()->create(['name' => self::DEFAULT_NAME]);

            LogService::startFeatureLogging(LogFeature::Workspaces, ['user_id' => $user->id]);
            LogService::info('Default workspace created', ['workspace_id' => $workspace->id]);
        }

        $this->switchTo($user, $workspace);

        return $workspace;
    }

    public function create(User $user, string $name): Workspace
    {
        $workspace = $user->workspaces()->create(['name' => $name]);

        LogService::startFeatureLogging(LogFeature::Workspaces, ['user_id' => $user->id]);
        LogService::info('Workspace created', ['workspace_id' => $workspace->id]);

        $this->switchTo($user, $workspace);

        return $workspace;
    }

    public function rename(Workspace $workspace, string $name): Workspace
    {
        $oldName = $workspace->name;
        $workspace->update(['name' => $name]);

        LogService::startFeatureLogging(LogFeature::Workspaces, ['user_id' => $workspace->user_id]);
        LogService::info('Workspace renamed', [
            'workspace_id' => $workspace->id,
            'old_name' => $oldName,
            'new_name' => $name,
        ]);

        return $workspace;
    }

    public function switchTo(User $user, Workspace $workspace): void
    {
        if ($user->current_workspace_id === $workspace->id) {
            return;
        }

        $user->forceFill(['current_workspace_id' => $workspace->id])->save();
        $user->setRelation('currentWorkspace', $workspace);

        LogService::startFeatureLogging(LogFeature::Workspaces, ['user_id' => $user->id]);
        LogService::info('Switched workspace', ['workspace_id' => $workspace->id]);
    }

    public function deletionBlockReason(Workspace $workspace): ?string
    {
        return match (true) {
            $workspace->socialAccounts()->exists() => 'Disconnect its accounts before deleting this workspace.',
            $workspace->user->workspaces()->count() <= 1 => 'You need at least one workspace.',
            default => null,
        };
    }

    public function delete(Workspace $workspace): void
    {
        $user = $workspace->user;
        $workspaceId = $workspace->id;

        LogService::startFeatureLogging(LogFeature::Workspaces, ['user_id' => $user->id]);

        $blockReason = $this->deletionBlockReason($workspace);

        if ($blockReason !== null) {
            LogService::warning('Workspace delete blocked', ['workspace_id' => $workspaceId, 'reason' => $blockReason]);

            return;
        }

        $workspace->delete();

        LogService::info('Workspace deleted', ['workspace_id' => $workspaceId]);

        if ($user->current_workspace_id === $workspaceId || $user->current_workspace_id === null) {
            $user->unsetRelation('currentWorkspace');
            $user->refresh();
            $this->ensureCurrent($user);
        }
    }
}
