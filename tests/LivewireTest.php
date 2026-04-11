<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test;

use Atldays\QueryCache\Test\Models\Post;
use Livewire\Component;
use Livewire\Livewire;
use Livewire\Testing\TestableLivewire;
use PHPUnit\Framework\Attributes\DataProvider;

class LivewireTest extends TestCase
{
    #[DataProvider('strictModeContextProvider')]
    public function test_livewire_component_poll_doesnt_break_when_callback_is_already_set()
    {
        // See: https://github.com/renoki-co/laravel-eloquent-query-cache/issues/163
        Livewire::component('post', PostComponent::class);

        $posts = factory(Post::class, 30)->create();

        /** @var TestableLivewire $component */
        Livewire::test(PostComponent::class, ['post' => $posts->first()])
            ->assertOk()
            ->assertSee($posts[0]->name)
            ->call('$refresh')
            ->assertOk()
            ->assertSee($posts[0]->name);
    }
}

class PostComponent extends Component
{
    public Post $post;

    public function getName(): string
    {
        return 'post';
    }
}
