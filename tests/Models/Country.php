<?php

namespace EloquentPopulator\Models;

use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;
}
