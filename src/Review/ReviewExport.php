<?php

declare(strict_types=1);

namespace Pindle\Review;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Pindle\Models\Annotation;
use Pindle\Models\Comment;
use Pindle\Pindle;
use Pindle\Support\Key;

/**
 * The review of a document, as something you can send to somebody.
 *
 * The question every review eventually produces is "what did legal actually
 * say?", and the answer should not require opening the viewer. Annotations live
 * in ordinary tables precisely so that this is a query rather than a feature
 * request.
 *
 * This is not flattening. Nothing is written back into the PDF -- that is a
 * non-goal, and for good reason: a flattened comment cannot be reopened,
 * reassigned or withdrawn. This produces a document *about* the document.
 */
final readonly class ReviewExport
{
    public function __construct(
        private Model $annotatable,
        private string $documentKey = 'default',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $summary = ReviewSummary::for($this->annotatable, $this->documentKey);

        return [
            'annotatable' => [
                'type' => $this->annotatable->getMorphClass(),
                'id' => Key::of($this->annotatable),
            ],
            'document_key' => $this->documentKey,
            'summary' => $summary->toArray(),
            'annotations' => $this->annotations()->map(fn (Annotation $annotation): array => [
                'id' => $annotation->id,
                'page' => $annotation->page,
                'type' => $annotation->type->value,
                'rects' => $annotation->rects->toArray(),
                'text_snippet' => $annotation->text_snippet,
                'author' => ['type' => $annotation->author_type, 'id' => $annotation->author_id],
                'resolved_at' => $annotation->resolved_at?->toIso8601String(),
                'created_at' => $annotation->created_at->toIso8601String(),
                'comments' => $annotation->comments->map(fn (Comment $comment): array => [
                    'id' => $comment->id,
                    'parent_id' => $comment->parent_id,
                    'body' => $comment->body,
                    'author' => ['type' => $comment->author_type, 'id' => $comment->author_id],
                    'created_at' => $comment->created_at->toIso8601String(),
                ])->all(),
            ])->all(),
        ];
    }

    /**
     * The same zero-fraction rule as the HTTP API: an anchor at x1 = 72.0 goes
     * out as 72.0, so an export and a response describe a rectangle the same way.
     */
    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * The same thing as a page somebody can read.
     *
     * Ordered by page and then by when it was raised, because that is the order
     * a reader goes through a document in -- not the order the database happened
     * to return.
     */
    public function toMarkdown(): string
    {
        $summary = ReviewSummary::for($this->annotatable, $this->documentKey);

        $lines = [
            sprintf('# Review of %s #%s', class_basename($this->annotatable), Key::of($this->annotatable)),
            '',
            sprintf('Document: `%s`', $this->documentKey),
            sprintf(
                '%d mark(s) — %d open, %d resolved, %d orphaned, %d comment(s).',
                $summary->total,
                $summary->open,
                $summary->resolved,
                $summary->orphaned,
                $summary->comments,
            ),
        ];

        if ($summary->orphaned > 0) {
            $lines[] = '';
            $lines[] = sprintf(
                '> %d mark(s) were made on a version of this document that has since been replaced, '
                .'and may no longer point at the right place.',
                $summary->orphaned,
            );
        }

        $page = null;

        foreach ($this->annotations() as $annotation) {
            if ($annotation->page !== $page) {
                $page = $annotation->page;
                $lines[] = '';
                $lines[] = '## Page '.$page;
            }

            $lines[] = '';
            $lines[] = sprintf(
                '### %s — %s',
                ucfirst($annotation->type->value),
                $annotation->isResolved() ? 'resolved' : 'open',
            );

            if (is_string($annotation->text_snippet) && $annotation->text_snippet !== '') {
                $lines[] = '';
                $lines[] = '> '.$annotation->text_snippet;
            }

            foreach ($annotation->comments as $comment) {
                $lines[] = '';
                $lines[] = sprintf(
                    '%s**%s** (%s): %s',
                    $comment->isReply() ? '  - ' : '- ',
                    $comment->author_id,
                    $comment->created_at->toDateString(),
                    $comment->body,
                );
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return Collection<int, Annotation>
     */
    private function annotations(): Collection
    {
        return Pindle::query()
            ->forDocument($this->annotatable, $this->documentKey)
            ->with('comments')
            ->orderBy('page')
            ->orderBy('created_at')
            ->get();
    }
}
