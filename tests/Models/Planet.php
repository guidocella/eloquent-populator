<?php

namespace EloquentPopulator\Models;

use Illuminate\Database\Eloquent\Model;
use Themsaid\Multilingual\Translatable;

class Planet extends Model
{
    use Translatable;

    public $translatable = ['name', 'order'];

    public $timestamps = false;

    protected $casts = [
        'name'  => 'array',
        'order' => 'array',
    ];
}
