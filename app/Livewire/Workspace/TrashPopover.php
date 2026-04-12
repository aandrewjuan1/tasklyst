<?php

namespace App\Livewire\Workspace;

use App\Actions\Event\ForceDeleteEventAction;
use App\Actions\Event\RestoreEventAction;
use App\Actions\Project\ForceDeleteProjectAction;
use App\Actions\Project\RestoreProjectAction;
use App\Actions\Task\ForceDeleteTaskAction;
use App\Actions\Task\RestoreTaskAction;
use App\Livewire\Concerns\HandlesTrash;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class TrashPopover extends Component
{
    use AuthorizesRequests;
    use HandlesTrash;

    protected ForceDeleteEventAction $forceDeleteEventAction;

    protected ForceDeleteProjectAction $forceDeleteProjectAction;

    protected ForceDeleteTaskAction $forceDeleteTaskAction;

    protected RestoreEventAction $restoreEventAction;

    protected RestoreProjectAction $restoreProjectAction;

    protected RestoreTaskAction $restoreTaskAction;

    public function boot(
        ForceDeleteEventAction $forceDeleteEventAction,
        ForceDeleteProjectAction $forceDeleteProjectAction,
        ForceDeleteTaskAction $forceDeleteTaskAction,
        RestoreEventAction $restoreEventAction,
        RestoreProjectAction $restoreProjectAction,
        RestoreTaskAction $restoreTaskAction,
    ): void {
        $this->forceDeleteEventAction = $forceDeleteEventAction;
        $this->forceDeleteProjectAction = $forceDeleteProjectAction;
        $this->forceDeleteTaskAction = $forceDeleteTaskAction;
        $this->restoreEventAction = $restoreEventAction;
        $this->restoreProjectAction = $restoreProjectAction;
        $this->restoreTaskAction = $restoreTaskAction;
    }

    /**
     * Notify the workspace page (when mounted) to remount list/kanban after a successful restore.
     */
    protected function afterTrashRestored(): void
    {
        $this->dispatch('workspace-trash-restored');
    }

    protected function requireAuth(string $message): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            $this->dispatch('toast', type: 'error', message: $message);

            return null;
        }

        return $user;
    }

    public function render()
    {
        return view('livewire.workspace.trash-popover');
    }
}
