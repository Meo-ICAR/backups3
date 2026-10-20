<?php

namespace App\Models;

use DutchCodingCompany\FilamentSocialite\Models\Contracts\FilamentSocialiteUser as FilamentSocialiteUserContract;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;

/**
 * Account Microsoft o Google collegato a un utente della suite. La tabella `socialite_users` è condivisa con le altre app: qui i token
 * non si salvano (non servono, e quelli delle altre app sono cifrati con la loro chiave).
 */
class SocialiteUser extends Model implements FilamentSocialiteUserContract
{
    protected $table = 'socialite_users';

    protected $guarded = ['id'];

    protected $hidden = ['token', 'refresh_token'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getUser(): Authenticatable
    {
        $user = $this->user;

        assert($user instanceof Authenticatable);

        return $user;
    }

    public static function findForProvider(string $provider, SocialiteUserContract $oauthUser): ?self
    {
        return self::query()
            ->where('provider', $provider)
            ->where('provider_id', $oauthUser->getId())
            ->first();
    }

    public static function createForProvider(string $provider, SocialiteUserContract $oauthUser, Authenticatable $user): self
    {
        return self::query()->create([
            'user_id' => $user->getKey(),
            'provider' => $provider,
            'provider_id' => $oauthUser->getId(),
            'email' => $oauthUser->getEmail(),
        ]);
    }
}
