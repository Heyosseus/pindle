<?php

declare(strict_types=1);

namespace Pindle\Policies;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may read and write annotations, answered by asking about the document.
 *
 * Every question here is put to the *owning model* -- the invoice, the contract
 * -- and never to the annotation. That is the whole design. An application has
 * already decided, in its own policies, who may see an invoice; a second set of
 * rules about who may see the highlights on it would be one more thing to keep
 * in step, and the day the two disagree is the day somebody reads a document
 * they were not meant to.
 *
 * It also means multi-tenancy needs nothing from Pindle. A tenant column here
 * would be a second, weaker copy of a constraint the application already
 * enforces on the invoice; going through the invoice means there is exactly one.
 *
 * The five abilities map onto the application's own through configuration, so
 * "may annotate" can be separated from "may edit the document" by naming a
 * different ability rather than by replacing this class.
 */
final readonly class AnnotationPolicy
{
    public function __construct(
        private Gate $gate,
        private Repository $config,
    ) {}

    public function viewAny(?Authenticatable $user, Model $annotatable): bool
    {
        return $this->allows($user, 'viewAny', $annotatable);
    }

    public function create(?Authenticatable $user, Model $annotatable): bool
    {
        return $this->allows($user, 'create', $annotatable);
    }

    public function update(?Authenticatable $user, Model $annotatable): bool
    {
        return $this->allows($user, 'update', $annotatable);
    }

    public function delete(?Authenticatable $user, Model $annotatable): bool
    {
        return $this->allows($user, 'delete', $annotatable);
    }

    public function resolve(?Authenticatable $user, Model $annotatable): bool
    {
        return $this->allows($user, 'resolve', $annotatable);
    }

    /**
     * Put one of Pindle's questions to the application's gate, in its own words.
     *
     * An unmapped ability denies rather than defaults. Emptying the map should
     * close the door, not open it: a configuration file that can silently grant
     * access by omission is a configuration file nobody can audit.
     */
    private function allows(?Authenticatable $user, string $ability, Model $annotatable): bool
    {
        $mapped = $this->config->get('pindle.policy.abilities.'.$ability);

        if (! is_string($mapped) || $mapped === '') {
            return false;
        }

        return $this->gate->forUser($user)->allows($mapped, $annotatable);
    }
}
