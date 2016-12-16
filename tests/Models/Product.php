<?php

namespace EloquentPopulator\Models;

use Dimsav\Translatable\Translatable;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use Translatable;

    public $translatedAttributes = ['name', 'description'];
}
