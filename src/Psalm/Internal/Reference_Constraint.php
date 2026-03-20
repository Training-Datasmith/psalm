<?php

declare (strict_types=1);
namespace Psalm\Internal;

use Psalm\Type\Atomic\T_Float;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Union;
/**
 * @internal
 */
final class Reference_Constraint
{
    public ?Union $type = null;
    public function __construct(?Union $type = null)
    {
        if ($type) {
            $type = $type->get_builder();
            if ($type->get_literal_strings()) {
                $type->add_type(new T_String());
            }
            if ($type->get_literal_ints()) {
                $type->add_type(new T_Int());
            }
            if ($type->get_literal_floats()) {
                $type->add_type(new T_Float());
            }
            $this->type = $type->freeze();
        }
    }
}