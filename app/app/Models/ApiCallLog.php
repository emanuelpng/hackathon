<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiCallLog extends Model
{
    protected $fillable = [
        'service',
        'method',
        'endpoint',
        'status_code',
        'request_payload',
        'response_body',
        'response_raw',
        'success',
        'duration_ms',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_body'   => 'array',
        'success'         => 'boolean',
    ];
}
