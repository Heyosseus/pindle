<?php

declare(strict_types=1);

namespace Pindle\Http\Requests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Pindle\Http\Concerns\ResolvesAnnotatable;

/**
 * "What is written on this document?"
 */
final class ListAnnotationsRequest extends FormRequest
{
    use ResolvesAnnotatable;

    public function authorize(): bool
    {
        $this->authorizeDocument('viewAny', $this->target());

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'annotatable_type' => ['required', 'string'],
            'annotatable_id' => ['required', 'string'],
            'document_key' => ['sometimes', 'string', 'max:255'],
        ];
    }

    public function documentKey(): string
    {
        $key = $this->input('document_key');

        return is_string($key) && $key !== '' ? $key : 'default';
    }

    /**
     * The model being asked about.
     *
     * Resolved through the morph map, and 404 rather than 403 when it names
     * nothing -- see the trait for why the two are not distinguished.
     */
    public function target(): Model
    {
        $type = $this->input('annotatable_type');
        $id = $this->input('annotatable_id');

        if (! is_string($type) || ! is_string($id) || $type === '' || $id === '') {
            abort(404);
        }

        return $this->annotatable($type, $id);
    }
}
