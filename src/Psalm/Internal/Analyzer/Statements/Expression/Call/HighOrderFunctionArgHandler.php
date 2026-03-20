<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Call;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Type\Template_Inferred_Type_Replacer;
use Psalm\Internal\Type\Template_Result;
use Psalm\Internal\Type\Template_Standin_Type_Replacer;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Storage\Function_Like_Parameter;
use Psalm\Type;
use Psalm\Type\Atomic\T_Callable;
use Psalm\Type\Atomic\T_Closure;
use Psalm\Type\Union;
use UnexpectedValueException;
use function is_string;
use function str_contains;
use function strtolower;
/**
 * @internal
 */
final class High_Order_Function_Arg_Handler
{
    /**
     * Compiles TemplateResult for high-order function
     * by previous template args ($inferred_template_result).
     *
     * It's need for proper template replacement:
     *
     * ```
     * * template T
     * * return Closure(T): T
     * function id(): Closure { ... }
     *
     * * template A
     * * template B
     * *
     * * param list<A> $_items
     * * param callable(A): B $_ab
     * * return list<B>
     * function map(array $items, callable $ab): array { ... }
     *
     * // list<int>
     * $numbers = [1, 2, 3];
     *
     * $result = map($numbers, id());
     * // $result is list<int> because template T of id() was inferred by previous arg.
     * ```
     */
    public static function remap_lower_bounds(Statements_Analyzer $statements_analyzer, Template_Result $inferred_template_result, High_Order_Function_Arg_Info $input_function, Union $container_function_type): Template_Result
    {
        // Try to infer container callable by $inferred_template_result
        $container_type = Template_Inferred_Type_Replacer::replace($container_function_type, $inferred_template_result, $statements_analyzer->get_codebase());
        $input_function_type = $input_function->get_function_type();
        $input_function_template_result = $input_function->get_templates();
        // Traverse side by side 'container' params and 'input' params.
        // This maps 'input' templates to 'container' templates.
        //
        // Example:
        // 'input'     => Closure(C:Bar, D:Bar): array{C:Bar, D:Bar}
        // 'container' => Closure(int, string): array{int, string}
        //
        // $remapped_lower_bounds will be: [
        //     'C' => ['Bar' => [int]],
        //     'D' => ['Bar' => [string]]
        // ].
        foreach ($input_function_type->get_atomic_types() as $input_atomic) {
            if (!$input_atomic instanceof T_Closure && !$input_atomic instanceof T_Callable) {
                continue;
            }
            foreach ($container_type->get_atomic_types() as $container_atomic) {
                if (!$container_atomic instanceof T_Closure && !$container_atomic instanceof T_Callable) {
                    continue;
                }
                foreach ($input_atomic->params ?? [] as $offset => $input_param) {
                    if (!isset($container_atomic->params[$offset])) {
                        continue;
                    }
                    Template_Standin_Type_Replacer::fill_template_result($input_param->type ?? Type::get_mixed(), $input_function_template_result, $statements_analyzer->get_codebase(), $statements_analyzer, $container_atomic->params[$offset]->type);
                }
            }
        }
        return $input_function_template_result;
    }
    public static function enhance_callable_arg_type(Context $context, Php_Parser\Node\Expr $arg_expr, Statements_Analyzer $statements_analyzer, High_Order_Function_Arg_Info $high_order_callable_info, Template_Result $high_order_template_result): void
    {
        // Psalm can infer simple callable/closure.
        // But can't infer first-class-callable or high-order function.
        if ($high_order_callable_info->get_type() === High_Order_Function_Arg_Info::TYPE_CALLABLE) {
            return;
        }
        $fully_inferred_callable_type = Template_Inferred_Type_Replacer::replace($high_order_callable_info->get_function_type(), $high_order_template_result, $statements_analyzer->get_codebase());
        // Some templates may not have been replaced.
        // They expansion makes error message better.
        $expanded = Type_Expander::expand_union($statements_analyzer->get_codebase(), $fully_inferred_callable_type, $context->self, $context->self, $context->parent, true, true, false, false, true);
        $statements_analyzer->node_data->set_type($arg_expr, $expanded);
    }
    public static function get_callable_arg_info(Context $context, Php_Parser\Node\Expr $input_arg_expr, Statements_Analyzer $statements_analyzer, Function_Like_Parameter $container_param): ?High_Order_Function_Arg_Info
    {
        if (!self::is_supported($container_param)) {
            return null;
        }
        $codebase = $statements_analyzer->get_codebase();
        try {
            if ($input_arg_expr instanceof Php_Parser\Node\Expr\Func_Call) {
                $function_id = strtolower((string) $input_arg_expr->name->get_attribute('resolvedName'));
                if (empty($function_id)) {
                    return null;
                }
                $dynamic_storage = !$input_arg_expr->is_first_class_callable() ? $codebase->functions->dynamic_storage_provider->get_function_storage($input_arg_expr, $statements_analyzer, $function_id, $context, new Code_Location($statements_analyzer, $input_arg_expr)) : null;
                return new High_Order_Function_Arg_Info($input_arg_expr->is_first_class_callable() ? High_Order_Function_Arg_Info::TYPE_FIRST_CLASS_CALLABLE : High_Order_Function_Arg_Info::TYPE_CALLABLE, $dynamic_storage ?? $codebase->functions->get_storage($statements_analyzer, $function_id));
            }
            if ($input_arg_expr instanceof Php_Parser\Node\Expr\Method_Call && $input_arg_expr->var instanceof Php_Parser\Node\Expr\Variable && $input_arg_expr->name instanceof Php_Parser\Node\Identifier && is_string($input_arg_expr->var->name) && isset($context->vars_in_scope['$' . $input_arg_expr->var->name])) {
                $lhs_type = $context->vars_in_scope['$' . $input_arg_expr->var->name]->get_single_atomic();
                if (!$lhs_type instanceof Type\Atomic\T_Named_Object) {
                    return null;
                }
                $method_id = new Method_Identifier($lhs_type->value, strtolower((string) $input_arg_expr->name));
                return new High_Order_Function_Arg_Info($input_arg_expr->is_first_class_callable() ? High_Order_Function_Arg_Info::TYPE_FIRST_CLASS_CALLABLE : High_Order_Function_Arg_Info::TYPE_CALLABLE, $codebase->methods->get_storage($method_id));
            }
            if ($input_arg_expr instanceof Php_Parser\Node\Expr\Static_Call && $input_arg_expr->name instanceof Php_Parser\Node\Identifier) {
                $method_id = new Method_Identifier((string) $input_arg_expr->class->get_attribute('resolvedName'), strtolower($input_arg_expr->name->to_string()));
                return new High_Order_Function_Arg_Info($input_arg_expr->is_first_class_callable() ? High_Order_Function_Arg_Info::TYPE_FIRST_CLASS_CALLABLE : High_Order_Function_Arg_Info::TYPE_CALLABLE, $codebase->methods->get_storage($method_id));
            }
            if ($input_arg_expr instanceof Php_Parser\Node\Scalar\String_) {
                return self::from_literal_string(Type::get_string($input_arg_expr->value), $statements_analyzer);
            }
            if ($input_arg_expr instanceof Php_Parser\Node\Expr\Const_Fetch) {
                $constant = $context->constants[$input_arg_expr->name->to_string()] ?? null;
                return null !== $constant ? self::from_literal_string($constant, $statements_analyzer) : null;
            }
            if ($input_arg_expr instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $input_arg_expr->name instanceof Php_Parser\Node\Identifier) {
                $storage = $codebase->classlikes->get_storage_for((string) $input_arg_expr->class->get_attribute('resolvedName'));
                $constant = null !== $storage ? $storage->constants[$input_arg_expr->name->to_string()] ?? null : null;
                return null !== $constant && null !== $constant->type ? self::from_literal_string($constant->type, $statements_analyzer) : null;
            }
            if ($input_arg_expr instanceof Php_Parser\Node\Expr\New_ && $input_arg_expr->class instanceof Php_Parser\Node\Name) {
                $class_storage = $codebase->classlikes->get_storage_for((string) $input_arg_expr->class->get_attribute('resolvedName'));
                $invoke_storage = $class_storage && isset($class_storage->methods['__invoke']) ? $class_storage->methods['__invoke'] : null;
                if (!$invoke_storage) {
                    return null;
                }
                return new High_Order_Function_Arg_Info(High_Order_Function_Arg_Info::TYPE_CLASS_CALLABLE, $invoke_storage, $class_storage);
            }
        } catch (UnexpectedValueException) {
            return null;
        }
        return null;
    }
    private static function is_supported(Function_Like_Parameter $container_param): bool
    {
        if (!$container_param->type || !$container_param->type->has_callable_type()) {
            return false;
        }
        foreach ($container_param->type->get_atomic_types() as $a) {
            // must check null explicitly, since no params (empty array) would not be handled correctly otherwise
            if (($a instanceof T_Closure || $a instanceof T_Callable) && $a->params === null) {
                return false;
            }
            if ($a instanceof Type\Atomic\T_Callable_String || $a instanceof Type\Atomic\T_Callable_Keyed_Array) {
                return false;
            }
        }
        return true;
    }
    private static function from_literal_string(Union $constant, Statements_Analyzer $statements_analyzer): ?High_Order_Function_Arg_Info
    {
        $literal = $constant->is_single() ? $constant->get_single_atomic() : null;
        if (!$literal instanceof Type\Atomic\T_Literal_String || empty($literal->value)) {
            return null;
        }
        $codebase = $statements_analyzer->get_codebase();
        return new High_Order_Function_Arg_Info(High_Order_Function_Arg_Info::TYPE_STRING_CALLABLE, str_contains($literal->value, '::') ? $codebase->methods->get_storage(Method_Identifier::wrap($literal->value)) : $codebase->functions->get_storage($statements_analyzer, strtolower($literal->value)));
    }
}