<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PushNotification extends Model
{
    use HasFactory;

    protected $table = 'push_notifications';

    protected $fillable = [
        'admin_user_id', 'title', 'body', 'data', 'sent_at', 'status'
    ];

    protected $casts = [
        'data' => 'array',
        'sent_at' => 'datetime',
    ];
}
