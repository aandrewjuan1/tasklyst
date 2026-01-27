<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Component;

new class extends Component {
    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(): void
    {
        /** @var User $user */
        $user = Auth::user();

        Auth::logout();

        $user->delete();

        Session::invalidate();
        Session::regenerateToken();

        $this->redirect('/', navigate: true);
    }
};

