<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Settings')]
class extends Component {
    public string $name = '';

    public string $email = '';

    public function mount(): void
    {
        $user = Auth::user();

        $this->name = $user->name;
        $this->email = $user->email;
    }

    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validatedProfile = $this->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $user->fill([
            'name' => $validatedProfile['name'],
        ]);

        $user->save();

        $this->dispatch('profile-updated', name: $user->name);
    }
};