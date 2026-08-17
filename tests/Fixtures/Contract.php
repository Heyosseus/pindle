<?php

declare(strict_types=1);

namespace Pindle\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Pindle\Concerns\HasAnnotations;

/**
 * A model that declares no document map and is keyed by ULID rather than by an
 * integer -- both of which Pindle has to cope with without being told.
 *
 * @property string $id
 * @property string|null $pdf_path
 */
final class Contract extends Model
{
    use HasAnnotations;
    use HasUlids;

    protected $table = 'contracts';

    /** @var list<string> */
    protected $fillable = ['pdf_path'];
}
