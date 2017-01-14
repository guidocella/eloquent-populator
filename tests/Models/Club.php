<?php

namespace EloquentPopulator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Club extends Model
{
    use SoftDeletes;

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
