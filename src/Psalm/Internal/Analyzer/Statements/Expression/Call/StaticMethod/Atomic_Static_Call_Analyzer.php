<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Call\Static_Method;

use Exception;
use Php_Parser;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Class_Like_Name_Options;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Analyzer\Method_Analyzer;
use Psalm\Internal\Analyzer\Namespace_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Argument_Map_Populator;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Arguments_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Method\Method_Visibility_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Method_Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Static_Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Atomic_Property_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Type\Template_Result;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Issue\Deprecated_Class;
use Psalm\Issue\Impure_Method_Call;
use Psalm\Issue\Internal_Class;
use Psalm\Issue\Invalid_String_Class;
use Psalm\Issue\Mixed_Method_Call;
use Psalm\Issue\Undefined_Class;
use Psalm\Issue\Undefined_Method;
use Psalm\Issue_Buffer;
use Psalm\Node\Expr\Virtual_Method_Call;
use Psalm\Node\Expr\Virtual_Variable;
use Psalm\Storage\Class_Like_Storage;
use Psalm\Storage\Method_Storage;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Class_String;
use Psalm\Type\Atomic\T_Closure;
use Psalm\Type\Atomic\T_Dependent_Get_Class;
use Psalm\Type\Atomic\T_Generic_Object;
use Psalm\Type\Atomic\T_Literal_Class_String;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_Numeric_String;
use Psalm\Type\Atomic\T_Object;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Union;
use function array_filter;
use function array_values;
use function assert;
use function count;
use function in_array;
use function strtolower;
/**
 * @internal
 */
