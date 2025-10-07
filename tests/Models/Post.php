<?php

namespace MrCache\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use MrCache\Traits\CacheableModel;

class Post extends Model
{
    use CacheableModel;

    protected $table = 'posts';
    protected $guarded = [];
}
