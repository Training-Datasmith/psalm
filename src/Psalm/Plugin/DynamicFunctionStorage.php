<?php

declare (strict_types=1);
namespace Psalm\Plugin;

use Psalm\Storage\Function_Like_Parameter;
use Psalm\Storage\Function_Storage;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Union;
final class Dynamic_Function_Storage
{
    /**
     * Required param list for a function.
     *
     * @var list<FunctionLikeParameter>
     */
    public array $params = [];
    /**
     * A function return type. Maybe null.
     * That means we can infer it in {@see FunctionReturnTypeProviderInterface} hook.
     */
    public ?Union $return_type = null;
    /**
     * A function can have template args or return type.
     * Plugin hook must fill all used templates here.
     *
     * @var list<TTemplateParam>
     */
    public array $templates = [];
    /**
     * Determines if a function can be called with named arguments.
     */
    public bool $allow_named_arg_calls = true;
    /**
     * Function purity.
     * If function is pure then plugin hook should set it to true.
     */
    public bool $pure = false;
    /**
     * Determines if a function can be called with a various number of arguments.
     */
    public bool $variadic = false;
    /**
     * @internal
     */
    public function to_function_storage(string $function_cased_name): Function_Storage
    {
        $storage = new Function_Storage();
        $storage->cased_name = $function_cased_name;
        $storage->set_params($this->params);
        $storage->return_type = $this->return_type;
        $storage->allow_named_arg_calls = $this->allow_named_arg_calls;
        $storage->pure = $this->pure;
        $storage->variadic = $this->variadic;
        if (!empty($this->templates)) {
            $storage->template_types = [];
            foreach ($this->templates as $template) {
                $storage->template_types[$template->param_name] = [$template->defining_class => $template->as];
            }
        }
        return $storage;
    }
}