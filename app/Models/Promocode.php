<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Promocode extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'points', 'expires_at', 'activated_at', 'used_by'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'activated_at' => 'datetime',
    ];
    protected $dates = ['expires_at', 'activated_at'];


    public function user()
    {
        return $this->belongsTo(User::class, 'used_by');
    }
}
