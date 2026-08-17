<?php

declare(strict_types=1);

namespace Pindle\Tests\Fixtures;

/**
 * A host application's own policy: an invoice belongs to a tenant, and you see
 * your tenant's invoices. Nothing here mentions annotations, which is the point
 * -- Pindle's questions have to arrive as questions this policy already answers.
 */
final class InvoicePolicy
{
    public function view(User $user, Invoice $invoice): bool
    {
        return $user->tenant_id === $invoice->tenant_id;
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $user->tenant_id === $invoice->tenant_id && $user->name !== 'Auditor';
    }
}
