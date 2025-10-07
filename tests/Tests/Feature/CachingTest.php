<?php

namespace MrCache\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MrCache\Contracts\CacheClientInterface;
use MrCache\Contracts\InvalidationInterface;
use MrCache\Tests\Models\Post;
use MrCache\Tests\TestCase;

class CachingTest extends TestCase
{
    private CacheClientInterface $mockClient;
    private InvalidationInterface $mockInvalidator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockInvalidator = $this->createMock(InvalidationInterface::class);
        $this->app->singleton(InvalidationInterface::class, fn() => $this->mockInvalidator);

        $this->mockClient = $this->createMock(CacheClientInterface::class);
        $this->app->singleton(CacheClientInterface::class, fn() => $this->mockClient);

        $this->setupDatabase();
    }

    protected function setupDatabase(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->timestamps();
        });

        Post::create(['title' => 'First Post']);
        Post::create(['title' => 'Second Post']);
    }

    /** @test */
    public function it_caches_a_query_on_the_first_run(): void
    {
        $this->mockClient->expects($this->once())
            ->method('get')
            ->willReturn(null); // Cache miss

        $this->mockClient->expects($this->once())
            ->method('pipeline'); // Store result in pipeline

        $posts = Post::all();
        $this->assertCount(2, $posts);
    }

    /** @test */
    public function it_retrieves_a_query_from_cache_on_the_second_run(): void
    {
        $cachedPayload = json_encode([
            'table' => 'posts',
            'pks' => [1, 2],
            'relations' => [],
            'created_at' => time(),
            'data' => [
                ['id' => 1, 'title' => 'First Post'],
                ['id' => 2, 'title' => 'Second Post'],
            ]
        ]);

        $this->mockClient->expects($this->once())
            ->method('get')
            ->willReturn($cachedPayload);

        DB::shouldReceive('select')->never();

        $posts = Post::all();
        $this->assertCount(2, $posts);
        $this->assertEquals('First Post', $posts->first()->title);
    }

    /** @test */
    public function without_caching_macro_bypasses_the_cache(): void
    {
        $this->mockClient->expects($this->never())->method('get');
        $this->mockClient->expects($this->never())->method('set');

        Post::withoutCaching()->get();
    }

    /** @test */
    public function saving_a_model_invalidates_the_cache(): void
    {
        $this->mockInvalidator->expects($this->once())
            ->method('invalidateRow')
            ->with('posts', 1);

        $post = Post::find(1);
        $post->title = 'Updated Title';
        $post->save();
    }
}
