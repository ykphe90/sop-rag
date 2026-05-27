<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SopDocument extends Model
{
    protected $fillable = [
        'file_code',
        'section_title',
        'content',
        'metadata',
        // 注意：embedding 不在 fillable 里
        // 因为 vector 类型需要用原生 SQL 插入
    ];

    protected $casts = [
        'metadata' => 'array',
    ];
}
