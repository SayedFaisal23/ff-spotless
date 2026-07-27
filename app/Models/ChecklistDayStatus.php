<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChecklistDayStatus extends Model
{
    protected $primaryKey = 'date';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['date', 'is_unavailable'];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'is_unavailable' => 'boolean',
        ];
    }
}
