<?php

declare (strict_types=1);
namespace Psalm\Internal\Type_Visitor;

use Override;
use Psalm\Type\Atomic\T_Class_Constant;
use Psalm\Type\Atomic\T_Literal_Class_String;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Type_Node;
use Psalm\Type\Type_Visitor;
use function strtolower;
/**
 * @internal
 */
final class Contains_Class_Like_Visitor extends Type_Visitor
{
    private bool $contains_classlike = false;
    /**
     * @psalm-external-mutation-free
     * @param lowercase-string $fq_classlike_name
     */
    public function __construct(private readonly string $fq_classlike_name)
    {
    }
    /**
     * @psalm-external-mutation-free
     */
    #[Override]
    protected function enter_node(Type_Node $type): ?int
    {
        if ($type instanceof T_Named_Object) {
            if (strtolower($type->value) === $this->fq_classlike_name) {
                $this->contains_classlike = true;
                return self::STOP_TRAVERSAL;
            }
        }
        if ($type instanceof T_Class_Constant) {
            if (strtolower($type->fq_classlike_name) === $this->fq_classlike_name) {
                $this->contains_classlike = true;
                return self::STOP_TRAVERSAL;
            }
        }
        if ($type instanceof T_Literal_Class_String) {
            if (strtolower($type->value) === $this->fq_classlike_name) {
                $this->contains_classlike = true;
                return self::STOP_TRAVERSAL;
            }
        }
        return null;
    }
    /**
     * @psalm-mutation-free
     */
    public function matches(): bool
    {
        return $this->contains_classlike;
    }
}