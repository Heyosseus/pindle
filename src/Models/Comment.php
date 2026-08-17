<?php

declare(strict_types=1);

namespace Pindle\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Pindle\Events\CommentPosted;
use Pindle\Pindle;

/**
 * Something somebody said about an annotation.
 *
 * The body is stored exactly as it was typed and escaped when rendered. No HTML,
 * no markdown: a comment thread that renders markup is an XSS hole with a
 * feature request attached.
 *
 * @property string $id
 * @property string $annotation_id
 * @property string|null $parent_id
 * @property string $author_type
 * @property string $author_id
 * @property string $body
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class Comment extends Model
{
    use HasUlids;
    use SoftDeletes;

    protected $table = 'pindle_comments';

    /** @var list<string> */
    protected $fillable = [
        'annotation_id',
        'parent_id',
        'author_type',
        'author_id',
        'body',
    ];

    /**
     * @return BelongsTo<Annotation, $this>
     */
    public function annotation(): BelongsTo
    {
        return $this->belongsTo(Pindle::annotationModel(), 'annotation_id');
    }

    /**
     * @return BelongsTo<Comment, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Pindle::commentModel(), 'parent_id');
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(Pindle::commentModel(), 'parent_id')->oldest();
    }

    public function isReply(): bool
    {
        return $this->parent_id !== null;
    }

    /**
     * The comment a reply to this one should actually hang off.
     *
     * Threading is one level deep. Replying to a reply attaches to the same
     * parent rather than nesting a third time -- deeper threads have no
     * demonstrated demand, and in a strip of margin beside a page they are
     * unreadable by the fourth level.
     */
    public function threadRoot(): self
    {
        return $this->parent_id === null ? $this : $this->parent()->firstOrFail();
    }

    /**
     * Dispatched from the model for the same reason the annotation's events are:
     * so an importer and a controller are heard equally.
     */
    protected static function booted(): void
    {
        static::created(static function (self $comment): void {
            CommentPosted::dispatch($comment, $comment->annotation()->firstOrFail(), Auth::user());
        });
    }
}
