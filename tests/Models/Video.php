<?php

namespace EloquentPopulator\Models;

use Illuminate\Database\Eloquent\Model;

class Video extends Model
{
    public function comment()
    {
        return $this->morphOne(Comment::class, 'commentable');
    }

    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }
}
