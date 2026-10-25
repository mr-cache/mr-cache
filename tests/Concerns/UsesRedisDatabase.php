<?php

declare(strict_types=1);

namespace MrCache\Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MrCache\Contracts\CacheClientInterface;
use MrCache\Tests\Models\Post;

trait UsesRedisDatabase
{
    private CacheClientInterface $client;

    protected function setUp(): void
    {
        parent::setUp();
        if (!getenv('MRCACHE_TEST_REDIS_PORT')) {
            $this->markTestSkipped('Set MRCACHE_TEST_REDIS_PORT to an isolated Redis server port.');
        }
        $this->client = $this->app->make(CacheClientInterface::class);
        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->string('secret')->default('private');
            $table->boolean('active')->default(false);
            $table->integer('counter')->default(0);
            $table->unsignedInteger('parent_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Post::create(['title' => 'First', 'active' => true]);
        Post::create(['title' => 'Second']);
        DB::connection()->enableQueryLog();
    }

    protected function tearDown(): void
    {
        if (isset($this->client)) {
            foreach ($this->client->scanKeys(config('mrcache.prefix').':*') as $key) {
                $this->client->del($key);
            }
        }
        parent::tearDown();
    }

    private function selects(): int
    {
        return count(array_filter(DB::connection()->getQueryLog(), fn ($query) => str_starts_with(strtolower($query['query']), 'select')));
    }

}
