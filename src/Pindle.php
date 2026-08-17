<?php

declare(strict_types=1);

namespace Pindle;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Pindle\Models\Annotation;
use Pindle\Models\Comment;

/**
 * Pindle's public entry point: which models it uses, and what else to constrain
 * its queries by.
 *
 * Static rather than injected, so it can be reached from a service provider's
 * `boot()` before anything is resolvable, which is where an application does
 * this kind of wiring.
 */
final class Pindle
{
    /**
     * An extra constraint the application wants on every annotation query.
     *
     * @var Closure(Builder<Annotation>): void|null
     */
    private static ?Closure $scope = null;

    /**
     * Constrain every annotation query Pindle makes.
     *
     * Pindle does not need this to be multi-tenant-safe -- an annotation is only
     * ever reachable through a model the viewer can already see, so tenant
     * isolation is the application's existing isolation and not a second copy of
     * it. This hook is for the cases beyond that: an application whose own global
     * scopes are not on the annotatable, a soft archive, a read-only period.
     *
     * ```php
     * Pindle::scopeUsing(fn (Builder $query) => $query->where('created_at', '>', $cutoff));
     * ```
     *
     * Pass null to remove it.
     *
     * @param  (Closure(Builder<Annotation>): void)|null  $callback
     */
    public static function scopeUsing(?Closure $callback): void
    {
        self::$scope = $callback;
    }

    /**
     * Apply whatever the application layered on, and hand the query back.
     *
     * @template TModel of Annotation
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function scope(Builder $query): Builder
    {
        if (self::$scope instanceof Closure) {
            /** @var Builder<Annotation> $query */
            (self::$scope)($query);
        }

        /** @var Builder<TModel> $query */
        return $query;
    }

    /**
     * The annotation model in use, which an application may replace with a subclass.
     *
     * @return class-string<Annotation>
     */
    public static function annotationModel(): string
    {
        $model = config('pindle.models.annotation');

        return is_string($model) && is_a($model, Annotation::class, true)
            ? $model
            : Annotation::class;
    }

    /**
     * @return class-string<Comment>
     */
    public static function commentModel(): string
    {
        $model = config('pindle.models.comment');

        return is_string($model) && is_a($model, Comment::class, true)
            ? $model
            : Comment::class;
    }

    /**
     * A fresh query over annotations, already carrying the application's scope.
     *
     * Every read Pindle makes starts here, so there is one place the hook has to
     * be remembered rather than eight.
     *
     * @return Builder<Annotation>
     */
    public static function query(): Builder
    {
        return self::scope(self::annotationModel()::query());
    }

    /**
     * Forget the registered scope. Used by the test suite, where a static
     * outlives the application that registered it and would otherwise constrain
     * the next test.
     */
    public static function flush(): void
    {
        self::$scope = null;
    }
}
