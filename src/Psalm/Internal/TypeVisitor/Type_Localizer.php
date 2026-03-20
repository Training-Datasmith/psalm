<?php

declare (strict_types=1);
namespace Psalm\Internal\Type_Visitor;

use Override;
use Psalm\Internal\Codebase\Methods;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Atomic\T_Template_Param_Class;
use Psalm\Type\Mutable_Type_Visitor;
use Psalm\Type\Mutable_Union;
use Psalm\Type\Type_Node;
use Psalm\Type\Union;
use function array_values;
use function count;
/**
 * @internal
 */
final class Type_Localizer extends Mutable_Type_Visitor
{
    /**
     * @param array<string, array<string, Union>> $extends
     */
    public function __construct(private array $extends, private readonly string $base_fq_class_name)
    {
    }
    #[Override]
    protected function enter_node(Type_Node &$type): ?int
    {
        if ($type instanceof T_Template_Param_Class) {
            if ($type->defining_class === $this->base_fq_class_name) {
                if (isset($this->extends[$this->base_fq_class_name][$type->param_name])) {
                    $extended_param = $this->extends[$this->base_fq_class_name][$type->param_name];
                    $types = array_values($extended_param->get_atomic_types());
                    if (count($types) === 1 && $types[0] instanceof T_Named_Object) {
                        $type = $type->set_as($type->as, $types[0]);
                    } elseif ($type->as_type !== null) {
                        $type = $type->set_as($type->as, null);
                    }
                }
            }
        }
        if ($type instanceof Union) {
            $union = $type->get_builder();
        } elseif ($type instanceof Mutable_Union) {
            $union = $type;
        } else {
            return null;
        }
        foreach ($union->get_atomic_types() as $key => $atomic_type) {
            if ($atomic_type instanceof T_Template_Param && ($atomic_type->defining_class === $this->base_fq_class_name || isset($this->extends[$atomic_type->defining_class]))) {
                $types_to_add = Methods::get_extended_templated_types($atomic_type, $this->extends);
                if ($types_to_add) {
                    $union->remove_type($key);
                    foreach ($types_to_add as $extra_added_type) {
                        $union->add_type($extra_added_type);
                    }
                }
            }
        }
        if ($type instanceof Union) {
            $type = $union->freeze();
        } else {
            $type = $union;
        }
        return null;
    }
}