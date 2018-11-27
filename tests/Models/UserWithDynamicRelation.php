<?php

namespace EloquentPopulator\Models;

use Illuminate\Database\Eloquent\Model;

class UserWithDynamicRelation extends Model
{
    protected $table = 'users_with_dynamic_relation';

    public $timestamps = false;

    public function login()
    {
        return $this->belongsTo(Login::class);
    }
}
