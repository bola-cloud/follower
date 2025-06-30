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
        'user_id',
    ];
}
