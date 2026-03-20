<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Call\Static_Method;

use Php_Parser;
use Php_Parser\Node\Expr\Static_Call;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Config;
use Psalm\Context;
use Psalm\File_Manipulation;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Class_Template_Param_Collector;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Method\Method_Call_Prohibition_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Static_Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Codebase\Assertions_From_Inheritance_Resolver;
use Psalm\Internal\File_Manipulation\File_Manipulation_Buffer;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Internal\Type\Template_Bound;
use Psalm\Internal\Type\Template_Inferred_Type_Replacer;
use Psalm\Internal\Type\Template_Result;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Internal\Type_Visitor\Contains_Static_Visitor;
use Psalm\Issue\Abstract_Method_Call;
use Psalm\Issue\Impure_Method_Call;
use Psalm\Issue_Buffer;
use Psalm\Plugin\Event_Handler\Event\After_Method_Call_Analysis_Event;
use Psalm\Storage\Class_Like_Storage;
use Psalm\Storage\Possibilities;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Atomic\T_Template_Param_Class;
use Psalm\Type\Union;
use function array_map;
use function count;
use function explode;
use function in_array;
use function is_string;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
/**
 * @internal
 */
final class Existing_Atomic_Static_Call_Analyzer
{
    /**
     * @param  list<PhpParser\Node\Arg> $args
     */
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Static_Call $stmt, Php_Parser\Node\Identifier $stmt_name, array $args, Context $context, Atomic $lhs_type_part, Method_Identifier $method_id, string $cased_method_id, Class_Like_Storage $class_storage, bool &$moved_call, ?Template_Result $inferred_template_result = null): void
    {
        $fq_class_name = $method_id->fq_class_name;
        $method_name_lc = $method_id->method_name;
        $codebase = $statements_analyzer->get_codebase();
        $config = $codebase->config;
        Method_Call_Prohibition_Analyzer::analyze($codebase, $context, $method_id, $statements_analyzer->get_fully_qualified_function_method_or_namespace_name(), new Code_Location($statements_analyzer->get_source(), $stmt), $statements_analyzer->get_suppressed_issues());
        if ($class_storage->user_defined && $context->self && ($context->collect_mutations || $context->collect_initializations)) {
            $appearing_method_id = $codebase->methods->get_appearing_method_id($method_id);
            if (!$appearing_method_id) {
                return;
            }
            $appearing_method_class_name = $appearing_method_id->fq_class_name;
            if ($codebase->class_extends($context->self, $appearing_method_class_name)) {
                $old_context_include_location = $context->include_location;
                $old_self = $context->self;
                $context->include_location = new Code_Location($statements_analyzer->get_source(), $stmt);
                $context->self = $appearing_method_class_name;
                $file_analyzer = $statements_analyzer->get_file_analyzer();
                if ($context->collect_mutations) {
                    $file_analyzer->get_method_mutations($appearing_method_id, $context);
                } else {
                    // collecting initializations
                    $local_vars_in_scope = [];
                    $local_vars_possibly_in_scope = [];
                    foreach ($context->vars_in_scope as $var => $_) {
                        if (!str_starts_with($var, '$this->') && $var !== '$this') {
                            $local_vars_in_scope[$var] = $context->vars_in_scope[$var];
                        }
                    }
                    foreach ($context->vars_possibly_in_scope as $var => $_) {
                        if (!str_starts_with($var, '$this->') && $var !== '$this') {
                            $local_vars_possibly_in_scope[$var] = $context->vars_possibly_in_scope[$var];
                        }
                    }
                    if (!isset($context->initialized_methods[(string) $appearing_method_id])) {
                        $context->initialized_methods[(string) $appearing_method_id] = true;
                        $file_analyzer->get_method_mutations($appearing_method_id, $context);
                        foreach ($local_vars_in_scope as $var => $type) {
                            $context->vars_in_scope[$var] = $type;
                        }
                        foreach ($local_vars_possibly_in_scope as $var => $type) {
                            $context->vars_possibly_in_scope[$var] = $type;
                        }
                    }
                }
                $context->include_location = $old_context_include_location;
                $context->self = $old_self;
            }
        }
        $found_generic_params = Class_Template_Param_Collector::collect($codebase, $class_storage, $class_storage, $method_name_lc, $lhs_type_part, !$statements_analyzer->is_static() && $method_id->fq_class_name === $context->self);
        if ($found_generic_params && $stmt->class instanceof Php_Parser\Node\Name && $stmt->class->get_parts() === ['parent'] && $context->self && ($self_class_storage = $codebase->classlike_storage_provider->get($context->self)) && $self_class_storage->template_extended_params) {
            foreach ($self_class_storage->template_extended_params as $template_fq_class_name => $extended_types) {
                foreach ($extended_types as $type_key => $extended_type) {
                    if (isset($found_generic_params[$type_key][$template_fq_class_name])) {
                        $found_generic_params[$type_key][$template_fq_class_name] = $extended_type;
                        continue;
                    }
                    foreach ($extended_type->get_atomic_types() as $t) {
                        if ($t instanceof T_Template_Param && isset($found_generic_params[$t->param_name][$t->defining_class])) {
                            $found_generic_params[$type_key][$template_fq_class_name] = $found_generic_params[$t->param_name][$t->defining_class];
                        } else {
                            $found_generic_params[$type_key][$template_fq_class_name] = $extended_type;
                            break;
                        }
                    }
                }
            }
        }
        $template_result = new Template_Result([], $found_generic_params ?: []);
        if ($inferred_template_result) {
            $template_result->lower_bounds += $inferred_template_result->lower_bounds;
        }
        if (Call_Analyzer::check_method_args($method_id, $args, $template_result, $context, new Code_Location($statements_analyzer->get_source(), $stmt), $statements_analyzer) === false) {
            return;
        }
        $fq_class_name = $stmt->class instanceof Php_Parser\Node\Name && $stmt->class->get_parts() === ['parent'] ? (string) $statements_analyzer->get_fqcln() : $fq_class_name;
        $self_fq_class_name = $fq_class_name;
        $return_type_candidate = null;
        if ($codebase->methods->return_type_provider->has($fq_class_name)) {
            $return_type_candidate = $codebase->methods->return_type_provider->get_return_type($statements_analyzer, $fq_class_name, $stmt_name->name, $stmt, $context, new Code_Location($statements_analyzer->get_source(), $stmt_name));
        }
        $declaring_method_id = $codebase->methods->get_declaring_method_id($method_id);
        if (!$return_type_candidate && $declaring_method_id && (string) $declaring_method_id !== (string) $method_id) {
            $declaring_fq_class_name = $declaring_method_id->fq_class_name;
            $declaring_method_name = $declaring_method_id->method_name;
            if ($codebase->methods->return_type_provider->has($declaring_fq_class_name)) {
                $return_type_candidate = $codebase->methods->return_type_provider->get_return_type($statements_analyzer, $declaring_fq_class_name, $declaring_method_name, $stmt, $context, new Code_Location($statements_analyzer->get_source(), $stmt_name), null, $fq_class_name, $stmt_name->name);
            }
        }
        if (!$return_type_candidate) {
            $return_type_candidate = self::get_method_return_type($statements_analyzer, $codebase, $stmt, $method_id, $args, $template_result, $self_fq_class_name, $lhs_type_part, $context, $fq_class_name, $class_storage, $config);
        }
        $method_storage = $codebase->methods->get_user_method_storage($method_id);
        if ($method_storage) {
            if ($method_storage->abstract && $stmt->class instanceof Php_Parser\Node\Name && (!$context->self || !Union_Type_Comparator::is_contained_by($codebase, $context->vars_in_scope['$this'] ?? new Union([new T_Named_Object($context->self)]), new Union([new T_Named_Object($method_id->fq_class_name)])))) {
                Issue_Buffer::maybe_add(new Abstract_Method_Call('Cannot call an abstract static method ' . $method_id . ' directly', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
            }
            if (!$context->inside_throw) {
                if ($context->pure && !$method_storage->pure) {
                    Issue_Buffer::maybe_add(new Impure_Method_Call('Cannot call an impure method from a pure context', new Code_Location($statements_analyzer, $stmt_name)), $statements_analyzer->get_suppressed_issues());
                } elseif ($context->mutation_free && !$method_storage->mutation_free) {
                    Issue_Buffer::maybe_add(new Impure_Method_Call('Cannot call a possibly-mutating method from a mutation-free context', new Code_Location($statements_analyzer, $stmt_name)), $statements_analyzer->get_suppressed_issues());
                } elseif ($statements_analyzer->get_source() instanceof Function_Like_Analyzer && $statements_analyzer->get_source()->track_mutations && !$method_storage->pure) {
                    if (!$method_storage->mutation_free) {
                        $statements_analyzer->get_source()->inferred_has_mutation = true;
                    }
                    $statements_analyzer->get_source()->inferred_impure = true;
                }
            }
            $assertions_resolver = new Assertions_From_Inheritance_Resolver($codebase);
            $assertions = $assertions_resolver->resolve($method_storage, $class_storage);
            if ($assertions) {
                Call_Analyzer::apply_assertions_to_context($stmt_name, null, $assertions, $stmt->get_args(), $template_result, $context, $statements_analyzer);
            }
            if ($method_storage->if_true_assertions) {
                $statements_analyzer->node_data->set_if_true_assertions($stmt, array_map(static fn(Possibilities $assertion): Possibilities => $assertion->get_untemplated_copy($template_result, null, $codebase), $method_storage->if_true_assertions));
            }
            if ($method_storage->if_false_assertions) {
                $statements_analyzer->node_data->set_if_false_assertions($stmt, array_map(static fn(Possibilities $assertion): Possibilities => $assertion->get_untemplated_copy($template_result, null, $codebase), $method_storage->if_false_assertions));
            }
        }
        if ($codebase->alter_code) {
            foreach ($codebase->call_transforms as $original_pattern => $transformation) {
                if (!$declaring_method_id) {
                    continue;
                }
                if (!(strtolower((string) $declaring_method_id) . '\((.*\))' === $original_pattern)) {
                    continue;
                }
                if (!(strpos($transformation, '($1)') === strlen($transformation) - 4)) {
                    continue;
                }
                if (!$stmt->class instanceof Php_Parser\Node\Name) {
                    continue;
                }
                $new_method_id = substr($transformation, 0, -4);
                $old_declaring_fq_class_name = $declaring_method_id->fq_class_name;
                [$new_fq_class_name, $new_method_name] = explode('::', $new_method_id);
                if ($codebase->classlikes->handle_class_like_reference_in_migration($codebase, $statements_analyzer, $stmt->class, $new_fq_class_name, $context->calling_method_id, strtolower($old_declaring_fq_class_name) !== strtolower($new_fq_class_name), $stmt->class->get_first() === 'self')) {
                    $moved_call = true;
                }
                $file_manipulations = [];
                $file_manipulations[] = new File_Manipulation((int) $stmt_name->get_attribute('startFilePos'), (int) $stmt_name->get_attribute('endFilePos') + 1, $new_method_name);
                File_Manipulation_Buffer::add($statements_analyzer->get_file_path(), $file_manipulations);
            }
        }
        if ($config->event_dispatcher->has_after_method_call_analysis_handlers()) {
            $file_manipulations = [];
            $appearing_method_id = $codebase->methods->get_appearing_method_id($method_id);
            if ($appearing_method_id !== null && $declaring_method_id) {
                $event = new After_Method_Call_Analysis_Event($stmt, (string) $method_id, (string) $appearing_method_id, (string) $declaring_method_id, $context, $statements_analyzer, $codebase, $file_manipulations, $return_type_candidate);
                $config->event_dispatcher->dispatch_after_method_call_analysis($event);
                $file_manipulations = $event->get_file_replacements();
                $return_type_candidate = $event->get_return_type_candidate();
            }
            if ($file_manipulations) {
                File_Manipulation_Buffer::add($statements_analyzer->get_file_path(), $file_manipulations);
            }
        }
        $return_type_candidate ??= Type::get_mixed();
        Static_Call_Analyzer::taint_return_type($statements_analyzer, $stmt, $method_id, $cased_method_id, $return_type_candidate, $method_storage, $template_result, $context);
        $stmt_type = $statements_analyzer->node_data->get_type($stmt);
        $statements_analyzer->node_data->set_type($stmt, Type::combine_union_types($stmt_type, $return_type_candidate));
        if ($codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations) {
            $codebase->analyzer->add_node_reference($statements_analyzer->get_file_path(), $stmt->name, $method_id . '()');
            if ($stmt_type = $statements_analyzer->node_data->get_type($stmt)) {
                $codebase->analyzer->add_node_type($statements_analyzer->get_file_path(), $stmt->name, $stmt_type->get_id(), $stmt);
            }
        }
    }
    /**
     * @param list<PhpParser\Node\Arg> $args
     */
    private static function get_method_return_type(Statements_Analyzer $statements_analyzer, Codebase $codebase, Static_Call $stmt, Method_Identifier $method_id, array $args, Template_Result $template_result, ?string &$self_fq_class_name, Atomic $lhs_type_part, Context $context, string $fq_class_name, Class_Like_Storage $class_storage, Config $config): ?Union
    {
        $return_type_candidate = $codebase->methods->get_method_return_type($method_id, $self_fq_class_name, $statements_analyzer, $args);
        if ($return_type_candidate) {
            if ($template_result->template_types) {
                $bindable_template_types = $return_type_candidate->get_template_types();
                foreach ($bindable_template_types as $template_type) {
                    if (!isset($template_result->lower_bounds[$template_type->param_name][$template_type->defining_class])) {
                        $template_result->lower_bounds[$template_type->param_name] = self::resolve_template_result_lower_bound($codebase, $stmt, $class_storage, $method_id, $template_type);
                    }
                }
            }
            $context_final = false;
            if ($lhs_type_part instanceof T_Template_Param) {
                $static_type = $lhs_type_part;
            } elseif ($lhs_type_part instanceof T_Template_Param_Class) {
                $static_type = new T_Template_Param($lhs_type_part->param_name, $lhs_type_part->as_type ? new Union([$lhs_type_part->as_type]) : Type::get_object(), $lhs_type_part->defining_class);
            } elseif ($stmt->class instanceof Php_Parser\Node\Name && count($stmt->class->get_parts()) === 1 && in_array(strtolower($stmt->class->get_first()), ['self', 'static', 'parent'], true) && $lhs_type_part instanceof T_Named_Object && $context->self) {
                $static_type = $context->self;
                $context_final = $codebase->classlike_storage_provider->get($context->self)->final;
            } elseif ($context->calling_method_id !== null) {
                // differentiate between these cases:
                //   1. "static" comes from the CALLED static method - use $fq_class_name.
                //   2. "static" in return type comes from return type of the
                //   method CALLING the currently analyzed static method - use $context->self.
                $static_type = self::has_static_in_type($return_type_candidate) ? $fq_class_name : $context->self;
            } else {
                $static_type = $fq_class_name;
            }
            if ($template_result->lower_bounds) {
                $return_type_candidate = Type_Expander::expand_union($codebase, $return_type_candidate, null, null, null);
                $return_type_candidate = Template_Inferred_Type_Replacer::replace($return_type_candidate, $template_result, $codebase);
            }
            $return_type_candidate = Type_Expander::expand_union($codebase, $return_type_candidate, $self_fq_class_name, $static_type, $class_storage->parent_class, true, false, is_string($static_type) && ($static_type !== $context->self || $class_storage->final || $context_final));
            $secondary_return_type_location = null;
            $return_type_location = $codebase->methods->get_method_return_type_location($method_id, $secondary_return_type_location);
            if ($secondary_return_type_location) {
                $return_type_location = $secondary_return_type_location;
            }
            // only check the type locally if it's defined externally
            if ($return_type_location && !$config->is_in_project_dirs($return_type_location->file_path)) {
                /** @psalm-suppress UnusedMethodCall Actually generates issues */
                $return_type_candidate->check($statements_analyzer, new Code_Location($statements_analyzer, $stmt), $statements_analyzer->get_suppressed_issues(), $context->phantom_classes, true, false, false, $context->calling_method_id);
            }
        }
        return $return_type_candidate;
    }
    /**
     * Dumb way to determine whether a type contains "static" somewhere inside.
     */
    private static function has_static_in_type(Type\Type_Node $type): bool
    {
        $visitor = new Contains_Static_Visitor();
        $visitor->traverse($type);
        return $visitor->matches();
    }
    /**
     * @return non-empty-array<string,non-empty-list<TemplateBound>>
     */
    private static function resolve_template_result_lower_bound(Codebase $codebase, Static_Call $stmt, Class_Like_Storage $class_storage, Method_Identifier $method_id, T_Template_Param $template_type): array
    {
        if ($template_type->param_name === 'TFunctionArgCount') {
            return ['fn-' . $method_id->method_name => [new Template_Bound(Type::get_int(false, count($stmt->get_args())))]];
        }
        if ($template_type->param_name === 'TPhpMajorVersion') {
            return ['fn-' . $method_id->method_name => [new Template_Bound(Type::get_int(false, $codebase->get_major_analysis_php_version()))]];
        }
        if ($template_type->param_name === 'TPhpVersionId') {
            return ['fn-' . $method_id->method_name => [new Template_Bound(Type::get_int(false, $codebase->analysis_php_version_id))]];
        }
        if (isset($class_storage->template_extended_params[$template_type->defining_class][$template_type->param_name])) {
            $extended_param_type = $class_storage->template_extended_params[$template_type->defining_class][$template_type->param_name];
            return [$template_type->defining_class => [new Template_Bound($extended_param_type)]];
        }
        return [$template_type->defining_class => [new Template_Bound(Type::get_never())]];
    }
}