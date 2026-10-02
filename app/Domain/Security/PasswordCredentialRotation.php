<?php

namespace App\Domain\Security;

use App\Models\ClientApiKey;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class PasswordCredentialRotation
{
    public function rotate(User $user, string $password): User
    {
        $passwordHash = Hash::make($password);
        $rememberToken = Str::random(60);

        return DB::transaction(function () use ($user, $passwordHash, $rememberToken): User {
            $target = User::query()->lockForUpdate()->findOrFail($user->user_id);
            $target->forceFill([
                'password' => $passwordHash,
                'remember_token' => $rememberToken,
                'api_key' => null,
            ])->save();

            ClientApiKey::query()->where('user_id', $target->user_id)
                ->whereNull('revoked_at')->update(['revoked_at' => now()]);

            return $target;
        });
    }
}
