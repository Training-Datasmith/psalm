<?php

declare (strict_types=1);
namespace Psalm\Internal\Type_Visitor;

use Override;
use Psalm\Type\Atomic\T_Class_Constant;
use Psalm\Type\Atomic\T_Class_String;
use Psalm\Type\Atomic\T_Literal_Class_String;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Mutable_Type_Visitor;
use Psalm\Type\Type_Node;
use function strtolower;
/**
 * @internal
 */
final class Classlike_Replacer extends Mutable_Type_Visitor
{
    private readonly string $old;
    public function __construct(string $old, private readonly string $new)
    {
        $this->old = strtolower($old);
    }
    #[Override]
    protected function enter_node(Type_Node &$type): ?int
    {
        if ($type instanceof T_Class_Constant) {
            if (strtolower($type->fq_classlike_name) === $this->old) {
                $type = new T_Class_Constant($this->new, $type->const_name, $type->from_docblock);
            }
        } elseif ($type instanceof T_Class_String) {
            if ($type->as !== 'object' && strtolower($type->as) === $this->old) {
                $type = new T_Class_String($this->new, $type->as_type, $type->is_loaded, $type->is_interface, $type->is_enum, $type->from_docblock);
            }
        } elseif ($type instanceof T_Named_Object || $type instanceof T_Literal_Class_String) {
            if (strtolower($type->value) === $this->old) {
                $type = $type->set_value($this->new);
            }
        }
        return null;
    }
}