<?php

declare(strict_types=1);

namespace Pindle\Http\Concerns;

use function abort;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Pindle\Policies\AnnotationPolicy;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Turning a request into "which model, and may you" -- the two questions every
 * endpoint has to answer before it does anything.
 *
 * The morph is resolved through Laravel's own morph map rather than by treating
 * the request's string as a class name. A request that could name any class
 * would be a request that could instantiate any class, and `enforceMorphMap`
 * users would find their aliases quietly bypassed.
 */
trait ResolvesAnnotatable
{
    /**
     * The model a request names, or a 404 when it names nothing real.
     *
     * 404 and not 422: whether a given model exists is itself information, and an
     * endpoint that distinguishes "no such invoice" from "not your invoice" tells
     * an unauthorised caller which ids are real.
     */
    protected function annotatable(string $morph, string $id): Model
    {
        $class = Relation::getMorphedModel($morph) ?? $morph;

        if (! is_string($class) || ! is_a($class, Model::class, true)) {
            $this->deny();
        }

        $model = $class::query()->find($id);

        if (! $model instanceof Model) {
            $this->deny();
        }

        return $model;
    }

    /**
     * Put one of Pindle's questions to the policy, and stop if the answer is no.
     */
    protected function authorizeDocument(string $ability, Model $annotatable): void
    {
        $policy = app(AnnotationPolicy::class);

        if (! $policy->{$ability}(request()->user(), $annotatable)) {
            abort(403);
        }
    }

    /**
     * @throws HttpException
     */
    private function deny(): never
    {
        abort(404);
    }
}
