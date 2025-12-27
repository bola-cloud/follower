<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserReferral extends Model
{
    use HasFactory;

    protected $table = 'user_referrals';

    protected $fillable = [
        'user_id',
        'referrer_id',
        'code_used',
    ];
}
