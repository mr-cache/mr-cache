<?php

declare(strict_types=1);

namespace MrCache\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use MrCache\Traits\CacheableModel;

class Post extends Model
{
    use CacheableModel;
    use SoftDeletes;

    protected $table = 'posts';
    protected $guarded = [];
    protected $hidden = ['secret'];
    protected $casts = ['active' => 'boolean', 'counter' => 'integer', 'created_at' => 'datetime'];

    public function peers()
    {
        return $this->belongsToMany(self::class, 'post_links', 'source_id', 'related_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
