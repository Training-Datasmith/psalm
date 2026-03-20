<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Call\Method;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\File_Manipulation;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Argument_Map_Populator;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Class_Template_Param_Collector;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Function_Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Expression_Identifier;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Analyzer\Trait_Analyzer;
use Psalm\Internal\Codebase\Assertions_From_Inheritance_Resolver;
use Psalm\Internal\Codebase\Internal_Call_Map_Handler;
use Psalm\Internal\File_Manipulation\File_Manipulation_Buffer;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Type\Comparator\Type_Comparison_Result;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Internal\Type\Template_Inferred_Type_Replacer;
use Psalm\Internal\Type\Template_Result;
use Psalm\Internal\Type\Template_Standin_Type_Replacer;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Issue\If_This_Is_Mismatch;
use Psalm\Issue\Invalid_Property_Assignment_Value;
use Psalm\Issue\Mixed_Property_Type_Coercion;
use Psalm\Issue\Possibly_Invalid_Property_Assignment_Value;
use Psalm\Issue\Property_Type_Coercion;
use Psalm\Issue\Undefined_This_Property_Assignment;
use Psalm\Issue\Undefined_This_Property_Fetch;
use Psalm\Issue_Buffer;
use Psalm\Node\Expr\Virtual_Func_Call;
use Psalm\Plugin\Event_Handler\Event\After_Method_Call_Analysis_Event;
use Psalm\Storage\Possibilities;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_filter;
use function array_map;
use function count;
use function explode;
use function in_array;
use function is_string;
use function str_starts_with;
use function strtolower;
/**
 * @internal
 */
