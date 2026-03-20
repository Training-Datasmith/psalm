<?php

declare (strict_types=1);
namespace Psalm\Internal\Type;

use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Non_Empty_Array;
use Psalm\Type\Union;
use function count;
/**
 * @internal
 */
final class Array_Type
{
    public function __construct(public Union $key, public Union $value, public bool $is_list, public ?int $count)
    {
    }
    /**
     * @return (
     *     $type is TKeyedArray ? self : (
     *         $type is TNonEmptyArray ? self : (
     *             $type is TArray ? self : null
     *         )
     *     )
     * )
     */
    public static function infer(Atomic $type): ?self
    {
        if ($type instanceof T_Keyed_Array) {
            $count = null;
            if ($type->is_sealed()) {
                $count = count($type->properties);
            }
            return new self($type->get_generic_key_type(), $type->get_generic_value_type(), $type->is_list, $count);
        }
        if ($type instanceof T_Non_Empty_Array) {
            return new self($type->type_params[0], $type->type_params[1], false, $type->count);
        }
        if ($type instanceof T_Array) {
            $empty = $type->is_empty_array();
            return new self($type->type_params[0], $type->type_params[1], false, $empty ? 0 : null);
        }
        return null;
    }
}