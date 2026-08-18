<?php

declare(strict_types=1);

namespace Pindle\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Pindle\Contracts\DocumentResolver;
use Pindle\Enums\AnnotationType;
use Pindle\Models\Annotation;
use Pindle\Pindle;
use Pindle\Support\Key;

/**
 * Annotations for an application's own tests.
 *
 * This exists because the alternative is every application that installs Pindle
 * writing the same forty lines: work out the morph columns, hash the PDF, decide
 * what a plausible rectangle looks like. The hash is the part worth shipping --
 * get it wrong and every annotation in your test suite is silently an orphan,
 * and the approval flow you were trying to test never sees an open mark at all.
 *
 * ```php
 * Annotation::factory()->on($invoice)->by($reviewer)->create();
 * Annotation::factory()->on($invoice)->orphaned()->count(3)->create();
 * ```
 *
 * @extends Factory<Annotation>
 */
final class AnnotationFactory extends Factory
{
    /** @var class-string<Annotation> */
    protected $model = Annotation::class;

    public function definition(): array
    {
        return [
            // Deliberately not a real model. `on()` is how an annotation gets
            // attached to something, and leaving these obviously synthetic means
            // a test that forgot to call it fails loudly rather than attaching
            // marks to a record that happens to share an id.
            'annotatable_type' => 'pindle-unattached',
            'annotatable_id' => (string) $this->faker->numberBetween(1, 1000),
            'document_key' => 'default',
            'document_hash' => hash('sha256', $this->faker->sentence()),
            'page' => 1,
            'type' => AnnotationType::Highlight,
            // One line of a paragraph, in points on an A4 page, bottom-left
            // origin -- the same coordinate space the viewer writes.
            'rects' => [['x1' => 72.0, 'y1' => 640.2, 'x2' => 310.5, 'y2' => 655.8]],
            'color' => '#fde047',
            'text_snippet' => $this->faker->sentence(),
            'author_type' => 'pindle-unattributed',
            'author_id' => '1',
        ];
    }

    /**
     * Anchor it to a model's document, on the document as it stands right now.
     *
     * The hash is read from the file, so the annotation starts life matching --
     * which is what makes {@see self::orphaned()} mean something.
     */
    public function on(Model $annotatable, string $documentKey = 'default'): self
    {
        return $this->state(fn (): array => [
            'annotatable_type' => $annotatable->getMorphClass(),
            'annotatable_id' => Key::of($annotatable),
            'document_key' => $documentKey,
            'document_hash' => $this->hashOf($annotatable, $documentKey),
        ]);
    }

    /** Attribute it to whoever drew it. */
    public function by(Model $author): self
    {
        return $this->state(fn (): array => [
            'author_type' => $author->getMorphClass(),
            'author_id' => Key::of($author),
        ]);
    }

    /**
     * Anchored to a version of the document that is no longer the one on disk.
     *
     * The state every application needs to test and none of them can produce
     * conveniently: re-issue the PDF, and this is what the reviewer sees.
     */
    public function orphaned(): self
    {
        return $this->state(fn (): array => [
            'document_hash' => hash('sha256', 'a version this document no longer is'),
        ]);
    }

    public function resolved(?Model $by = null): self
    {
        return $this->state(fn (): array => [
            'resolved_at' => now(),
            'resolved_by_id' => $by instanceof Model ? Key::of($by) : null,
        ]);
    }

    public function onPage(int $page): self
    {
        return $this->state(fn (): array => ['page' => $page]);
    }

    public function ofType(AnnotationType $type): self
    {
        return $this->state(fn (): array => ['type' => $type]);
    }

    /** A highlight over particular words, which is what re-anchoring searches for. */
    public function highlighting(string $text): self
    {
        return $this->state(fn (): array => [
            'type' => AnnotationType::Highlight,
            'text_snippet' => $text,
        ]);
    }

    /**
     * The document's current hash, or a placeholder when there is no file.
     *
     * A model with no document is an ordinary thing to write a test about, and
     * it should not have to put a PDF on a disk first.
     */
    private function hashOf(Model $annotatable, string $documentKey): string
    {
        $document = app(DocumentResolver::class)->resolve($annotatable, $documentKey);

        return $document !== null && $document->exists()
            ? $document->hash()
            : hash('sha256', $annotatable->getMorphClass().'#'.Key::of($annotatable).'#'.$documentKey);
    }

    /**
     * Respect an application that swapped the model for a subclass of its own.
     *
     * @return class-string<Annotation>
     */
    public function modelName(): string
    {
        return Pindle::annotationModel();
    }
}
