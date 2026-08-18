<?php

declare(strict_types=1);

namespace Pindle\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Pindle\Models\Annotation;
use Pindle\Models\Comment;
use Pindle\Pindle;
use Pindle\Support\Key;

/**
 * Comments for an application's own tests.
 *
 * ```php
 * Comment::factory()->on($annotation)->by($reviewer)->create();
 * Comment::factory()->on($annotation)->replyTo($first)->create();
 * ```
 *
 * @extends Factory<Comment>
 */
final class CommentFactory extends Factory
{
    /** @var class-string<Comment> */
    protected $model = Comment::class;

    public function definition(): array
    {
        return [
            'annotation_id' => Pindle::annotationModel()::factory(),
            'parent_id' => null,
            'author_type' => 'pindle-unattributed',
            'author_id' => '1',
            'body' => $this->faker->sentence(),
        ];
    }

    public function on(Annotation $annotation): self
    {
        return $this->state(fn (): array => ['annotation_id' => $annotation->getKey()]);
    }

    public function by(Model $author): self
    {
        return $this->state(fn (): array => [
            'author_type' => $author->getMorphClass(),
            'author_id' => Key::of($author),
        ]);
    }

    /**
     * An answer to another comment.
     *
     * Threading stops at one level, so a reply to a reply is attached to that
     * reply's parent -- the same rule the API applies, applied here so a test
     * fixture cannot build a shape the application would refuse to.
     */
    public function replyTo(Comment $parent): self
    {
        $root = $parent->threadRoot();

        return $this->state(fn (): array => [
            'annotation_id' => $root->annotation_id,
            'parent_id' => $root->getKey(),
        ]);
    }

    public function saying(string $body): self
    {
        return $this->state(fn (): array => ['body' => $body]);
    }

    /**
     * @return class-string<Comment>
     */
    public function modelName(): string
    {
        return Pindle::commentModel();
    }
}
