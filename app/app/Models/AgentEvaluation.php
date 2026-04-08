<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentEvaluation extends Model
{
    protected $fillable = [
        'reservation_id',
        'reservation_type',
        'reservation_data',
        'budget',
        'reservation_price',
        'decision',
        'reason',
        'alternative',
        'savings',
        'savings_percentage',
        'api_fallback',
        'trace',
    ];

    protected $casts = [
        'reservation_data' => 'array',
        'alternative'      => 'array',
        'budget'           => 'float',
        'reservation_price' => 'float',
        'savings'          => 'float',
        'savings_percentage' => 'float',
        'api_fallback'     => 'boolean',
        'trace'            => 'array',
    ];
}
