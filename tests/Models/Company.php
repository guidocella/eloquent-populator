<?php

namespace EloquentPopulator\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    public function country()
    {
        return $this->belongsTo(Country::class, 'country_code');
    }

    public function users()
    {
        return $this->belongsToMany(User::class);
    }
}
