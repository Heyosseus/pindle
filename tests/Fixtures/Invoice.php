<?php

declare(strict_types=1);

namespace Pindle\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Pindle\Concerns\HasAnnotations;

/**
 * A host application's model, carrying two documents.
 *
 * Left open rather than final so that it mirrors the way applications actually
 * declare their models -- and so that `$pindleDocuments` stays protected, which
 * is what the documentation tells people to write.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string|null $pdf_path
 * @property string|null $delivery_pdf_path
 */
class Invoice extends Model
{
    use HasAnnotations;

    protected $table = 'invoices';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'pdf_path', 'delivery_pdf_path'];

    /** @var array<string, string> */
    protected array $pindleDocuments = [
        'default' => 'pdf_path',
        'delivery_note' => 'delivery_pdf_path',
    ];
}
