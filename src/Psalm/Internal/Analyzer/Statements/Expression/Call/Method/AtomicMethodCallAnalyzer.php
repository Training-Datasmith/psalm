<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Call\Method;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Class_Like_Name_Options;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Analyzer\Method_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Arguments_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Class_Template_Param_Collector;
use Psalm\Internal\Analyzer\Statements\Expression\Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Analyzer\Trait_Analyzer;
use Psalm\Internal\Codebase\Internal_Call_Map_Handler;
use Psalm\Internal\Codebase\Variable_Use_Graph;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Type\Template_Result;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Issue\Mixed_Method_Call;
use Psalm\Issue_Buffer;
use Psalm\Statements_Source;
use Psalm\Storage\Class_Like_Storage;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Callable;
use Psalm\Type\Atomic\T_Callable_Object;
use Psalm\Type\Atomic\T_Closure;
use Psalm\Type\Atomic\T_Empty_Mixed;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_Generic_Object;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Never;
use Psalm\Type\Atomic\T_Non_Empty_Mixed;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_Object;
use Psalm\Type\Atomic\T_Object_With_Properties;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Union;
use function array_keys;
use function array_merge;
use function array_search;
use function array_shift;
use function array_values;
use function count;
use function reset;
use function strtolower;
/**
 * This is a bunch of complex logic to handle the potential for missing methods,
 * use of intersection types and/or mixins, together with handling for fallback magic
 * methods.
 *
 * The happy path (i.e 99% of method calls) is handled in ExistingAtomicMethodCallAnalyzer
 *
 * @internal
 */
