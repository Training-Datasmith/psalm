<?php

declare (strict_types=1);
namespace Psalm\Internal\Type_Visitor;

use Override;
use Psalm\Type;
use Psalm\Type\Atomic\T_Conditional;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Atomic\T_Template_Param_Class;
use Psalm\Type\Type_Node;
use Psalm\Type\Type_Visitor;
use Psalm\Type\Union;
/**
 * @internal
 */
final class Template_Type_Collector extends Type_Visitor
{
    /**
     * @var list<TTemplateParam>
     */
    private array $template_types = [];
    #[Override]
    protected function enter_node(Type_Node $type): ?int
    {
        if ($type instanceof T_Template_Param) {
            $this->template_types[] = $type;
        } elseif ($type instanceof T_Template_Param_Class) {
            $extends = $type->as_type;
            $this->template_types[] = new T_Template_Param($type->param_name, $extends ? new Union([$extends]) : Type::get_mixed(), $type->defining_class);
        } elseif ($type instanceof T_Conditional) {
            $this->template_types[] = new T_Template_Param($type->param_name, Type::get_mixed(), $type->defining_class);
        }
        return null;
    }
    /**
     * @return list<TTemplateParam>
     */
    public function get_template_types(): array
    {
        return $this->template_types;
    }
}