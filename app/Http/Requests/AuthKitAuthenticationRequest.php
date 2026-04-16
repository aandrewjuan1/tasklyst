<?php

namespace App\Http\Requests;

use App\Models\User as AppUser;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Laravel\WorkOS\User;
use Laravel\WorkOS\WorkOS;
use WorkOS\UserManagement;

class AuthKitAuthenticationRequest extends FormRequest
{
    public function authenticate(): AppUser
    {
        WorkOS::configure();

        $this->ensureStateIsValid();

        $authentication = (new UserManagement)->authenticateWithCode(
            config('services.workos.client_id'),
            $this->query('code'),
        );

        [$userFromWorkOs, $accessToken, $refreshToken] = [
            $authentication->user,
            $authentication->access_token,
            $authentication->refresh_token,
        ];

        $workOsUser = new User(
            id: $userFromWorkOs->id,
            firstName: $userFromWorkOs->firstName,
            lastName: $userFromWorkOs->lastName,
            email: $userFromWorkOs->email,
            avatar: $userFromWorkOs->profilePictureUrl,
        );

        $existingUser = $this->findUsing($workOsUser);

        if (! $existingUser) {
            $existingUser = $this->createUsing($workOsUser);

            event(new Registered($existingUser));
        } else {
            $existingUser = $this->updateUsing($existingUser, $workOsUser);
        }

        Auth::guard('web')->login($existingUser);

        $this->session()->put('workos_access_token', $accessToken);
        $this->session()->put('workos_refresh_token', $refreshToken);
        $this->session()->regenerate();

        return $existingUser;
    }

    protected function findUsing(User $user): ?AppUser
    {
        $userByWorkOsId = AppUser::query()
            ->where('workos_id', $user->id)
            ->first();

        if ($userByWorkOsId) {
            return $userByWorkOsId;
        }

        return AppUser::query()
            ->where('email', $user->email)
            ->first();
    }

    protected function createUsing(User $user): AppUser
    {
        return AppUser::create([
            'name' => trim($user->firstName.' '.$user->lastName),
            'email' => $user->email,
            'email_verified_at' => now(),
            'workos_id' => $user->id,
            'avatar' => $user->avatar ?? '',
        ]);
    }

    protected function updateUsing(AppUser $user, User $userFromWorkOS): AppUser
    {
        $user->update([
            'workos_id' => $userFromWorkOS->id,
            'avatar' => $userFromWorkOS->avatar ?? '',
        ]);

        return $user->fresh();
    }

    protected function ensureStateIsValid(): void
    {
        $state = json_decode($this->query('state'), true)['state'] ?? false;

        if ($state !== $this->session()->get('state')) {
            abort(403);
        }

        $this->session()->forget('state');
    }
}
