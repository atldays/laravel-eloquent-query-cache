<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test;

use Atldays\QueryCache\Test\Models\{Book, Comment, StampedePost, User};
use Atldays\QueryCache\Test\Support\RecordingLockStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, MorphTo};
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class CacheStampedeRelationTest extends TestCase
{
    public function test_model_default_and_explicit_runtime_override_on_direct_relation(): void
    {
        $postId = $this->seedRelations();
        $store = new RecordingLockStore;
        $cache = new Repository($store);
        $comment = Comment::query()->dontCache()->where('commentable_id', $postId)->where('commentable_type', StampedePost::class)->firstOrFail();

        $this->assertSame(23, StampedePost::query()->getQuery()->getCacheLockOptions()->seconds);
        $this->assertSame(23, $comment->commentable()->getBaseQuery()->getCacheLockOptions()->seconds);
        $this->assertSame('Visible', $comment->commentable()->cacheDriver($cache)->firstOrFail()->name);
        $this->assertSame([23], array_column($store->locksRequested, 1));
        $this->assertSame('Visible', $comment->commentable()->cacheDriver($cache)->cachePrefix('explicit')->cacheLock(11, 0)->firstOrFail()->name);
        $this->assertSame([23, 11], array_column($store->locksRequested, 1));
        $this->assertSame('Visible', $comment->commentable()->cacheDriver($cache)->cachePrefix('off')->withoutCacheLock()->firstOrFail()->name);
        $this->assertSame([23, 11], array_column($store->locksRequested, 1));
    }

    public static function typeOverrides(): iterable
    {
        yield 'withoutCacheLock' => [false];
        yield 'dontCache' => [true];
    }

    #[DataProvider('typeOverrides')]
    public function test_eager_types_inherit_defaults_before_constraints_and_nested_queries_have_their_own_lock(bool $bypassCache): void
    {
        $this->seedRelations();
        $store = new RecordingLockStore;
        $cache = new Repository($store);
        $seen = [];
        $relations = ['commentable' => function (MorphTo $relation) use ($cache, $bypassCache, &$seen): void {
            $relation->cacheFor(60)->cacheDriver($cache)->cacheLock(13, 0)->morphWith([
                StampedePost::class => ['author' => fn (BelongsTo $author) => $author->cacheFor(60)->cacheDriver($cache)->cacheLock(31, 0)],
            ])->constrain([
                StampedePost::class => function (Builder $query) use (&$seen): void {
                    $seen[] = $query->getQuery()->getCacheLockOptions()->seconds;
                    $query->where('name', 'Visible')->cacheLock(19, 0);
                },
                Book::class => function (Builder $query) use ($bypassCache, &$seen): void {
                    $seen[] = $query->getQuery()->getCacheLockOptions()->seconds;
                    $query->where('name', 'Book');
                    $bypassCache ? $query->dontCache() : $query->withoutCacheLock();
                },
            ]);
        }];

        DB::enableQueryLog();
        DB::flushQueryLog();
        $comments = Comment::query()->dontCache()->orderBy('id')->with($relations)->get();
        $this->assertSame([13, 13], $seen);
        $this->assertSame('Visible', $comments[0]->commentable->name);
        $this->assertSame('Author', $comments[0]->commentable->author->name);
        $this->assertSame('Book', $comments[1]->commentable->name);
        $this->assertNull($comments[2]->commentable);
        $this->assertSame([19, 31], array_column($store->locksRequested, 1));
        $this->assertCount(2, array_unique(array_column($store->locksRequested, 0)));
        $this->assertCount(4, DB::getQueryLog());

        DB::flushQueryLog();
        $comments->load($relations);
        $this->assertSame([13, 13, 13, 13], $seen);
        $this->assertSame([19, 31], array_column($store->locksRequested, 1));
        $this->assertCount($bypassCache ? 1 : 0, DB::getQueryLog());
    }

    public function test_relation_explicit_opt_out_overrides_target_model_default_during_eager_loading(): void
    {
        $this->seedRelations();
        $store = new RecordingLockStore;
        $cache = new Repository($store);
        $options = [];
        $comments = Comment::query()->dontCache()->orderBy('id')->get();
        $relations = ['commentable' => function (MorphTo $relation) use ($cache, &$options): void {
            $relation->dontCache()->cacheLock(11, 0)->withoutCacheLock()->constrain([
                StampedePost::class => function (Builder $query) use ($cache, &$options): void {
                    $options[] = $query->getQuery()->getCacheLockOptions();
                    $query->where('name', 'Visible')->cacheDriver($cache);
                },
            ]);
        }];

        DB::enableQueryLog();
        DB::flushQueryLog();
        $comments->load($relations);
        $this->assertCount(2, DB::getQueryLog());
        $this->assertNull($comments[2]->commentable);
        $comments->load($relations);
        $this->assertSame([null, null], $options);
        $this->assertSame([], $store->locksRequested);
        $this->assertCount(2, DB::getQueryLog());
    }

    private function seedRelations(): int
    {
        $author = User::query()->create(['name' => 'Author', 'email' => 'author@example.test', 'password' => 'test']);
        $postId = DB::table('posts')->insertGetId(['name' => 'Visible', 'user_id' => $author->id]);
        $hidden = StampedePost::query()->create(['name' => 'Hidden']);
        $book = Book::query()->create(['name' => 'Book']);
        foreach ([[$postId, StampedePost::class], [$book->id, Book::class], [$hidden->id, StampedePost::class]] as [$id, $type]) {
            Comment::query()->create(['body' => 'body', 'commentable_id' => $id, 'commentable_type' => $type]);
        }

        return $postId;
    }
}
