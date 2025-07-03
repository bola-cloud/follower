<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'total_count',
        'done_count',
        'cost',
        'status',
        'target_url',
        'target_url_hash', // ✅ ADD THIS
        'user_id',
    ];

        public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Relationship: An order can have many actions performed by users
    public function actions()
    {
        return $this->hasMany(Action::class);
    }
}
