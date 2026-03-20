<?php

declare (strict_types=1);
namespace Psalm\Plugin;

use Psalm\Type;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Union;
final class Dynamic_Template_Provider
{
    /**
     * @internal
     */
    public function __construct(private readonly string $defining_class)
    {
    }
    /**
     * If {@see DynamicFunctionStorage} requires template params this method can create it.
     */
    public function create_template(string $param_name, ?Union $as = null): T_Template_Param
    {
        return new T_Template_Param($param_name, $as ?? Type::get_mixed(), $this->defining_class);
    }
}