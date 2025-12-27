<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasProfilePhoto;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'profile_link',
        'points',
        'type',
        'timer',
        'cookies',
        'invitation_code',
    ];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    // Relationship: A user can have many actions (both 'follow' and 'like')
    public function actions()
    {
        return $this->hasMany(Action::class);
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
        // cookies may contain sensitive session data for another app
        'cookies',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'timer' => 'datetime', // ✅ This is required!
        // store cookies as JSON decoded to array when reading
        'cookies' => 'array',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    public function toArray()
    {
        $data = parent::toArray();

        if ($this->timer) {
            $data['timer'] = $this->timer->timezone(config('app.timezone'))->format('Y-m-d H:i:s');
        }

        return $data;
    }

}