final class Atomic_Method_Call_Analyzer extends Call_Analyzer
{
    /**
     * @param  TNamedObject|TTemplateParam|null $static_type
     * @psalm-suppress ComplexMethod it's really complex, but unavoidably so
     */
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Method_Call $stmt, Codebase $codebase, Context $context, Union $lhs_type, Atomic $lhs_type_part, ?Atomic $static_type, bool $is_intersection, ?string $lhs_var_id, Atomic_Method_Call_Analysis_Result $result, ?Template_Result $inferred_template_result = null): void
    {
        if ($lhs_type_part instanceof T_Template_Param && !$lhs_type_part->as->is_mixed()) {
            $extra_types = $lhs_type_part->extra_types;
            $lhs_type_part = array_values($lhs_type_part->as->get_atomic_types())[0];
            if ($lhs_type_part instanceof T_Named_Object) {
                $lhs_type_part = $lhs_type_part->set_intersection_types($extra_types)->set_from_docblock(true);
            } elseif ($lhs_type_part instanceof T_Object && $extra_types) {
                $lhs_type_part = array_shift($extra_types)->set_from_docblock(true);
                if ($extra_types) {
                    $lhs_type_part = $lhs_type_part->set_intersection_types($extra_types);
                }
            } else {
                $lhs_type_part = $lhs_type_part->set_from_docblock(true);
            }
            $result->has_mixed_method_call = true;
        }
        $source = $statements_analyzer->get_source();
        if ($lhs_type_part instanceof T_Callable_Object) {
            self::handle_callable_object($statements_analyzer, $stmt, $context, $lhs_type_part->callable, $result, $inferred_template_result);
            return;
        }
        if (!$lhs_type_part instanceof T_Named_Object) {
            self::handle_invalid_class($statements_analyzer, $codebase, $stmt, $lhs_type, $lhs_type_part, $lhs_var_id, $context, $is_intersection, $result);
            return;
        }
        if (!$context->collect_initializations && !$context->collect_mutations && $statements_analyzer->get_file_path() === $statements_analyzer->get_root_file_path() && (!($parent_source = $statements_analyzer->get_source()) instanceof Function_Like_Analyzer || !$parent_source->get_source() instanceof Trait_Analyzer)) {
            $codebase->analyzer->increment_non_mixed_count($statements_analyzer->get_file_path());
        }
        $result->has_valid_method_call_type = true;
        $fq_class_name = $lhs_type_part->value;
        $is_mock = Expression_Analyzer::is_mock($fq_class_name);
        $result->has_mock = $result->has_mock || $is_mock;
        if ($fq_class_name === 'static') {
            $fq_class_name = (string) $context->self;
        }
        if ($is_mock || $context->is_phantom_class($fq_class_name)) {
            $result->return_type = Type::get_mixed();
            Arguments_Analyzer::analyze($statements_analyzer, $stmt->get_args(), null, null, true, $context);
            return;
        }
        if ($lhs_var_id === '$this') {
            $does_class_exist = true;
        } else {
            $does_class_exist = Class_Like_Analyzer::check_fully_qualified_class_like_name($statements_analyzer, $fq_class_name, new Code_Location($source, $stmt->var), $context->self, $context->calling_method_id, $statements_analyzer->get_suppressed_issues(), new Class_Like_Name_Options(true, false, true, true, $lhs_type_part->from_docblock), $context->check_classes);
        }
        if (!$does_class_exist) {
            return;
        }
        $class_storage = $codebase->classlike_storage_provider->get($fq_class_name);
        $result->check_visibility = $result->check_visibility && !$class_storage->override_method_visibility;
        $intersection_types = $lhs_type_part->get_intersection_types();
        if (!$stmt->name instanceof Php_Parser\Node\Identifier) {
            if (!$context->ignore_variable_method) {
                $codebase->analyzer->add_mixed_member_name(strtolower($fq_class_name) . '::', $context->calling_method_id ?: $statements_analyzer->get_file_name());
            }
            if ($stmt->is_first_class_callable()) {
                $return_type_candidate = null;
                $method_name_type = $statements_analyzer->node_data->get_type($stmt->name);
                if ($method_name_type && $method_name_type->is_single_string_literal()) {
                    $method_identifier = new Method_Identifier($fq_class_name, strtolower($method_name_type->get_single_string_literal()->value));
                    //the call to methodExists will register that the method was called from somewhere
                    if ($codebase->methods->method_exists($method_identifier, $context->calling_method_id, null, $statements_analyzer, $statements_analyzer->get_file_path(), true, $context->inside_use())) {
                        $method_storage = $codebase->methods->get_storage($method_identifier);
                        $return_type_candidate = new Union([new T_Closure('Closure', $method_storage->params, $method_storage->return_type, $method_storage->pure)]);
                    }
                }
                $statements_analyzer->node_data->set_type($stmt, $return_type_candidate ?? Type::get_closure());
                return;
            }
            Arguments_Analyzer::analyze($statements_analyzer, $stmt->get_args(), null, null, true, $context);
            $result->return_type = Type::get_mixed();
            return;
        }
        $method_name_lc = strtolower($stmt->name->name);
        $method_id = new Method_Identifier($fq_class_name, $method_name_lc);
        $args = $stmt->is_first_class_callable() ? [] : $stmt->get_args();
        $naive_method_id = $method_id;
        // this tells us whether or not we can stay on the happy path
        $naive_method_exists = $codebase->methods->method_exists($method_id, $context->calling_method_id, $codebase->collect_locations ? new Code_Location($source, $stmt->name) : null, !$context->collect_initializations && !$context->collect_mutations ? $statements_analyzer : null, $statements_analyzer->get_file_path(), false, $context->inside_use());
        $fake_method_exists = false;
        if (!$naive_method_exists) {
            // if the method doesn't exist we check for any method existence providers
            if ($codebase->methods->existence_provider->has($fq_class_name)) {
                $method_exists = $codebase->methods->existence_provider->does_method_exist($fq_class_name, $method_id->method_name, $source);
                if ($method_exists) {
                    $fake_method_exists = true;
                }
            }
            $naive_method_exists = false;
            // @mixin attributes are an absolute pain! Lots of complexity here,
            // as they can redefine the called class, method id etc.
            if ($class_storage->templated_mixins && $lhs_type_part instanceof T_Generic_Object && $class_storage->template_types) {
                [$lhs_type_part, $class_storage, $naive_method_exists, $method_id, $fq_class_name] = self::handle_templated_mixins($class_storage, $lhs_type_part, $method_name_lc, $codebase, $context, $method_id, $source, $stmt, $statements_analyzer, $fq_class_name);
            } elseif ($class_storage->mixin_declaring_fqcln && $class_storage->named_mixins) {
                [$lhs_type_part, $class_storage, $naive_method_exists, $method_id, $fq_class_name] = self::handle_regular_mixins($class_storage, $lhs_type_part, $method_name_lc, $codebase, $context, $method_id, $source, $stmt, $statements_analyzer, $fq_class_name, $lhs_var_id);
            }
        }
        $all_intersection_return_type = null;
        $all_intersection_existent_method_ids = [];
        // intersection types are also fun, they also complicate matters
        if ($intersection_types) {
            [$all_intersection_return_type, $all_intersection_existent_method_ids] = self::get_intersection_return_type($statements_analyzer, $stmt, $codebase, $context, $lhs_type, $lhs_type_part, $lhs_var_id, $result, $intersection_types);
        }
        if ($fake_method_exists && $codebase->methods->method_exists(new Method_Identifier($fq_class_name, '__call')) || !$naive_method_exists || !Method_Analyzer::is_method_visible($method_id, $context, $statements_analyzer->get_source())) {
            $interface_has_method = false;
            if ($class_storage->abstract && $class_storage->class_implements) {
                foreach ($class_storage->class_implements as $interface_fqcln_lc => $_) {
                    $interface_storage = $codebase->classlike_storage_provider->get($interface_fqcln_lc);
                    if (isset($interface_storage->methods[$method_name_lc])) {
                        $interface_has_method = true;
                        $fq_class_name = $interface_storage->name;
                        $method_id = new Method_Identifier($fq_class_name, $method_name_lc);
                        break;
                    }
                }
            }
            if (!$interface_has_method && $codebase->methods->method_exists(new Method_Identifier($fq_class_name, '__call'), $context->calling_method_id, $codebase->collect_locations ? new Code_Location($source, $stmt->name) : null, !$context->collect_initializations && !$context->collect_mutations ? $statements_analyzer : null, $statements_analyzer->get_file_path(), true, $context->inside_use())) {
                $new_call_context = Missing_Method_Call_Handler::handle_magic_method($statements_analyzer, $codebase, $stmt, $method_id, $class_storage, $context, $codebase->config, $all_intersection_return_type, $result, $lhs_type_part);
                if ($new_call_context) {
                    if ($method_id === $new_call_context->method_id) {
                        return;
                    }
                    $method_id = $new_call_context->method_id;
                    $args = $new_call_context->args;
                } else {
                    return;
                }
            }
        }
        $intersection_method_id = $intersection_types ? '(' . $lhs_type_part . ')' . '::' . $stmt->name->name : null;
        $cased_method_id = $fq_class_name . '::' . $stmt->name->name;
        if ($lhs_var_id === '$this' && $context->self && $fq_class_name !== $context->self && $codebase->methods->method_exists(new Method_Identifier($context->self, $method_name_lc))) {
            $method_id = new Method_Identifier($context->self, $method_name_lc);
            $cased_method_id = $context->self . '::' . $stmt->name->name;
            $fq_class_name = $context->self;
        }
        $source_method_id = $source instanceof Function_Like_Analyzer ? $source->get_id() : null;
        $corrected_method_exists = $naive_method_exists && $method_id === $naive_method_id || $method_id !== $naive_method_id && $codebase->methods->method_exists($method_id, $context->calling_method_id, $codebase->collect_locations && $method_id !== $source_method_id ? new Code_Location($source, $stmt->name) : null);
        if (!$corrected_method_exists || $codebase->config->use_phpdoc_method_without_magic_or_parent && isset($class_storage->pseudo_methods[$method_name_lc])) {
            Missing_Method_Call_Handler::handle_missing_or_magic_method($statements_analyzer, $codebase, $stmt, $method_id, $codebase->interface_exists($fq_class_name), $context, $codebase->config, $all_intersection_return_type, $all_intersection_existent_method_ids, $intersection_method_id, $cased_method_id, $result, $lhs_type_part);
            return;
        }
        $old_node_data = $statements_analyzer->node_data;
        $return_type_candidate = Existing_Atomic_Method_Call_Analyzer::analyze($statements_analyzer, $stmt, $stmt->name, $args, $codebase, $context, $lhs_type_part, $static_type, $lhs_var_id, $method_id, $result, $inferred_template_result);
        $statements_analyzer->node_data = $old_node_data;
        $declaring_method_id = $codebase->methods->get_declaring_method_id($method_id);
        $in_call_map = Internal_Call_Map_Handler::in_call_map((string) ($declaring_method_id ?? $method_id));
        if (!$in_call_map) {
            if ($result->check_visibility) {
                $name_code_location = new Code_Location($statements_analyzer, $stmt->name);
                Method_Visibility_Analyzer::analyze($method_id, $context, $statements_analyzer->get_source(), $name_code_location, $statements_analyzer->get_suppressed_issues());
            }
        }
        self::update_result_return_type($result, $return_type_candidate, $all_intersection_return_type, $codebase);
    }
    /**
     * @param  TNamedObject|TTemplateParam $lhs_type_part
     * @param   array<string, Atomic> $intersection_types
     * @return  array{?Union, array<string, bool>}
     */
    private static function get_intersection_return_type(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Method_Call $stmt, Codebase $codebase, Context $context, Union $lhs_type, Atomic $lhs_type_part, ?string $lhs_var_id, Atomic_Method_Call_Analysis_Result $result, array $intersection_types): array
    {
        $all_intersection_return_type = null;
        $all_intersection_existent_method_ids = [];
        foreach ($intersection_types as $intersection_type) {
            $intersection_result = clone $result;
            /** @var ?Union $intersection_result->return_type */
            $intersection_result->return_type = null;
            self::analyze($statements_analyzer, $stmt, $codebase, $context, $lhs_type, $intersection_type, $lhs_type_part, true, $lhs_var_id, $intersection_result);
            $result->returns_by_ref = $intersection_result->returns_by_ref;
            $result->has_mock = $intersection_result->has_mock;
            $result->has_valid_method_call_type = $intersection_result->has_valid_method_call_type;
            $result->has_mixed_method_call = $intersection_result->has_mixed_method_call;
            $result->invalid_method_call_types = $intersection_result->invalid_method_call_types;
            $result->check_visibility = $intersection_result->check_visibility;
            $result->too_many_arguments = $intersection_result->too_many_arguments;
            $all_intersection_existent_method_ids = array_merge($all_intersection_existent_method_ids, $intersection_result->existent_method_ids);
            if ($intersection_result->return_type) {
                if (!$all_intersection_return_type || $all_intersection_return_type->is_mixed()) {
                    $all_intersection_return_type = $intersection_result->return_type;
                } else {
                    $all_intersection_return_type = Type::intersect_union_types($all_intersection_return_type, $intersection_result->return_type, $codebase) ?? Type::get_mixed();
                }
            }
        }
        return [$all_intersection_return_type, $all_intersection_existent_method_ids];
    }
    private static function update_result_return_type(Atomic_Method_Call_Analysis_Result $result, Union $return_type_candidate, ?Union $all_intersection_return_type, Codebase $codebase): void
    {
        if ($all_intersection_return_type) {
            $return_type_candidate = Type::intersect_union_types($all_intersection_return_type, $return_type_candidate, $codebase) ?? Type::get_mixed();
        }
        $result->return_type = Type::combine_union_types($return_type_candidate, $result->return_type);
    }
    private static function handle_invalid_class(Statements_Analyzer $statements_analyzer, Codebase $codebase, Php_Parser\Node\Expr\Method_Call $stmt, Union $lhs_type, Atomic $lhs_type_part, ?string $lhs_var_id, Context $context, bool $is_intersection, Atomic_Method_Call_Analysis_Result $result): void
    {
        switch ($lhs_type_part::class) {
            case T_Null::class:
            case T_False::class:
                // handled above
                return;
            case T_Template_Param::class:
            case T_Empty_Mixed::class:
            case T_Never::class:
            case T_Mixed::class:
            case T_Non_Empty_Mixed::class:
            case T_Object::class:
            case T_Object_With_Properties::class:
                if (!$context->collect_initializations && !$context->collect_mutations && $statements_analyzer->get_file_path() === $statements_analyzer->get_root_file_path() && (!($parent_source = $statements_analyzer->get_source()) instanceof Function_Like_Analyzer || !$parent_source->get_source() instanceof Trait_Analyzer)) {
                    $codebase->analyzer->increment_mixed_count($statements_analyzer->get_file_path());
                }
                $result->has_mixed_method_call = true;
                if ($lhs_type_part instanceof T_Object_With_Properties && $stmt->name instanceof Php_Parser\Node\Identifier && isset($lhs_type_part->methods[strtolower($stmt->name->name)])) {
                    $method_id = $lhs_type_part->methods[strtolower($stmt->name->name)];
                    $result->existent_method_ids[$method_id] = true;
                } elseif (!$is_intersection) {
                    if ($stmt->name instanceof Php_Parser\Node\Identifier) {
                        $codebase->analyzer->add_mixed_member_name(strtolower($stmt->name->name), $context->calling_method_id ?: $statements_analyzer->get_file_name());
                    }
                    if ($context->check_methods) {
                        $message = 'Cannot determine the type of the object' . ' on the left hand side of this expression';
                        if ($lhs_var_id) {
                            $message = 'Cannot determine the type of ' . $lhs_var_id;
                            if ($stmt->name instanceof Php_Parser\Node\Identifier) {
                                $message .= ' when calling method ' . $stmt->name->name;
                            }
                        }
                        $origin_locations = [];
                        if ($statements_analyzer->data_flow_graph instanceof Variable_Use_Graph) {
                            foreach ($lhs_type->parent_nodes as $parent_node) {
                                $origin_locations = [...$origin_locations, ...$statements_analyzer->data_flow_graph->get_origin_locations($parent_node)];
                            }
                        }
                        $origin_location = count($origin_locations) === 1 ? reset($origin_locations) : null;
                        $name_code_location = new Code_Location($statements_analyzer, $stmt->name);
                        if ($origin_location && $origin_location->get_hash() === $name_code_location->get_hash()) {
                            $origin_location = null;
                        }
                        Issue_Buffer::maybe_add(new Mixed_Method_Call($message, $name_code_location, $origin_location), $statements_analyzer->get_suppressed_issues());
                    }
                }
                if ($stmt->is_first_class_callable()) {
                    $result->return_type = Type::get_closure();
                } else {
                    if (Arguments_Analyzer::analyze($statements_analyzer, $stmt->get_args(), null, null, true, $context) === false) {
                        return;
                    }
                    $result->return_type = Type::get_mixed();
                }
                return;
            default:
                $result->invalid_method_call_types[] = (string) $lhs_type_part;
                return;
        }
    }
    /**
     * @param lowercase-string $method_name_lc
     * @return array{TNamedObject, ClassLikeStorage, bool, MethodIdentifier, string}
     */
    private static function handle_templated_mixins(Class_Like_Storage $class_storage, T_Named_Object $lhs_type_part, string $method_name_lc, Codebase $codebase, Context $context, Method_Identifier $method_id, Statements_Source $source, Php_Parser\Node\Expr\Method_Call $stmt, Statements_Analyzer $statements_analyzer, string $fq_class_name): array
    {
        $naive_method_exists = false;
        if ($class_storage->templated_mixins && $lhs_type_part instanceof T_Generic_Object && $class_storage->template_types) {
            $template_type_keys = array_keys($class_storage->template_types);
            foreach ($class_storage->templated_mixins as $mixin) {
                $param_position = array_search($mixin->param_name, $template_type_keys, true);
                if ($param_position !== false && isset($lhs_type_part->type_params[$param_position])) {
                    $current_type_param = $lhs_type_part->type_params[$param_position];
                    if ($current_type_param->is_single()) {
                        $lhs_type_part_new = array_values($current_type_param->get_atomic_types())[0];
                        if ($lhs_type_part_new instanceof T_Named_Object) {
                            $new_method_id = new Method_Identifier($lhs_type_part_new->value, $method_name_lc);
                            $mixin_class_storage = $codebase->classlike_storage_provider->get($lhs_type_part_new->value);
                            if ($codebase->methods->method_exists($new_method_id, $context->calling_method_id, $codebase->collect_locations ? new Code_Location($source, $stmt->name) : null, !$context->collect_initializations && !$context->collect_mutations ? $statements_analyzer : null, $statements_analyzer->get_file_path(), true, $context->inside_use())) {
                                $lhs_type_part = $lhs_type_part_new;
                                $class_storage = $mixin_class_storage;
                                $naive_method_exists = true;
                                $method_id = $new_method_id;
                            } elseif (isset($mixin_class_storage->pseudo_methods[$method_name_lc])) {
                                $lhs_type_part = $lhs_type_part_new;
                                $class_storage = $mixin_class_storage;
                                $method_id = $new_method_id;
                            }
                        }
                    }
                }
            }
        }
        return [$lhs_type_part, $class_storage, $naive_method_exists, $method_id, $fq_class_name];
    }
    /**
     * @param lowercase-string $method_name_lc
     * @return array{TNamedObject, ClassLikeStorage, bool, MethodIdentifier, string}
     */
    private static function handle_regular_mixins(Class_Like_Storage $class_storage, T_Named_Object $lhs_type_part, string $method_name_lc, Codebase $codebase, Context $context, Method_Identifier $method_id, Statements_Source $source, Php_Parser\Node\Expr\Method_Call $stmt, Statements_Analyzer $statements_analyzer, string $fq_class_name, ?string $lhs_var_id): array
    {
        $naive_method_exists = false;
        foreach ($class_storage->named_mixins as $mixin) {
            if (!$class_storage->mixin_declaring_fqcln) {
                continue;
            }
            $new_method_id = new Method_Identifier($mixin->value, $method_name_lc);
            if ($codebase->methods->method_exists($new_method_id, $context->calling_method_id, $codebase->collect_locations ? new Code_Location($source, $stmt->name) : null, !$context->collect_initializations && !$context->collect_mutations ? $statements_analyzer : null, $statements_analyzer->get_file_path(), true, $context->inside_use())) {
                $mixin_declaring_class_storage = $codebase->classlike_storage_provider->get($class_storage->mixin_declaring_fqcln);
                $mixin_class_template_params = Class_Template_Param_Collector::collect($codebase, $mixin_declaring_class_storage, $codebase->classlike_storage_provider->get($fq_class_name), null, $lhs_type_part, $lhs_var_id === '$this');
                $lhs_type_part = $mixin->replace_template_types_with_arg_types(new Template_Result([], $mixin_class_template_params ?: []), $codebase);
                $lhs_type_expanded = Type_Expander::expand_union($codebase, new Union([$lhs_type_part]), $mixin_declaring_class_storage->name, $fq_class_name, $class_storage->parent_class, true, false, $class_storage->final);
                $new_lhs_type_part = $lhs_type_expanded->get_single_atomic();
                if ($new_lhs_type_part instanceof T_Named_Object) {
                    $lhs_type_part = $new_lhs_type_part;
                }
                $mixin_class_storage = $codebase->classlike_storage_provider->get($mixin->value);
                $fq_class_name = $mixin_class_storage->name;
                $mixin_class_storage->mixin_declaring_fqcln = $class_storage->mixin_declaring_fqcln;
                $class_storage = $mixin_class_storage;
                $naive_method_exists = true;
                $method_id = $new_method_id;
            }
        }
        return [$lhs_type_part, $class_storage, $naive_method_exists, $method_id, $fq_class_name];
    }
    private static function handle_callable_object(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Method_Call $stmt, Context $context, ?T_Callable $lhs_type_part_callable, Atomic_Method_Call_Analysis_Result $result, ?Template_Result $inferred_template_result = null): void
    {
        $method_id = 'object::__invoke';
        $result->existent_method_ids[$method_id] = true;
        $result->has_valid_method_call_type = true;
        if ($lhs_type_part_callable !== null && !$stmt->is_first_class_callable()) {
            $result->return_type = $lhs_type_part_callable->return_type ?? Type::get_mixed();
            $callable_argument_count = count($lhs_type_part_callable->params ?? []);
            $provided_arguments_count = count($stmt->get_args());
            if ($callable_argument_count > $provided_arguments_count) {
                $result->too_few_arguments = true;
                $result->too_few_arguments_method_ids[] = new Method_Identifier('callable-object', '__invoke');
            } elseif ($provided_arguments_count > $callable_argument_count) {
                $result->too_many_arguments = true;
                $result->too_many_arguments_method_ids[] = new Method_Identifier('callable-object', '__invoke');
            }
            $template_result = $inferred_template_result ?? new Template_Result([], []);
            Arguments_Analyzer::analyze($statements_analyzer, $stmt->get_args(), $lhs_type_part_callable->params, $method_id, false, $context, $template_result);
            Arguments_Analyzer::check_arguments_match($statements_analyzer, $stmt->get_args(), $method_id, $lhs_type_part_callable->params ?? [], null, null, $template_result, new Code_Location($statements_analyzer->get_source(), $stmt), $context);
        }
    }
}