final class Atomic_Static_Call_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Static_Call $stmt, Context $context, Atomic $lhs_type_part, bool $ignore_nullable_issues, bool &$moved_call, bool &$has_mock, bool &$has_existing_method, ?Template_Result $inferred_template_result = null): void
    {
        $intersection_types = [];
        if ($lhs_type_part instanceof T_Named_Object) {
            $fq_class_name = $lhs_type_part->value;
            if (!Class_Like_Analyzer::check_fully_qualified_class_like_name($statements_analyzer, $fq_class_name, new Code_Location($statements_analyzer, $stmt->class), !$context->collect_initializations && !$context->collect_mutations ? $context->self : null, !$context->collect_initializations && !$context->collect_mutations ? $context->calling_method_id : null, $statements_analyzer->get_suppressed_issues(), new Class_Like_Name_Options($stmt->class instanceof Php_Parser\Node\Name && count($stmt->class->get_parts()) === 1 && in_array(strtolower($stmt->class->get_first()), ['self', 'static'], true)))) {
                return;
            }
            $intersection_types = $lhs_type_part->extra_types;
        } elseif ($lhs_type_part instanceof T_Class_String && $lhs_type_part->as_type) {
            $fq_class_name = $lhs_type_part->as_type->value;
            if (!Class_Like_Analyzer::check_fully_qualified_class_like_name($statements_analyzer, $fq_class_name, new Code_Location($statements_analyzer, $stmt->class), $context->self, $context->calling_method_id, $statements_analyzer->get_suppressed_issues())) {
                return;
            }
            $intersection_types = $lhs_type_part->as_type->extra_types;
        } elseif ($lhs_type_part instanceof T_Dependent_Get_Class && !$lhs_type_part->as_type->has_object()) {
            $fq_class_name = 'object';
            if ($lhs_type_part->as_type->has_object_type() && $lhs_type_part->as_type->is_single()) {
                foreach ($lhs_type_part->as_type->get_atomic_types() as $typeof_type_atomic) {
                    if ($typeof_type_atomic instanceof T_Named_Object) {
                        $fq_class_name = $typeof_type_atomic->value;
                    }
                }
            }
            if ($fq_class_name === 'object') {
                return;
            }
        } elseif ($lhs_type_part instanceof T_Literal_Class_String) {
            $fq_class_name = $lhs_type_part->value;
            if (!Class_Like_Analyzer::check_fully_qualified_class_like_name($statements_analyzer, $fq_class_name, new Code_Location($statements_analyzer, $stmt->class), $context->self, $context->calling_method_id, $statements_analyzer->get_suppressed_issues())) {
                return;
            }
        } elseif ($lhs_type_part instanceof T_Template_Param && !$lhs_type_part->as->is_mixed() && !$lhs_type_part->as->has_object()) {
            $fq_class_name = null;
            foreach ($lhs_type_part->as->get_atomic_types() as $generic_param_type) {
                if (!$generic_param_type instanceof T_Named_Object) {
                    return;
                }
                $fq_class_name = $generic_param_type->value;
                break;
            }
            if (!$fq_class_name) {
                Issue_Buffer::maybe_add(new Undefined_Class('Type ' . $lhs_type_part->as . ' cannot be called as a class', new Code_Location($statements_analyzer->get_source(), $stmt), (string) $lhs_type_part), $statements_analyzer->get_suppressed_issues());
                return;
            }
        } else {
            self::handle_non_object_call($statements_analyzer, $stmt, $context, $lhs_type_part, $ignore_nullable_issues);
            return;
        }
        $codebase = $statements_analyzer->get_codebase();
        $fq_class_name = $codebase->classlikes->get_un_aliased_name($fq_class_name);
        $is_mock = Expression_Analyzer::is_mock($fq_class_name);
        $has_mock = $has_mock || $is_mock;
        if ($stmt->name instanceof Php_Parser\Node\Identifier && !$is_mock) {
            self::handle_named_call($statements_analyzer, $stmt, $stmt->name, $context, $lhs_type_part, $intersection_types ?: [], $fq_class_name, $moved_call, $has_existing_method, $inferred_template_result);
        } else {
            if ($stmt->name instanceof Php_Parser\Node\Expr) {
                $was_inside_general_use = $context->inside_general_use;
                $context->inside_general_use = true;
                Expression_Analyzer::analyze($statements_analyzer, $stmt->name, $context);
                $context->inside_general_use = $was_inside_general_use;
            }
            if (!$context->ignore_variable_method) {
                $codebase->analyzer->add_mixed_member_name(strtolower($fq_class_name) . '::', $context->calling_method_id ?: $statements_analyzer->get_file_name());
            }
            if ($stmt->is_first_class_callable()) {
                $return_type_candidate = null;
                if (!$stmt->name instanceof Php_Parser\Node\Identifier) {
                    $method_name_type = $statements_analyzer->node_data->get_type($stmt->name);
                    if ($method_name_type && $method_name_type->is_single_string_literal()) {
                        $method_identifier = new Method_Identifier($fq_class_name, strtolower($method_name_type->get_single_string_literal()->value));
                        //the call to methodExists will register that the method was called from somewhere
                        if ($codebase->methods->method_exists($method_identifier, $context->calling_method_id, null, $statements_analyzer, $statements_analyzer->get_file_path(), true, $context->inside_use())) {
                            $method_storage = $codebase->methods->get_storage($method_identifier);
                            $return_type_candidate = new Union([new T_Closure('Closure', $method_storage->params, $method_storage->return_type, $method_storage->pure)]);
                        }
                    }
                }
                $statements_analyzer->node_data->set_type($stmt, $return_type_candidate ?? Type::get_closure());
                return;
            }
            if (Arguments_Analyzer::analyze($statements_analyzer, $stmt->get_args(), null, null, true, $context) === false) {
                return;
            }
        }
        if ($codebase->alter_code && $fq_class_name && !$moved_call && $stmt->class instanceof Php_Parser\Node\Name && !in_array($stmt->class->get_first(), ['parent', 'static'])) {
            $codebase->classlikes->handle_class_like_reference_in_migration($codebase, $statements_analyzer, $stmt->class, $fq_class_name, $context->calling_method_id, false, $stmt->class->get_first() === 'self');
        }
    }
    /**
     * @psalm-suppress UnusedReturnValue not used but seems important
     * @psalm-suppress ComplexMethod to be refactored
     */
    private static function handle_named_call(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Static_Call $stmt, Php_Parser\Node\Identifier $stmt_name, Context $context, Atomic $lhs_type_part, array $intersection_types, string $fq_class_name, bool &$moved_call, bool &$has_existing_method, ?Template_Result $inferred_template_result = null): bool
    {
        $codebase = $statements_analyzer->get_codebase();
        $method_name_lc = strtolower($stmt_name->name);
        $method_id = new Method_Identifier($fq_class_name, $method_name_lc);
        $cased_method_id = $fq_class_name . '::' . $stmt_name->name;
        if ($codebase->store_node_types && !$stmt->is_first_class_callable() && !$context->collect_initializations && !$context->collect_mutations) {
            Argument_Map_Populator::record_argument_positions($statements_analyzer, $stmt, $codebase, (string) $method_id);
        }
        if ($intersection_types && !$codebase->methods->method_exists($method_id)) {
            foreach ($intersection_types as $intersection_type) {
                if (!$intersection_type instanceof T_Named_Object) {
                    continue;
                }
                $intersection_method_id = new Method_Identifier($intersection_type->value, $method_name_lc);
                if ($codebase->methods->method_exists($intersection_method_id)) {
                    $method_id = $intersection_method_id;
                    $cased_method_id = $intersection_type->value . '::' . $stmt_name->name;
                    $fq_class_name = $intersection_type->value;
                    break;
                }
            }
        }
        $class_storage = $codebase->classlike_storage_provider->get($fq_class_name);
        $naive_method_exists = $codebase->methods->method_exists($method_id, !$context->collect_initializations && !$context->collect_mutations ? $context->calling_method_id : null, $codebase->collect_locations ? new Code_Location($statements_analyzer, $stmt_name) : null, $statements_analyzer, $statements_analyzer->get_file_path(), false, $context->inside_use());
        $fake_method_exists = false;
        if (!$naive_method_exists && $codebase->methods->existence_provider->has($fq_class_name)) {
            $fake_method_exists = $codebase->methods->existence_provider->does_method_exist($fq_class_name, $method_id->method_name, $statements_analyzer) ?? false;
        }
        $args = $stmt->is_first_class_callable() ? [] : $stmt->get_args();
        if (!$naive_method_exists && $class_storage->mixin_declaring_fqcln && $class_storage->named_mixins) {
            foreach ($class_storage->named_mixins as $mixin) {
                $new_method_id = new Method_Identifier($mixin->value, $method_name_lc);
                if ($codebase->methods->method_exists($new_method_id, $context->calling_method_id, $codebase->collect_locations ? new Code_Location($statements_analyzer, $stmt_name) : null, !$context->collect_initializations && !$context->collect_mutations ? $statements_analyzer : null, $statements_analyzer->get_file_path(), true, $context->inside_use())) {
                    $mixin_candidates = [];
                    foreach ($class_storage->templated_mixins as $mixin_candidate) {
                        $mixin_candidates[] = $mixin_candidate;
                    }
                    foreach ($class_storage->named_mixins as $mixin_candidate) {
                        $mixin_candidates[] = $mixin_candidate;
                    }
                    $mixin_candidates_no_generic = array_filter($mixin_candidates, static fn(Atomic $check): bool => !$check instanceof T_Generic_Object);
                    // $mixin_candidates_no_generic will only be empty when there are TGenericObject entries.
                    // In that case, Union will be initialized with an empty array but
                    // replaced with non-empty types in the following loop.
                    /** @psalm-suppress ArgumentTypeCoercion */
                    $mixin_candidate_type = new Union($mixin_candidates_no_generic);
                    foreach ($mixin_candidates as $t_generic_mixin) {
                        if (!$t_generic_mixin instanceof T_Generic_Object) {
                            continue;
                        }
                        $mixin_declaring_class_storage = $codebase->classlike_storage_provider->get($class_storage->mixin_declaring_fqcln);
                        $new_mixin_candidate_type = Atomic_Property_Fetch_Analyzer::localize_property_type($codebase, new Union([$lhs_type_part]), $t_generic_mixin, $class_storage, $mixin_declaring_class_storage)->get_builder();
                        foreach ($mixin_candidate_type->get_atomic_types() as $type) {
                            $new_mixin_candidate_type->add_type($type);
                        }
                        $mixin_candidate_type = $new_mixin_candidate_type->freeze();
                    }
                    $new_lhs_type = Type_Expander::expand_union($codebase, $mixin_candidate_type, $fq_class_name, $fq_class_name, $class_storage->parent_class, true, false, $class_storage->final);
                    $mixin_context = clone $context;
                    $mixin_context->vars_in_scope['$__tmp_mixin_var__'] = $new_lhs_type;
                    return self::forward_call_to_instance_method($statements_analyzer, $stmt, $stmt_name, $mixin_context, '__tmp_mixin_var__', true);
                }
            }
        }
        $config = $codebase->config;
        $found_method_and_class_storage = self::find_pseudo_method_and_class_storages($codebase, $class_storage, $method_name_lc);
        if ($stmt->is_first_class_callable()) {
            if ($found_method_and_class_storage) {
                [$method_storage] = $found_method_and_class_storage;
                $return_type_candidate = new Union([new T_Closure('Closure', $method_storage->params, $method_storage->return_type, $method_storage->pure)]);
            } else {
                $method_exists = $naive_method_exists || $fake_method_exists || isset($class_storage->methods[$method_name_lc]) || isset($class_storage->pseudo_static_methods[$method_name_lc]);
                if ($method_exists) {
                    $declaring_method_id = $codebase->methods->get_declaring_method_id($method_id) ?? $method_id;
                    $return_type_candidate = new Union([new T_Closure('Closure', array_values($codebase->get_method_params($method_id)), $codebase->get_method_return_type($method_id, $fq_class_name), $codebase->methods->get_storage($declaring_method_id)->pure)]);
                } elseif ($codebase->method_exists($call_static_method_id = new Method_Identifier($method_id->fq_class_name, '__callstatic'), new Code_Location($statements_analyzer, $stmt), null, null, false)) {
                    $return_type_candidate = new Union([new T_Closure('Closure', null, $codebase->get_method_return_type($call_static_method_id, $fq_class_name), $codebase->methods->get_storage($call_static_method_id)->pure)]);
                } else {
                    if (Issue_Buffer::accepts(new Undefined_Method('Method ' . $method_id . ' does not exist', new Code_Location($statements_analyzer, $stmt), (string) $method_id), $statements_analyzer->get_suppressed_issues())) {
                        return false;
                    }
                    $return_type_candidate = Type::get_closure();
                }
            }
            $expanded_return_type = Type_Expander::expand_union($codebase, $return_type_candidate, $context->self, $class_storage->name, $context->parent, true, false, true);
            $statements_analyzer->node_data->set_type($stmt, $expanded_return_type);
            return true;
        }
        $callstatic_id = new Method_Identifier($fq_class_name, '__callstatic');
        $callstatic_method_exists = $codebase->methods->method_exists($callstatic_id);
        $with_pseudo = $callstatic_method_exists || $codebase->config->use_phpdoc_method_without_magic_or_parent;
        if ($codebase->methods->get_declaring_method_id($method_id, $with_pseudo)) {
            if ((!$stmt->class instanceof Php_Parser\Node\Name || $stmt->class->get_first() !== 'parent' || $statements_analyzer->is_static()) && (!$context->self || $statements_analyzer->is_static() || !$codebase->class_extends($context->self, $fq_class_name))) {
                Method_Analyzer::check_static($method_id, $stmt->class instanceof Php_Parser\Node\Name && strtolower($stmt->class->get_first()) === 'self' || $context->self === $fq_class_name, !$statements_analyzer->is_static(), $codebase, new Code_Location($statements_analyzer, $stmt), $statements_analyzer->get_suppressed_issues(), $is_dynamic_this_method);
                if ($is_dynamic_this_method) {
                    return self::forward_call_to_instance_method($statements_analyzer, $stmt, $stmt_name, $context);
                }
            }
        }
        if (!$naive_method_exists || !Method_Analyzer::is_method_visible($method_id, $context, $statements_analyzer->get_source()) || $fake_method_exists || $found_method_and_class_storage) {
            if ($callstatic_method_exists) {
                $callstatic_declaring_id = $codebase->methods->get_declaring_method_id($callstatic_id);
                assert($callstatic_declaring_id !== null);
                $callstatic_pure = false;
                $callstatic_mutation_free = false;
                if ($codebase->methods->has_storage($callstatic_declaring_id)) {
                    $callstatic_storage = $codebase->methods->get_storage($callstatic_declaring_id);
                    $callstatic_pure = $callstatic_storage->pure;
                    $callstatic_mutation_free = $callstatic_storage->mutation_free;
                }
                if ($codebase->methods->return_type_provider->has($fq_class_name)) {
                    $return_type_candidate = $codebase->methods->return_type_provider->get_return_type($statements_analyzer, $method_id->fq_class_name, $method_id->method_name, $stmt, $context, new Code_Location($statements_analyzer->get_source(), $stmt_name), null, null, strtolower($stmt_name->name));
                    if ($return_type_candidate) {
                        Call_Analyzer::check_method_args($method_id, $stmt->get_args(), new Template_Result([], []), $context, new Code_Location($statements_analyzer->get_source(), $stmt), $statements_analyzer);
                        $statements_analyzer->node_data->set_type($stmt, $return_type_candidate);
                        return true;
                    }
                }
                if ($found_method_and_class_storage) {
                    [$pseudo_method_storage, $defining_class_storage] = $found_method_and_class_storage;
                    if (self::check_pseudo_method($statements_analyzer, $stmt, $method_id, $fq_class_name, $args, $defining_class_storage, $pseudo_method_storage, $context) === false) {
                        return false;
                    }
                    if (!$context->inside_throw) {
                        if ($context->pure && !$callstatic_pure) {
                            Issue_Buffer::maybe_add(new Impure_Method_Call('Cannot call an impure method from a pure context', new Code_Location($statements_analyzer, $stmt_name)), $statements_analyzer->get_suppressed_issues());
                        } elseif ($context->mutation_free && !$callstatic_mutation_free) {
                            Issue_Buffer::maybe_add(new Impure_Method_Call('Cannot call a possibly-mutating method from a mutation-free context', new Code_Location($statements_analyzer, $stmt_name)), $statements_analyzer->get_suppressed_issues());
                        } elseif ($statements_analyzer->get_source() instanceof Function_Like_Analyzer && $statements_analyzer->get_source()->track_mutations && !$callstatic_pure) {
                            if (!$callstatic_mutation_free) {
                                $statements_analyzer->get_source()->inferred_has_mutation = true;
                            }
                            $statements_analyzer->get_source()->inferred_impure = true;
                        }
                    }
                    if ($pseudo_method_storage->return_type) {
                        return true;
                    }
                } else if (Arguments_Analyzer::analyze($statements_analyzer, $args, null, null, true, $context) === false) {
                    return false;
                }
            } elseif ($found_method_and_class_storage && ($naive_method_exists || $with_pseudo)) {
                [$pseudo_method_storage, $defining_class_storage] = $found_method_and_class_storage;
                if (self::check_pseudo_method($statements_analyzer, $stmt, $method_id, $fq_class_name, $args, $defining_class_storage, $pseudo_method_storage, $context) === false) {
                    return false;
                }
                if ($pseudo_method_storage->return_type) {
                    return true;
                }
            } elseif ($stmt->class instanceof Php_Parser\Node\Name && $stmt->class->get_first() === 'parent' && !$codebase->method_exists($method_id) && !$statements_analyzer->is_static()) {
                // In case of parent::xxx() call on instance method context (i.e. not static context)
                // with nonexistent method, we try to forward to instance method call for resolve pseudo method.
                // Use parent type as static type for the method call
                $tmp_context = clone $context;
                $tmp_context->vars_in_scope['$__tmp_parent_var__'] = new Union([$lhs_type_part]);
                if (self::forward_call_to_instance_method($statements_analyzer, $stmt, $stmt_name, $tmp_context, '__tmp_parent_var__') === false) {
                    return false;
                }
                unset($tmp_context);
                // Resolve actual static return type according to caller (i.e. $this) static type
                if (isset($context->vars_in_scope['$this']) && $method_call_type = $statements_analyzer->node_data->get_type($stmt)) {
                    $method_call_type = $method_call_type->get_builder();
                    foreach ($method_call_type->get_atomic_types() as $name => $type) {
                        if ($type instanceof T_Named_Object && $type->is_static && $type->value === $fq_class_name) {
                            // Replace parent&static type to actual static type
                            $method_call_type->remove_type($name);
                            $method_call_type->add_type($context->vars_in_scope['$this']->get_single_atomic());
                        }
                    }
                    $statements_analyzer->node_data->set_type($stmt, $method_call_type->freeze());
                }
                return true;
            }
            if (!$context->check_methods) {
                if (Arguments_Analyzer::analyze($statements_analyzer, $stmt->get_args(), null, null, true, $context) === false) {
                    return false;
                }
                return true;
            }
        }
        if ($naive_method_exists || !$callstatic_method_exists || $class_storage->has_sealed_methods($config)) {
            $does_method_exist = Method_Analyzer::check_method_exists($codebase, $method_id, new Code_Location($statements_analyzer, $stmt), $statements_analyzer->get_suppressed_issues(), $context->calling_method_id, $with_pseudo);
        } else {
            $does_method_exist = null;
        }
        if (!$does_method_exist) {
            if (Arguments_Analyzer::analyze($statements_analyzer, $stmt->get_args(), null, null, true, $context) === false) {
                return false;
            }
            if ($codebase->alter_code && $fq_class_name && !$moved_call) {
                $codebase->classlikes->handle_class_like_reference_in_migration($codebase, $statements_analyzer, $stmt->class, $fq_class_name, $context->calling_method_id);
            }
            return true;
        }
        $class_storage = $codebase->classlike_storage_provider->get($fq_class_name);
        if ($class_storage->deprecated && $fq_class_name !== $context->self) {
            Issue_Buffer::maybe_add(new Deprecated_Class($fq_class_name . ' is marked deprecated', new Code_Location($statements_analyzer->get_source(), $stmt), $fq_class_name), $statements_analyzer->get_suppressed_issues());
        }
        if ($context->self && !Namespace_Analyzer::is_within_any($context->self, $class_storage->internal)) {
            Issue_Buffer::maybe_add(new Internal_Class($fq_class_name . ' is internal to ' . Internal_Class::list_to_phrase($class_storage->internal) . ' but called from ' . $context->self, new Code_Location($statements_analyzer->get_source(), $stmt), $fq_class_name), $statements_analyzer->get_suppressed_issues());
        }
        if (Method_Visibility_Analyzer::analyze($method_id, $context, $statements_analyzer->get_source(), new Code_Location($statements_analyzer, $stmt), $statements_analyzer->get_suppressed_issues()) === false) {
            return false;
        }
        $has_existing_method = true;
        Existing_Atomic_Static_Call_Analyzer::analyze($statements_analyzer, $stmt, $stmt_name, $args, $context, $lhs_type_part, $method_id, $cased_method_id, $class_storage, $moved_call, $inferred_template_result);
        return true;
    }
    /**
     * @param  list<PhpParser\Node\Arg> $args
     * @return false|null
     */
    private static function check_pseudo_method(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Static_Call $stmt, Method_Identifier $method_id, string $static_fq_class_name, array $args, Class_Like_Storage $class_storage, Method_Storage $pseudo_method_storage, Context $context): ?bool
    {
        if (Arguments_Analyzer::analyze($statements_analyzer, $args, $pseudo_method_storage->params, (string) $method_id, true, $context) === false) {
            return false;
        }
        $codebase = $statements_analyzer->get_codebase();
        if (Arguments_Analyzer::check_arguments_match($statements_analyzer, $args, $method_id, $pseudo_method_storage->params, $pseudo_method_storage, null, new Template_Result([], []), new Code_Location($statements_analyzer, $stmt), $context) === false) {
            return false;
        }
        $method_storage = null;
        if ($statements_analyzer->data_flow_graph) {
            try {
                $method_storage = $codebase->methods->get_storage($method_id);
                Arguments_Analyzer::analyze($statements_analyzer, $args, $method_storage->params, (string) $method_id, true, $context);
                Arguments_Analyzer::check_arguments_match($statements_analyzer, $args, $method_id, $method_storage->params, $method_storage, null, new Template_Result([], []), new Code_Location($statements_analyzer, $stmt), $context);
            } catch (Exception) {
                // do nothing
            }
        }
        if ($pseudo_method_storage->return_type) {
            $return_type_candidate = $pseudo_method_storage->return_type;
            $return_type_candidate = Type_Expander::expand_union($statements_analyzer->get_codebase(), $return_type_candidate, $class_storage->name, $static_fq_class_name, $class_storage->parent_class);
            if ($method_storage) {
                Static_Call_Analyzer::taint_return_type($statements_analyzer, $stmt, $method_id, (string) $method_id, $return_type_candidate, $method_storage, null, $context);
            }
            $stmt_type = $statements_analyzer->node_data->get_type($stmt);
            $statements_analyzer->node_data->set_type($stmt, Type::combine_union_types($return_type_candidate, $stmt_type));
        }
        return null;
    }
    public static function handle_non_object_call(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Static_Call $stmt, Context $context, Atomic $lhs_type_part, bool $ignore_nullable_issues): void
    {
        $codebase = $statements_analyzer->get_codebase();
        $config = $codebase->config;
        if ($lhs_type_part instanceof T_Mixed || $lhs_type_part instanceof T_Template_Param || $lhs_type_part instanceof T_Class_String || $lhs_type_part instanceof T_Object) {
            if ($stmt->name instanceof Php_Parser\Node\Identifier) {
                $codebase->analyzer->add_mixed_member_name(strtolower($stmt->name->name), $context->calling_method_id ?: $statements_analyzer->get_file_name());
            }
            Issue_Buffer::maybe_add(new Mixed_Method_Call('Cannot call method on an unknown class', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
            return;
        }
        if ($lhs_type_part instanceof T_String) {
            if ($config->allow_string_standin_for_class && !$lhs_type_part instanceof T_Numeric_String) {
                return;
            }
            Issue_Buffer::maybe_add(new Invalid_String_Class('String cannot be used as a class', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
            return;
        }
        if ($lhs_type_part instanceof T_Null && $ignore_nullable_issues) {
            return;
        }
        Issue_Buffer::maybe_add(new Undefined_Class('Type ' . $lhs_type_part . ' cannot be called as a class', new Code_Location($statements_analyzer->get_source(), $stmt), (string) $lhs_type_part), $statements_analyzer->get_suppressed_issues());
    }
    /**
     * Try to find matching pseudo method over ancestors (including interfaces).
     *
     * Returns the pseudo method if exists, with its defining class storage.
     * If the method is not declared, null is returned.
     *
     * @param ClassLikeStorage $static_class_storage The called class
     * @param lowercase-string $method_name_lc
     * @return array{MethodStorage, ClassLikeStorage}|null
     */
    private static function find_pseudo_method_and_class_storages(Codebase $codebase, Class_Like_Storage $static_class_storage, string $method_name_lc): ?array
    {
        if ($pseudo_method_storage = $static_class_storage->pseudo_static_methods[$method_name_lc] ?? null) {
            return [$pseudo_method_storage, $static_class_storage];
        }
        $ancestors = $static_class_storage->class_implements + $static_class_storage->parent_classes;
        foreach ($ancestors as $fq_class_name => $_) {
            $class_storage = $codebase->classlikes->get_storage_for($fq_class_name);
            if ($class_storage && isset($class_storage->pseudo_static_methods[$method_name_lc])) {
                return [$class_storage->pseudo_static_methods[$method_name_lc], $class_storage];
            }
        }
        return null;
    }
    /**
     * Forward static call to instance call, using `VirtualMethodCall` and `MethodCallAnalyzer::analyze()`
     * The resolved method return type will be set as type of the $stmt node.
     *
     * @param string $virtual_var_name Temporary var name to use for create the fake MethodCall statement.
     * @param bool $always_set_node_type If true, when the method has no declared typed, mixed will be set on node.
     * @return bool Result of analysis. False if the call is invalid.
     * @see MethodCallAnalyzer::analyze()
     */
    private static function forward_call_to_instance_method(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Static_Call $stmt, Php_Parser\Node\Identifier $stmt_name, Context $context, string $virtual_var_name = 'this', bool $always_set_node_type = false): bool
    {
        $old_data_provider = $statements_analyzer->node_data;
        $statements_analyzer->node_data = clone $statements_analyzer->node_data;
        $fake_method_call_expr = new Virtual_Method_Call(new Virtual_Variable($virtual_var_name, $stmt->class->get_attributes()), $stmt_name, $stmt->get_args(), $stmt->get_attributes());
        if (Method_Call_Analyzer::analyze($statements_analyzer, $fake_method_call_expr, $context) === false) {
            return false;
        }
        $fake_method_call_type = $statements_analyzer->node_data->get_type($fake_method_call_expr);
        $statements_analyzer->node_data = $old_data_provider;
        if ($fake_method_call_type) {
            $statements_analyzer->node_data->set_type($stmt, $fake_method_call_type);
        } elseif ($always_set_node_type) {
            $statements_analyzer->node_data->set_type($stmt, Type::get_mixed());
        }
        return true;
    }
}