final class Existing_Atomic_Method_Call_Analyzer extends Call_Analyzer
{
    /**
     * @param  TNamedObject|TTemplateParam|null  $static_type
     * @param  list<PhpParser\Node\Arg> $args
     */
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Method_Call $stmt, Php_Parser\Node\Identifier $stmt_name, array $args, Codebase $codebase, Context $context, T_Named_Object $lhs_type_part, ?Atomic $static_type, ?string $lhs_var_id, Method_Identifier $method_id, Atomic_Method_Call_Analysis_Result $result, ?Template_Result $inferred_template_result = null): Union
    {
        $config = $codebase->config;
        $fq_class_name = $lhs_type_part->value;
        if ($fq_class_name === 'static') {
            $fq_class_name = (string) $context->self;
        }
        $method_name_lc = $method_id->method_name;
        $cased_method_id = $fq_class_name . '::' . $stmt_name->name;
        $result->existent_method_ids[$method_id->__toString()] = true;
        if ($context->collect_initializations && $context->calling_method_id) {
            [$calling_method_class] = explode('::', $context->calling_method_id);
            $codebase->file_reference_provider->add_method_reference_to_class_member($calling_method_class . '::__construct', strtolower((string) $method_id), false);
        }
        if ($codebase->store_node_types && !$stmt->is_first_class_callable() && !$context->collect_initializations && !$context->collect_mutations) {
            Argument_Map_Populator::record_argument_positions($statements_analyzer, $stmt, $codebase, (string) $method_id);
        }
        if ($fq_class_name === 'Closure' && $method_name_lc === '__invoke') {
            $statements_analyzer->node_data = clone $statements_analyzer->node_data;
            $fake_function_call = new Virtual_Func_Call($stmt->var, $args, $stmt->get_attributes());
            Function_Call_Analyzer::analyze($statements_analyzer, $fake_function_call, $context);
            return $statements_analyzer->node_data->get_type($fake_function_call) ?? Type::get_mixed();
        }
        $source = $statements_analyzer->get_source();
        if ($codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations) {
            $codebase->analyzer->add_node_reference($statements_analyzer->get_file_path(), $stmt_name, $method_id . '()');
        }
        if ($context->collect_initializations && $context->calling_method_id) {
            [$calling_method_class] = explode('::', $context->calling_method_id);
            $codebase->file_reference_provider->add_method_reference_to_class_member($calling_method_class . '::__construct', strtolower((string) $method_id), false);
        }
        if ($stmt->var instanceof Php_Parser\Node\Expr\Variable && ($context->collect_initializations || $context->collect_mutations) && $stmt->var->name === 'this' && $source instanceof Function_Like_Analyzer) {
            self::collect_special_information($source, $stmt_name->name, $context);
        }
        $fq_class_name = $codebase->classlikes->get_un_aliased_name($fq_class_name);
        $class_storage = $codebase->classlike_storage_provider->get($fq_class_name);
        $parent_source = $statements_analyzer->get_source();
        $class_template_params = Class_Template_Param_Collector::collect($codebase, $codebase->methods->get_class_like_storage_for_method($method_id), $class_storage, $method_name_lc, $lhs_type_part, $lhs_var_id === '$this');
        if ($lhs_var_id === '$this' && $parent_source instanceof Function_Like_Analyzer) {
            $grandparent_source = $parent_source->get_source();
            if ($grandparent_source instanceof Trait_Analyzer) {
                $fq_trait_name = $grandparent_source->get_fqcln();
                $fq_trait_name_lc = strtolower($fq_trait_name);
                $trait_storage = $codebase->classlike_storage_provider->get($fq_trait_name_lc);
                if (isset($trait_storage->methods[$method_name_lc])) {
                    $trait_method_id = new Method_Identifier($trait_storage->name, $method_name_lc);
                    $class_template_params = Class_Template_Param_Collector::collect($codebase, $codebase->methods->get_class_like_storage_for_method($trait_method_id), $class_storage, $method_name_lc, $lhs_type_part, true);
                }
            }
        }
        $declaring_method_id = $codebase->methods->get_declaring_method_id($method_id);
        try {
            $method_storage = $codebase->methods->get_storage($declaring_method_id ?? $method_id);
        } catch (UnexpectedValueException) {
            $method_storage = null;
        }
        $method_template_params = [];
        if ($method_storage && $method_storage->if_this_is_type) {
            $method_template_result = new Template_Result($method_storage->template_types ?: [], []);
            Template_Standin_Type_Replacer::fill_template_result($method_storage->if_this_is_type, $method_template_result, $codebase, null, new Union([$lhs_type_part]));
            $method_template_params = $method_template_result->lower_bounds;
        }
        $template_result = new Template_Result([], $class_template_params ?: []);
        $template_result->lower_bounds += $method_template_params;
        if ($inferred_template_result) {
            $template_result->lower_bounds += $inferred_template_result->lower_bounds;
        }
        if ($method_storage && $method_storage->template_types) {
            $template_result->template_types += $method_storage->template_types;
        }
        if ($codebase->store_node_types && !$stmt->is_first_class_callable() && !$context->collect_initializations && !$context->collect_mutations) {
            Argument_Map_Populator::record_argument_positions($statements_analyzer, $stmt, $codebase, (string) $method_id);
        }
        $is_first_class_callable = $stmt->is_first_class_callable();
        if (!$is_first_class_callable && self::check_method_args($method_id, $args, $template_result, $context, new Code_Location($source, $stmt_name), $statements_analyzer) === false) {
            return Type::get_mixed();
        }
        $return_type_candidate = Method_Call_Return_Type_Fetcher::fetch($statements_analyzer, $codebase, $stmt, $context, $method_id, $declaring_method_id, $method_id, $cased_method_id, $lhs_type_part, $static_type, $args, $result, $template_result);
        if ($is_first_class_callable) {
            return $return_type_candidate;
        }
        $in_call_map = Internal_Call_Map_Handler::in_call_map((string) ($declaring_method_id ?? $method_id));
        if (!$in_call_map) {
            $name_code_location = new Code_Location($statements_analyzer, $stmt_name);
            Method_Call_Prohibition_Analyzer::analyze($codebase, $context, $method_id, $statements_analyzer->get_fully_qualified_function_method_or_namespace_name(), $name_code_location, $statements_analyzer->get_suppressed_issues());
            $getter_return_type = self::get_magic_getter_or_setter_property($statements_analyzer, $stmt, $stmt_name, $context, $fq_class_name);
            if ($getter_return_type) {
                $return_type_candidate = $getter_return_type;
            }
        }
        if ($method_storage) {
            if ($method_storage->if_this_is_type) {
                $class_type = new Union([$lhs_type_part]);
                $if_this_is_type = Template_Inferred_Type_Replacer::replace($method_storage->if_this_is_type, $template_result, $codebase);
                if (!Union_Type_Comparator::is_contained_by($codebase, $class_type, $if_this_is_type)) {
                    Issue_Buffer::maybe_add(new If_This_Is_Mismatch('Class type must be ' . $method_storage->if_this_is_type->get_id() . ' current type ' . $class_type->get_id(), new Code_Location($source, $stmt->name)), $statements_analyzer->get_suppressed_issues());
                }
            }
            if ($method_storage->self_out_type && $lhs_var_id) {
                $self_out_candidate = $method_storage->self_out_type;
                if ($template_result->lower_bounds) {
                    $self_out_candidate = Type_Expander::expand_union($codebase, $self_out_candidate, $fq_class_name, null, $class_storage->parent_class, true, false, $static_type instanceof T_Named_Object && $codebase->classlike_storage_provider->get($static_type->value)->final, true);
                }
                $self_out_candidate = Method_Call_Return_Type_Fetcher::replace_template_types($self_out_candidate, $template_result, $method_id, count($args), $codebase);
                $self_out_candidate = Type_Expander::expand_union($codebase, $self_out_candidate, $fq_class_name, $static_type, $class_storage->parent_class, true, false, $static_type instanceof T_Named_Object && $codebase->classlike_storage_provider->get($static_type->value)->final, true);
                $context->vars_in_scope[$lhs_var_id] = $self_out_candidate;
            }
            if (!$context->collect_mutations && !$context->collect_initializations) {
                Method_Call_Purity_Analyzer::analyze($statements_analyzer, $codebase, $stmt, $lhs_var_id, $cased_method_id, $method_id, $method_storage, $class_storage, $context, $config, $result);
            }
            $has_packed_arg = false;
            foreach ($args as $arg) {
                $has_packed_arg = $has_packed_arg || $arg->unpack;
            }
            if (!$has_packed_arg) {
                $has_variadic_param = $method_storage->variadic;
                foreach ($method_storage->params as $param) {
                    $has_variadic_param = $has_variadic_param || $param->is_variadic;
                }
                for ($i = count($args), $j = count($method_storage->params); $i < $j; ++$i) {
                    $param = $method_storage->params[$i];
                    if (!$param->is_optional && !$param->is_variadic && !$in_call_map) {
                        $result->too_few_arguments = true;
                        $result->too_few_arguments_method_ids[] = $declaring_method_id ?? $method_id;
                    }
                }
                if ($has_variadic_param || count($method_storage->params) >= count($args) || $in_call_map) {
                    $result->too_many_arguments = false;
                } else {
                    $result->too_many_arguments_method_ids[] = $declaring_method_id ?? $method_id;
                }
            }
            $assertions_resolver = new Assertions_From_Inheritance_Resolver($codebase);
            $assertions = $assertions_resolver->resolve($method_storage, $class_storage);
            if ($assertions) {
                self::apply_assertions_to_context($stmt_name, Expression_Identifier::get_extended_var_id($stmt->var, null, $statements_analyzer), $assertions, $args, $template_result, $context, $statements_analyzer);
            }
            if ($method_storage->if_true_assertions) {
                $possibilities = array_map(static fn(Possibilities $assertion): Possibilities => $assertion->get_untemplated_copy($template_result, $lhs_var_id, $codebase), $method_storage->if_true_assertions);
                if ($lhs_var_id === null) {
                    $possibilities = array_filter($possibilities, static fn(Possibilities $assertion): bool => !(is_string($assertion->var_id) && str_starts_with($assertion->var_id, '$this->')));
                }
                $statements_analyzer->node_data->set_if_true_assertions($stmt, $possibilities);
            }
            if ($method_storage->if_false_assertions) {
                $possibilities = array_map(static fn(Possibilities $assertion): Possibilities => $assertion->get_untemplated_copy($template_result, $lhs_var_id, $codebase), $method_storage->if_false_assertions);
                if ($lhs_var_id === null) {
                    $possibilities = array_filter($possibilities, static fn(Possibilities $assertion): bool => !(is_string($assertion->var_id) && str_starts_with($assertion->var_id, '$this->')));
                }
                $statements_analyzer->node_data->set_if_false_assertions($stmt, $possibilities);
            }
        }
        if ($codebase->methods_to_rename) {
            $declaring_method_id = $codebase->methods->get_declaring_method_id($method_id);
            foreach ($codebase->methods_to_rename as $original_method_id => $new_method_name) {
                if ($declaring_method_id && strtolower((string) $declaring_method_id) === $original_method_id) {
                    $file_manipulations = [new File_Manipulation((int) $stmt_name->get_attribute('startFilePos'), (int) $stmt_name->get_attribute('endFilePos') + 1, $new_method_name)];
                    File_Manipulation_Buffer::add($statements_analyzer->get_file_path(), $file_manipulations);
                }
            }
        }
        if ($config->event_dispatcher->has_after_method_call_analysis_handlers()) {
            $file_manipulations = [];
            $appearing_method_id = $codebase->methods->get_appearing_method_id($method_id);
            $declaring_method_id = $codebase->methods->get_declaring_method_id($method_id);
            if ($appearing_method_id !== null && $declaring_method_id !== null) {
                $event = new After_Method_Call_Analysis_Event($stmt, (string) $method_id, (string) $appearing_method_id, (string) $declaring_method_id, $context, $statements_analyzer, $codebase, $file_manipulations, $return_type_candidate);
                $config->event_dispatcher->dispatch_after_method_call_analysis($event);
                $file_manipulations = $event->get_file_replacements();
                $return_type_candidate = $event->get_return_type_candidate();
            }
            if ($file_manipulations) {
                File_Manipulation_Buffer::add($statements_analyzer->get_file_path(), $file_manipulations);
            }
        }
        return $return_type_candidate ?? Type::get_mixed();
    }
    /**
     * Check properties accessed with magic getters and setters.
     * If `@psalm-seal-properties` is set, they must be defined.
     * If an `@property` annotation is specified, the setter must set something with the correct
     * type.
     */
    private static function get_magic_getter_or_setter_property(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Method_Call $stmt, Php_Parser\Node\Identifier $stmt_name, Context $context, string $fq_class_name): ?Union
    {
        $method_name = strtolower($stmt_name->name);
        if (!in_array($method_name, ['__get', '__set'], true)) {
            return null;
        }
        $codebase = $statements_analyzer->get_codebase();
        $first_arg_value = $stmt->get_args()[0]->value ?? null;
        if (!$first_arg_value instanceof Php_Parser\Node\Scalar\String_) {
            return null;
        }
        $prop_name = $first_arg_value->value;
        $property_id = $fq_class_name . '::$' . $prop_name;
        $class_storage = $codebase->classlike_storage_provider->get($fq_class_name);
        $codebase->properties->property_exists($property_id, $method_name === '__get', $statements_analyzer, $context, new Code_Location($statements_analyzer->get_source(), $stmt));
        switch ($method_name) {
            case '__set':
                // If `@psalm-seal-properties` is set, the property must be defined with
                // a `@property` annotation
                if ($class_storage->has_sealed_properties($codebase->config) && !isset($class_storage->pseudo_property_set_types['$' . $prop_name])) {
                    Issue_Buffer::maybe_add(new Undefined_This_Property_Assignment('Instance property ' . $property_id . ' is not defined', new Code_Location($statements_analyzer->get_source(), $stmt), $property_id), $statements_analyzer->get_suppressed_issues());
                }
                // If a `@property` annotation is set, the type of the value passed to the
                // magic setter must match the annotation.
                $second_arg_type = isset($stmt->get_args()[1]) ? $statements_analyzer->node_data->get_type($stmt->get_args()[1]->value) : null;
                if (isset($class_storage->pseudo_property_set_types['$' . $prop_name]) && $second_arg_type) {
                    $pseudo_set_type = Type_Expander::expand_union($codebase, $class_storage->pseudo_property_set_types['$' . $prop_name], $fq_class_name, new T_Named_Object($fq_class_name), $class_storage->parent_class);
                    $union_comparison_results = new Type_Comparison_Result();
                    $type_match_found = Union_Type_Comparator::is_contained_by($codebase, $second_arg_type, $pseudo_set_type, $second_arg_type->ignore_nullable_issues, $second_arg_type->ignore_falsable_issues, $union_comparison_results);
                    if ($union_comparison_results->type_coerced) {
                        if ($union_comparison_results->type_coerced_from_mixed) {
                            Issue_Buffer::maybe_add(new Mixed_Property_Type_Coercion($prop_name . ' expects \'' . $pseudo_set_type->get_id() . '\', ' . ' parent type `' . $second_arg_type . '` provided', new Code_Location($statements_analyzer->get_source(), $stmt), $property_id), $statements_analyzer->get_suppressed_issues());
                        } else {
                            Issue_Buffer::maybe_add(new Property_Type_Coercion($prop_name . ' expects \'' . $pseudo_set_type->get_id() . '\', ' . ' parent type `' . $second_arg_type . '` provided', new Code_Location($statements_analyzer->get_source(), $stmt), $property_id), $statements_analyzer->get_suppressed_issues());
                        }
                    }
                    if (!$type_match_found && !$union_comparison_results->type_coerced_from_mixed) {
                        if (Union_Type_Comparator::can_be_contained_by($codebase, $second_arg_type, $pseudo_set_type)) {
                            Issue_Buffer::maybe_add(new Possibly_Invalid_Property_Assignment_Value($prop_name . ' with declared type \'' . $pseudo_set_type . '\' cannot be assigned possibly different type \'' . $second_arg_type . '\'', new Code_Location($statements_analyzer->get_source(), $stmt), $property_id), $statements_analyzer->get_suppressed_issues());
                        } else {
                            Issue_Buffer::maybe_add(new Invalid_Property_Assignment_Value($prop_name . ' with declared type \'' . $pseudo_set_type . '\' cannot be assigned type \'' . $second_arg_type . '\'', new Code_Location($statements_analyzer->get_source(), $stmt), $property_id), $statements_analyzer->get_suppressed_issues());
                        }
                    }
                }
                break;
            case '__get':
                // If `@psalm-seal-properties` is set, the property must be defined with
                // a `@property` annotation
                if ($class_storage->has_sealed_properties($codebase->config) && !isset($class_storage->pseudo_property_get_types['$' . $prop_name])) {
                    Issue_Buffer::maybe_add(new Undefined_This_Property_Fetch('Instance property ' . $property_id . ' is not defined', new Code_Location($statements_analyzer->get_source(), $stmt), $property_id), $statements_analyzer->get_suppressed_issues());
                }
                if (isset($class_storage->pseudo_property_get_types['$' . $prop_name])) {
                    return $class_storage->pseudo_property_get_types['$' . $prop_name];
                }
                break;
        }
        return null;
    }
}