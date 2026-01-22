<?php

use App\Models\User;
use Laravel\WorkOS\Http\Requests\AuthKitAccountDeletionRequest;
use Livewire\Component;

new class extends Component {
    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(AuthKitAccountDeletionRequest $request): void
    {
        $request->delete(
            using: fn (User $user) => $user->delete()
        );

        $this->redirect('/', navigate: true);
    }
}; 