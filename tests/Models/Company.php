<?php

namespace GuidoCella\EloquentPopulator\Models;

use GuidoCella\Multilingual\Translatable;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use Translatable;

    public $translatable = ['name'];

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
