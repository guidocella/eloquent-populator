<?php

namespace GuidoCella\EloquentPopulator\Models;

use Illuminate\Database\Eloquent\Model;

// use GuidoCella\Multilingual\Translatable;

class User extends Model
{
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function friend()
    {
        return $this->belongsTo(User::class);
    }
}
