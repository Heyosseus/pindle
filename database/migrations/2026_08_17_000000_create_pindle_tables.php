<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pindle_annotations', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Written out rather than ->morphs(), which would make the id an
            // unsigned big integer. Pindle attaches to whatever the application
            // already keys its models by -- integer, UUID or ULID -- so the column
            // is a string and the value is cast to one on the way in.
            $table->string('annotatable_type');
            $table->string('annotatable_id');

            // Which PDF on that model. A model may carry several -- the invoice and
            // its delivery note -- and each keeps its own annotations.
            $table->string('document_key')->default('default');

            // The sha256 of the bytes the annotation was drawn on. When the file
            // behind the model is replaced, this stops matching, and the annotation
            // is served flagged rather than silently pointing at the wrong sentence.
            $table->string('document_hash', 64);

            $table->unsignedInteger('page');
            $table->string('type');

            // PDF user-space rectangles, bottom-left origin, in points. Nothing
            // scale-dependent is stored: see the anchoring note on the Annotation model.
            $table->json('rects');

            $table->string('color', 9)->nullable();

            // The text under the anchor at the time of drawing. Not used for
            // rendering -- it is the only thing that could ever re-find this
            // highlight in a document that has since been re-issued.
            $table->text('text_snippet')->nullable();

            $table->string('author_type');
            $table->string('author_id');

            $table->timestamp('resolved_at')->nullable();
            $table->string('resolved_by_id')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The list query: every annotation on one document of one model.
            $table->index(['annotatable_type', 'annotatable_id', 'document_key'], 'pindle_annotations_document_index');

            // Orphan detection compares against this, and pruning a re-issued
            // document's stale anchors reads by it alone.
            $table->index('document_hash');

            // "What is still open on this document" is the question a reviewer
            // actually asks, and it is asked on every page load.
            $table->index('resolved_at');
        });

        Schema::create('pindle_comments', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('annotation_id')
                ->constrained('pindle_annotations')
                ->cascadeOnDelete();

            // Threading is one level deep, enforced above this in Comment::parent().
            // A reply to a reply attaches to the same parent rather than nesting.
            $table->foreignUlid('parent_id')
                ->nullable()
                ->constrained('pindle_comments')
                ->cascadeOnDelete();

            $table->string('author_type');
            $table->string('author_id');

            $table->text('body');

            $table->timestamps();
            $table->softDeletes();

            // A thread is read whole, oldest first, every time it is opened.
            $table->index(['annotation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pindle_comments');
        Schema::dropIfExists('pindle_annotations');
    }
};
