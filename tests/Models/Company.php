<?php

namespace EloquentPopulator\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    public $timestamps = false;

    public function country()
    {
        return $this->belongsTo(Country::class, 'country_code');
    }
}
