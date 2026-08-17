<?php

declare(strict_types=1);

namespace Pindle\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Pindle\Casts\RectsCast;
use Pindle\Documents\PindleDocument;
use Pindle\Enums\AnnotationType;
use Pindle\Events\AnnotationCreated;
use Pindle\Events\AnnotationDeleted;
use Pindle\Events\AnnotationResolved;
use Pindle\Events\AnnotationUpdated;
use Pindle\Geometry\Rects;
use Pindle\Pindle;
use Pindle\Support\Key;

/**
 * A mark on a page of a document belonging to a model.
 *
 * Anchoring lives in `page` and `rects`, and `rects` is in PDF user space --
 * bottom-left origin, points -- so that nothing here depends on the zoom, the
 * screen or the rotation it was drawn at. See {@see \Pindle\Geometry\Rect} for
 * why that matters more than it looks like it should.
 *
 * @property string $id
 * @property string $annotatable_type
 * @property string $annotatable_id
 * @property string $document_key
 * @property string $document_hash
 * @property int $page
 * @property AnnotationType $type
 * @property Rects $rects
 * @property string|null $color
 * @property string|null $text_snippet
 * @property string $author_type
 * @property string $author_id
 * @property CarbonInterface|null $resolved_at
 * @property string|null $resolved_by_id
 * @property array<string, mixed>|null $meta
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class Annotation extends Model
{
    use HasUlids;
    use SoftDeletes;

    protected $table = 'pindle_annotations';

    /** @var list<string> */
    protected $fillable = [
        'annotatable_type',
        'annotatable_id',
        'document_key',
        'document_hash',
        'page',
        'type',
        'rects',
        'color',
        'text_snippet',
        'author_type',
        'author_id',
        'resolved_at',
        'resolved_by_id',
        'meta',
    ];

    /**
     * The model this annotation is written on.
     *
     * @return MorphTo<Model, $this>
     */
    public function annotatable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Whoever drew it.
     *
     * @return MorphTo<Model, $this>
     */
    public function author(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The thread hanging off this annotation, oldest first.
     *
     * Ordered here rather than at every call site: a thread read in any other
     * order is a thread nobody can follow.
     *
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Pindle::commentModel(), 'annotation_id')->oldest();
    }

    /**
     * Whether the document has been replaced since this was drawn.
     *
     * An orphan is not deleted and not hidden. It is served with the flag set so
     * the viewer can show it in the margin with a warning, because the alternative
     * -- drawing it at coordinates that now point at a different sentence -- is a
     * package quietly putting words in a reviewer's mouth.
     */
    public function isOrphanedFrom(PindleDocument $document): bool
    {
        return ! $document->matches($this->document_hash);
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /**
     * Annotations on one document of one model.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForDocument(Builder $query, Model $annotatable, string $key = 'default'): Builder
    {
        return $query
            ->where('annotatable_type', $annotatable->getMorphClass())
            ->where('annotatable_id', Key::of($annotatable))
            ->where('document_key', $key);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /**
     * The five events fire from the model rather than from the controller.
     *
     * A listener that reacts to "somebody objected to clause 4" should react
     * whether the objection arrived over HTTP, from an importer, or from a job
     * backfilling a migration. Dispatching from the controller would mean it only
     * ever heard about the first of those.
     */
    protected static function booted(): void
    {
        static::created(static function (self $annotation): void {
            AnnotationCreated::dispatch($annotation, Auth::user());
        });

        static::updated(static function (self $annotation): void {
            // Resolution is its own event, and only on the transition into it --
            // a listener that emails the author should not email them again
            // because somebody nudged the highlight afterwards.
            if ($annotation->wasChanged('resolved_at') && $annotation->resolved_at !== null) {
                AnnotationResolved::dispatch($annotation, Auth::user());

                return;
            }

            AnnotationUpdated::dispatch($annotation, Auth::user());
        });

        static::deleted(static function (self $annotation): void {
            // Pruning force-deletes rows that were already announced as deleted
            // months ago. Announcing them again would be a second obituary.
            if (! $annotation->isForceDeleting()) {
                AnnotationDeleted::dispatch($annotation, Auth::user());
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'page' => 'integer',
            'type' => AnnotationType::class,
            'rects' => RectsCast::class,
            'resolved_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
