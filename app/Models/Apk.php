<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Apk extends Model
{
    use HasFactory;

    protected $fillable = [
        'version',
        'file_name',
        'file_path',
        'file_size',
        'play_store_url',
        'status',
        'download_count',
    ];

    protected $casts = [
        'download_count' => 'integer',
        'file_size' => 'integer',
    ];

    // Ensure computed attributes appear in array/json
    protected $appends = ['download_url'];

    // Get download URL
    public function getDownloadUrlAttribute()
    {
        return route('apk.download', ['id' => $this->id]);
    }
}
