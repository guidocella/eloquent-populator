<?php

namespace EloquentPopulator;

use EloquentPopulator\Models\Club;
use EloquentPopulator\Models\Comment;
use EloquentPopulator\Models\Company;
use EloquentPopulator\Models\Country;
use EloquentPopulator\Models\Phone;
use EloquentPopulator\Models\Post;
use EloquentPopulator\Models\User;
use EloquentPopulator\Models\Video;
use Illuminate\Database\Eloquent;

class BelongsToTest extends PopulatorTestCase
{
    public function testExecuteAssociatesBelongsTo()
    {
        $phone = $this->populator->add(User::class, ['id' => 5])->make(Phone::class);

        $this->assertSame(5, $phone->user_id);
    }

    public function testExecuteAlwaysPopulateNullableForeignKeys()
    {
        $posts = $this->populator->add(User::class, 100)->create(Post::class, 100);

        $this->assertTrue($posts->every('user_id', '!=', null));
    }

    public function testSeedMakesNullableForeignKeysOptional()
    {
        $this->populator->add(User::class, 100)->add(Post::class, 100)->seed();

        $this->assertTrue(Post::whereNull('user_id')->exists());
        $this->assertTrue(Post::whereNotNull('user_id')->exists());
    }

    public function testRecursiveOwnerCreationWithNullableForeignKeys()
    {
        $post = $this->populator->make(Post::class);

        $this->assertNotNull($post->user->company_id);
    }

    public function testOwnerIsNotCreated_ifForeignKeyIsPassedAsCustomAttribute()
    {
        $post = $this->populator->make(Post::class, ['user_id' => null]);

        $this->assertFalse(User::exists());
    }

    public function testOwnerIsNotCreated_ifForeignKeyIsInFactoryDefinition()
    {
        $this->app[Eloquent\Factory::class]->define(Post::class, function () {
            return ['user_id' => null];
        });

        $this->populator->make(Post::class);

        $this->assertFalse(User::exists());
    }

    public function testOwnerIsNotCreated_ifForeignKeyIsPassedAsCustomAttributeToTheMakeOfModelPopulator()
    {
        $post = $this->populator->add(Post::class)->make(['user_id' => null]);

        $this->assertFalse(User::exists());
    }

    public function testSelfReferentialBelongsTo()
    {
        $clubs = $this->populator->create(Club::class, 2);

        $this->assertNull($clubs[0]->parent_id);

        $this->assertSame(1, $clubs[1]->parent_id);
    }

    public function testAssociatingMorphTo()
    {
        $comments = $this->populator->add(Post::class)// Tests morphMany().
                                    ->add(Video::class)// Tests morphOne().
                                    ->create(Comment::class, 50);

        $this->assertSame(1, $comments[0]->commentable_id);

        // Tests that the comments haven't all been assigned the same morph type,
        // and that the morph map's custom name was used.
        $this->assertTrue($comments->contains('commentable_type', Post::class));
        $this->assertTrue($comments->contains('commentable_type', 'videos'));

        $this->assertFalse($comments->contains('commentable_id', null));
        $this->assertFalse($comments->contains('commentable_type', null));
    }

    public function testSeedAssociatesAutoIncrementPrimaryKeys()
    {
        $this->populator
            ->add(User::class, ['id' => 5])
            ->add(Phone::class)
            ->seed();

        $this->assertEquals(5, Phone::value('user_id'));
    }

    public function testSeedAssociatesNonAutoIncrementPrimaryKeys()
    {
        $this->populator
            ->add(Country::class)
            ->add(Company::class, 100)
            ->seed();

        $this->assertTrue(Company::whereNull('country_code')->exists());
        $this->assertTrue(Company::whereNotNull('country_code')->exists());
    }

    public function testSeedMakesNullableMorphsOptional()
    {
        $this->populator->add(Post::class)
                        ->add(Video::class)
                        ->add(Comment::class, 50)
                        ->seed();

        $this->assertTrue(Comment::whereNull('commentable_id')->exists());
        $this->assertTrue(Comment::whereNotNull('commentable_id')->exists());

        $this->assertFalse(Comment::whereNull('commentable_id')->whereNotNull('commentable_type')->exists());
        $this->assertFalse(Comment::whereNull('commentable_type')->whereNotNull('commentable_id')->exists());
    }
}
