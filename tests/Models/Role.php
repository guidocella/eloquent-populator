<?php

namespace EloquentPopulator\Models;

use Dimsav\Translatable\Translatable;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    // use Translatable;

    public $translatedAttributes = ['name'];

    // This method is not used to populate the database, but it tests that nothing wrong
    // happens with a BelongsToMany relation to a model that hasn't been added.
    public function users()
    {
        return $this->belongsToMany(User::class);
    }
}
