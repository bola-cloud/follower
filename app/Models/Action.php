<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Action extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'user_id', 'type', 'status', 'performed_at'
    ];

    // Relationship: An action belongs to an order
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    // Relationship: An action belongs to a user
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
