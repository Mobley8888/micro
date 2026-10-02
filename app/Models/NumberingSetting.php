<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NumberingSetting extends Model
{
    protected $fillable = ['company_id', 'document_type', 'prefix', 'next_number', 'padding', 'year'];
}
