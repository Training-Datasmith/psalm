<?php

declare (strict_types=1);
namespace Psalm\Internal\Type;

use Psalm\Codebase;
use Psalm\Type\Atomic\T_Generic_Object;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Union;
use function array_keys;
/**
 * @internal
 */
final class Closed_Inheritance_To_Union
{
    public static function map(Union $input, Codebase $codebase): Union
    {
        $new_types = [];
        $meet_inheritors = false;
        foreach ($input->get_atomic_types() as $atomic_type) {
            if ($atomic_type instanceof T_Named_Object) {
                $storage = $codebase->classlikes->get_storage_for($atomic_type->value);
                if (null === $storage || null === $storage->inheritors) {
                    $new_types[] = $atomic_type;
                    continue;
                }
                $template_result = self::get_template_result($atomic_type, $codebase);
                $replaced_inheritors = Template_Inferred_Type_Replacer::replace($storage->inheritors, $template_result, $codebase);
                foreach ($replaced_inheritors->get_atomic_types() as $replaced_atomic_type) {
                    $new_types[] = $replaced_atomic_type;
                }
                $meet_inheritors = true;
            } else {
                $new_types[] = $atomic_type;
            }
        }
        if (!$meet_inheritors) {
            return $input;
        }
        return $new_types ? $input->set_types($new_types) : $input;
    }
    private static function get_template_result(T_Named_Object $object, Codebase $codebase): Template_Result
    {
        if (!$object instanceof T_Generic_Object) {
            return new Template_Result([], []);
        }
        $storage = $codebase->classlikes->get_storage_for($object->value);
        if (null === $storage || null === $storage->template_types) {
            return new Template_Result([], []);
        }
        $lower_bounds = [];
        $offset = 0;
        foreach ($storage->template_types as $template_name => $templates) {
            foreach (array_keys($templates) as $defining_class) {
                $lower_bounds[$template_name][$defining_class] = $object->type_params[$offset++];
            }
        }
        return new Template_Result($storage->template_types, $lower_bounds);
    }
}