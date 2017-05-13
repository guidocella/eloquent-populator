<?php

namespace EloquentPopulator\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    public $timestamps = false;

    public function roles()
    {
        return $this->belongsToMany(Role::class)->withPivot('expires_at');
    }

    public function clubs()
    {
        return $this->belongsToMany(Club::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function companies()
    {
        return $this->belongsToMany(Company::class);
    }
}
