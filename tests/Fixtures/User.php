<?php

declare(strict_types=1);

namespace Pindle\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @property int $id
 * @property string $name
 * @property int|null $tenant_id
 */
final class User extends Authenticatable
{
    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = ['name', 'tenant_id'];
}
