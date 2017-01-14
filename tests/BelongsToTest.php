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
        $this->populator->add(User::class, ['id' => 5]);

        $phone = $this->populator->make(Phone::class);

        $this->assertSame(5, $phone->user_id);
    }

    public function testExecuteWithOptionalBelongsTo()
    {
        $this->populator
            ->add(User::class, 100)
            ->add(Post::class, 100);

        $posts = $this->populator->execute()[Post::class];

        $this->assertTrue($posts->contains('user_id', null));
        $this->assertFalse($posts->where('user_id', '!=', null)->isEmpty());
    }

    public function testMakeAndCreateCreateOwnerifForeignKeyIsNotNullable()
    {
        $phone = $this->populator->make(Phone::class);

        $this->assertNotNull($phone->user_id);
    }

    public function testMakeAndCreateCreateOwnersRecursivelyIfForeignKeysAreNullable()
    {
        $post = $this->populator->make(Post::class);

        $this->assertNotNull($post->user->company_id);
    }

    public function testMakeAndCreateCreateDontCreateOwner_ifForeignKeyIsPassedAsCustomAttribute()
    {
        $post = $this->populator->make(Post::class, ['user_id' => null]);

        $this->assertFalse(User::exists());
    }

    public function testMakeAndCreateCreateDontCreateOwner_ifForeignKeyIsInFactoryDefinition()
    {
        $this->app[Eloquent\Factory::class]->define(Post::class, function () {
            return ['user_id' => null];
        });

        $this->populator->make(Post::class);

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
        $this->populator
            ->add(Post::class)   // Tests morphMany().
            ->add(Video::class); // Tests morphOne().

        $comments = $this->populator->create(Comment::class, 50);

        $this->assertTrue($comments[0]->commentable instanceof Post || $comments[0]->commentable instanceof Video);

        // Tests that the comments haven't all been assigned the same morph type,
        // and that the morph map's custom name was used.
        $this->assertTrue($comments->contains('commentable_type', Post::class));
        $this->assertFalse($comments->where('commentable_type', 'videos')->isEmpty());
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
}
