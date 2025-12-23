<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserAdWatchCount extends Model
{
    use HasFactory;

    protected $table = 'user_ad_watch_counts';
    protected $fillable = ['user_id', 'watch_date', 'count'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
