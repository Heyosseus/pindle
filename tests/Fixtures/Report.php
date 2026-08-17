<?php

declare(strict_types=1);

namespace Pindle\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Pindle\Concerns\HasAnnotations;

/**
 * A model whose document map was declared wrong -- a string where an array
 * belongs. Pindle falls back rather than throwing, because one typo on one model
 * should not take down every page in the application that renders any viewer.
 */
class Report extends Model
{
    use HasAnnotations;

    protected $table = 'reports';

    /** @var list<string> */
    protected $fillable = ['pdf_path'];

    /** @var mixed */
    protected $pindleDocuments = 'pdf_path';
}
