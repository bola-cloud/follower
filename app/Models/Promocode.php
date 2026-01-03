<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Promocode extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'points',
        'max_uses',
        'uses_count',
        'expires_at',
        'used_by'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    // Deprecated: kept for backward compatibility if needed, but new logic uses pivot
    public function user()
    {
        return $this->belongsTo(User::class, 'used_by');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'promocode_user')
            ->withPivot('used_at');
    }
}
