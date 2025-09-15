<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'date',
        'hours_worked',
        'total_hours',
        'earnings',
        'base_pay',      // YENİ
        'tips',          // YENİ
        'service_type',  // YENİ
        'miles',
        'gas_cost',
        'notes'
    ];

    protected $casts = [
        'date' => 'date',
        'hours_worked' => 'decimal:2',
        'total_hours' => 'decimal:2',
        'earnings' => 'decimal:2',
        'miles' => 'decimal:2',
        'gas_cost' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
