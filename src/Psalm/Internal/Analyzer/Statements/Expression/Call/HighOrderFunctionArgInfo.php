<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Call;

use Psalm\Internal\Type\Template_Result;
use Psalm\Storage\Class_Like_Storage;
use Psalm\Storage\Function_Like_Storage;
use Psalm\Type;
use Psalm\Type\Atomic\T_Callable;
use Psalm\Type\Atomic\T_Closure;
use Psalm\Type\Union;
/**
 * @internal
 */
final class High_Order_Function_Arg_Info
{
    public const TYPE_FIRST_CLASS_CALLABLE = 'first-class-callable';
    public const TYPE_CLASS_CALLABLE = 'class-callable';
    public const TYPE_STRING_CALLABLE = 'string-callable';
    public const TYPE_CALLABLE = 'callable';
    /**
     * @psalm-param HighOrderFunctionArgInfo::TYPE_* $type
     */
    public function __construct(private readonly string $type, private readonly Function_Like_Storage $function_storage, private readonly ?Class_Like_Storage $class_storage = null)
    {
    }
    public function get_templates(): Template_Result
    {
        $templates = $this->class_storage ? [...$this->function_storage->template_types ?? [], ...$this->class_storage->template_types ?? []] : $this->function_storage->template_types ?? [];
        return new Template_Result($templates, []);
    }
    public function get_type(): string
    {
        return $this->type;
    }
    public function get_function_type(): Union
    {
        return match ($this->type) {
            self::TYPE_FIRST_CLASS_CALLABLE => new Union([new T_Closure('Closure', $this->function_storage->params, $this->function_storage->return_type, $this->function_storage->pure)]),
            self::TYPE_STRING_CALLABLE, self::TYPE_CLASS_CALLABLE => new Union([new T_Callable('callable', $this->function_storage->params, $this->function_storage->return_type, $this->function_storage->pure)]),
            default => $this->function_storage->return_type ?? Type::get_mixed(),
        };
    }
}