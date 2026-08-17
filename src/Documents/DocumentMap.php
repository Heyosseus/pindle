<?php

declare(strict_types=1);

namespace Pindle\Documents;

use Illuminate\Database\Eloquent\Model;
use Pindle\Concerns\HasAnnotations;
use ReflectionProperty;

/**
 * The `$pindleDocuments` map a model declares, read without asking the model for it.
 *
 * Read by reflection on purpose. The map is a *declaration* -- a piece of
 * configuration that happens to live on the class -- and reaching it through a
 * method would mean the trait had to expose one more public name on every model
 * that uses it. Models are the application's, not Pindle's; the smaller the
 * footprint the trait leaves on them, the better.
 *
 * @internal
 */
final class DocumentMap
{
    /** What a model that declares nothing is assumed to hold. */
    public const string DEFAULT_ATTRIBUTE = 'pdf_path';

    /**
     * The document keys this model carries, mapped to the attributes holding them.
     *
     * @return array<string, string>
     */
    public static function for(Model $model): array
    {
        if (! in_array(HasAnnotations::class, class_uses_recursive($model), true)) {
            return [];
        }

        if (! property_exists($model, 'pindleDocuments')) {
            // A model that added the trait and nothing else. One PDF, on the
            // conventional column, which is the whole of the five-minute install.
            return ['default' => self::DEFAULT_ATTRIBUTE];
        }

        $declared = (new ReflectionProperty($model, 'pindleDocuments'))->getValue($model);

        if (! is_array($declared)) {
            return ['default' => self::DEFAULT_ATTRIBUTE];
        }

        $map = [];

        foreach ($declared as $key => $attribute) {
            // A malformed entry is dropped rather than thrown on: a typo in one
            // key should cost you that document, not every page that renders any.
            if (is_string($key) && $key !== '' && is_string($attribute) && $attribute !== '') {
                $map[$key] = $attribute;
            }
        }

        return $map;
    }
}
