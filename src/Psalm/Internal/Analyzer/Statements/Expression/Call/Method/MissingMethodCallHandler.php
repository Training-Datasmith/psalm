<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Call\Method;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Config;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Arguments_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Class_Template_Param_Collector;
use Psalm\Internal\Analyzer\Statements\Expression\Call_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Type\Template_Inferred_Type_Replacer;
use Psalm\Internal\Type\Template_Result;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Node\Expr\Virtual_Array;
use Psalm\Node\Scalar\Virtual_String;
use Psalm\Node\Virtual_Arg;
use Psalm\Node\Virtual_Array_Item;
use Psalm\Storage\Class_Like_Storage;
use Psalm\Storage\Method_Storage;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Closure;
use Psalm\Type\Union;
use function array_map;
use function array_merge;
/**
 * @internal
 */
final class Missing_Method_Call_Handler
{
    public static function handle_magic_method(Statements_Analyzer $statements_analyzer, Codebase $codebase, Php_Parser\Node\Expr\Method_Call $stmt, Method_Identifier $method_id, Class_Like_Storage $class_storage, Context $context, Config $config, ?Union $all_intersection_return_type, Atomic_Method_Call_Analysis_Result $result, ?Atomic $lhs_type_part): ?Atomic_Call_Context
    {
        $fq_class_name = $method_id->fq_class_name;
        $method_name_lc = $method_id->method_name;
        if ($stmt->is_first_class_callable()) {
            if (isset($class_storage->pseudo_methods[$method_name_lc])) {
                $result->has_valid_method_call_type = true;
                $result->existent_method_ids[$method_id->__toString()] = true;
                $result->return_type = self::create_first_class_callable_return_type($class_storage->pseudo_methods[$method_name_lc]);
            } else {
                $result->non_existent_magic_method_ids[] = $method_id->__toString();
                $result->return_type = self::create_first_class_callable_return_type();
            }
            return null;
        }
        if ($codebase->methods->return_type_provider->has($fq_class_name)) {
            $return_type_candidate = $codebase->methods->return_type_provider->get_return_type($statements_analyzer, $method_id->fq_class_name, $method_id->method_name, $stmt, $context, new Code_Location($statements_analyzer->get_source(), $stmt->name));
            if ($return_type_candidate) {
                if ($all_intersection_return_type) {
                    $return_type_candidate = Type::intersect_union_types($all_intersection_return_type, $return_type_candidate, $codebase) ?? Type::get_mixed();
                }
                $result->return_type = Type::combine_union_types($return_type_candidate, $result->return_type, $codebase);
                Call_Analyzer::check_method_args($method_id, $stmt->get_args(), new Template_Result([], []), $context, new Code_Location($statements_analyzer->get_source(), $stmt), $statements_analyzer);
                return null;
            }
        }
        $found_method_and_class_storage = self::find_pseudo_method_and_class_storages($codebase, $class_storage, $method_name_lc);
        if ($found_method_and_class_storage) {
            $result->has_valid_method_call_type = true;
            $result->existent_method_ids[$method_id->__toString()] = true;
            [$pseudo_method_storage, $defining_class_storage] = $found_method_and_class_storage;
            $found_generic_params = Class_Template_Param_Collector::collect($codebase, $defining_class_storage, $class_storage, $method_name_lc, $lhs_type_part, !$statements_analyzer->is_static() && $method_id->fq_class_name === $context->self);
            Arguments_Analyzer::analyze($statements_analyzer, $stmt->get_args(), $pseudo_method_storage->params, (string) $method_id, true, $context, $found_generic_params ? new Template_Result([], $found_generic_params) : null);
            Arguments_Analyzer::check_arguments_match($statements_analyzer, $stmt->get_args(), null, $pseudo_method_storage->params, $pseudo_method_storage, null, new Template_Result([], $found_generic_params ?: []), new Code_Location($statements_analyzer, $stmt), $context);
            if ($pseudo_method_storage->return_type) {
                $return_type_candidate = $pseudo_method_storage->return_type;
                if ($found_generic_params) {
                    $return_type_candidate = Template_Inferred_Type_Replacer::replace($return_type_candidate, new Template_Result([], $found_generic_params), $codebase);
                }
                $return_type_candidate = Type_Expander::expand_union($codebase, $return_type_candidate, $defining_class_storage->name, $lhs_type_part instanceof Atomic\T_Named_Object ? $lhs_type_part : $fq_class_name, $defining_class_storage->parent_class);
                if ($all_intersection_return_type) {
                    $return_type_candidate = Type::intersect_union_types($all_intersection_return_type, $return_type_candidate, $codebase) ?? Type::get_mixed();
                }
                $result->return_type = Type::combine_union_types($return_type_candidate, $result->return_type, $codebase);
                return null;
            }
        } elseif ($all_intersection_return_type === null) {
            Arguments_Analyzer::analyze($statements_analyzer, $stmt->get_args(), null, null, true, $context);
            if ($class_storage->has_sealed_methods($config)) {
                $result->non_existent_magic_method_ids[] = $method_id->__toString();
                return null;
            }
        }
        $result->has_valid_method_call_type = true;
        $result->existent_method_ids[$method_id->__toString()] = true;
        $array_values = array_map(static fn(Php_Parser\Node\Arg $arg): Php_Parser\Node\Array_Item => new Virtual_Array_Item($arg->value, null, false, $arg->get_attributes()), $stmt->get_args());
        $statements_analyzer->node_data = clone $statements_analyzer->node_data;
        return new Atomic_Call_Context(new Method_Identifier($fq_class_name, '__call'), [new Virtual_Arg(new Virtual_String($method_name_lc), false, false, $stmt->get_attributes()), new Virtual_Arg(new Virtual_Array($array_values, $stmt->get_attributes()), false, false, $stmt->get_attributes())]);
    }
    /**
     * @param array<string, bool> $all_intersection_existent_method_ids
     */
    public static function handle_missing_or_magic_method(Statements_Analyzer $statements_analyzer, Codebase $codebase, Php_Parser\Node\Expr\Method_Call $stmt, Method_Identifier $method_id, bool $is_interface, Context $context, Config $config, ?Union $all_intersection_return_type, array $all_intersection_existent_method_ids, ?string $intersection_method_id, string $cased_method_id, Atomic_Method_Call_Analysis_Result $result, ?Atomic $lhs_type_part): void
    {
        $fq_class_name = $method_id->fq_class_name;
        $method_name_lc = $method_id->method_name;
        $class_storage = $codebase->classlike_storage_provider->get($fq_class_name);
        $found_method_and_class_storage = self::find_pseudo_method_and_class_storages($codebase, $class_storage, $method_name_lc);
        if (($is_interface || $config->use_phpdoc_method_without_magic_or_parent) && $found_method_and_class_storage) {
            $result->has_valid_method_call_type = true;
            $result->existent_method_ids[$method_id->__toString()] = true;
            [$pseudo_method_storage, $defining_class_storage] = $found_method_and_class_storage;
            if ($stmt->is_first_class_callable()) {
                $result->return_type = self::create_first_class_callable_return_type($pseudo_method_storage);
                return;
            }
            $found_generic_params = Class_Template_Param_Collector::collect($codebase, $defining_class_storage, $class_storage, $method_name_lc, $lhs_type_part, !$statements_analyzer->is_static() && $method_id->fq_class_name === $context->self);
            if (Arguments_Analyzer::analyze($statements_analyzer, $stmt->get_args(), $pseudo_method_storage->params, (string) $method_id, true, $context, $found_generic_params ? new Template_Result([], $found_generic_params) : null) === false) {
                return;
            }
            if (Arguments_Analyzer::check_arguments_match($statements_analyzer, $stmt->get_args(), null, $pseudo_method_storage->params, $pseudo_method_storage, null, new Template_Result([], $found_generic_params ?: []), new Code_Location($statements_analyzer, $stmt->name), $context) === false) {
                return;
            }
            if ($pseudo_method_storage->return_type) {
                $return_type_candidate = $pseudo_method_storage->return_type;
                if ($found_generic_params) {
                    $return_type_candidate = Template_Inferred_Type_Replacer::replace($return_type_candidate, new Template_Result([], $found_generic_params), $codebase);
                }
                if ($all_intersection_return_type) {
                    $return_type_candidate = Type::intersect_union_types($all_intersection_return_type, $return_type_candidate, $codebase) ?? Type::get_mixed();
                }
                $return_type_candidate = Type_Expander::expand_union($codebase, $return_type_candidate, $defining_class_storage->name, $lhs_type_part instanceof Atomic\T_Named_Object ? $lhs_type_part : $fq_class_name, $defining_class_storage->parent_class, true, false, $class_storage->final);
                $result->return_type = Type::combine_union_types($return_type_candidate, $result->return_type);
                return;
            }
            $result->return_type = Type::get_mixed();
            return;
        }
        if ($stmt->is_first_class_callable()) {
            $result->non_existent_class_method_ids[] = $method_id->__toString();
            $result->return_type = self::create_first_class_callable_return_type();
            return;
        }
        if (Arguments_Analyzer::analyze($statements_analyzer, $stmt->get_args(), null, null, true, $context) === false) {
            return;
        }
        if ($all_intersection_return_type && $all_intersection_existent_method_ids) {
            $result->existent_method_ids = array_merge($result->existent_method_ids, $all_intersection_existent_method_ids);
            $result->return_type = Type::combine_union_types($all_intersection_return_type, $result->return_type);
            return;
        }
        if (!$is_interface && !$config->use_phpdoc_method_without_magic_or_parent || !isset($class_storage->pseudo_methods[$method_name_lc])) {
            if ($is_interface) {
                $result->non_existent_interface_method_ids[] = $intersection_method_id ?: $cased_method_id;
            } else {
                $result->non_existent_class_method_ids[] = $intersection_method_id ?: $cased_method_id;
            }
        }
    }
    private static function create_first_class_callable_return_type(?Method_Storage $method_storage = null): Union
    {
        if ($method_storage) {
            return new Union([new T_Closure('Closure', $method_storage->params, $method_storage->return_type, $method_storage->pure)]);
        }
        return Type::get_closure();
    }
    /**
     * Try to find matching pseudo method over ancestors (including interfaces).
     *
     * Returns the pseudo method if exists, with its defining class storage.
     * If the method is not declared, null is returned.
     *
     * @param ClassLikeStorage $static_class_storage The called class
     * @param lowercase-string $method_name_lc
     * @return array{MethodStorage, ClassLikeStorage}
     */
    private static function find_pseudo_method_and_class_storages(Codebase $codebase, Class_Like_Storage $static_class_storage, string $method_name_lc): ?array
    {
        if (isset($static_class_storage->declaring_pseudo_method_ids[$method_name_lc])) {
            $method_id = $static_class_storage->declaring_pseudo_method_ids[$method_name_lc];
            $class_storage = $codebase->classlikes->get_storage_for($method_id->fq_class_name);
            if ($class_storage && isset($class_storage->pseudo_methods[$method_name_lc])) {
                return [$class_storage->pseudo_methods[$method_name_lc], $class_storage];
            }
        }
        if ($pseudo_method_storage = $static_class_storage->pseudo_methods[$method_name_lc] ?? null) {
            return [$pseudo_method_storage, $static_class_storage];
        }
        $ancestors = $static_class_storage->class_implements;
        foreach ($static_class_storage->named_mixins as $named_object) {
            $type = $named_object->value;
            if ($type) {
                $ancestors[$type] = true;
            }
        }
        foreach ($ancestors as $fq_class_name => $_) {
            $class_storage = $codebase->classlikes->get_storage_for($fq_class_name);
            if ($class_storage && isset($class_storage->pseudo_methods[$method_name_lc])) {
                return [$class_storage->pseudo_methods[$method_name_lc], $class_storage];
            }
        }
        return null;
    }
}