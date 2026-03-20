<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression;

use Php_Parser;
use Php_Parser\Node\Identifier;
use Php_Parser\Node\Name;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\File_Source;
use Psalm\Internal\Algebra;
use Psalm\Internal\Algebra\Formula_Generator;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Arguments_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Analyzer\Trait_Analyzer;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Type\Comparator\Type_Comparison_Result;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Internal\Type\Template_Bound;
use Psalm\Internal\Type\Template_Inferred_Type_Replacer;
use Psalm\Internal\Type\Template_Result;
use Psalm\Internal\Type\Template_Standin_Type_Replacer;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Issue\Argument_Type_Coercion;
use Psalm\Issue\Invalid_Argument;
use Psalm\Issue\Invalid_Docblock;
use Psalm\Issue\Invalid_Scalar_Argument;
use Psalm\Issue\Mixed_Argument_Type_Coercion;
use Psalm\Issue\Type_Does_Not_Contain_Type;
use Psalm\Issue\Undefined_Function;
use Psalm\Issue_Buffer;
use Psalm\Node\Expr\Binary_Op\Virtual_Identical;
use Psalm\Node\Expr\Virtual_Const_Fetch;
use Psalm\Node\Virtual_Name;
use Psalm\Storage\Assertion\Falsy;
use Psalm\Storage\Assertion\Is_Identical;
use Psalm\Storage\Assertion\Is_Type;
use Psalm\Storage\Assertion\Truthy;
use Psalm\Storage\Class_Like_Storage;
use Psalm\Storage\Possibilities;
use Psalm\Type;
use Psalm\Type\Atomic\T_Callable_Object;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Object_With_Properties;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Atomic\T_True;
use Psalm\Type\Reconciler;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_filter;
use function array_map;
use function array_merge;
use function array_unique;
use function assert;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_int;
use function is_numeric;
use function mt_rand;
use function preg_match;
use function preg_replace;
use function spl_object_id;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strtolower;
/**
 * @internal
 */
abstract class Call_Analyzer
{
    public static function collect_special_information(Function_Like_Analyzer $source, string $method_name, Context $context): void
    {
        $method_name_lc = strtolower($method_name);
        $fq_class_name = (string) $source->get_fqcln();
        $project_analyzer = $source->get_file_analyzer()->project_analyzer;
        $codebase = $source->get_codebase();
        if ($context->collect_mutations && $context->self && ($context->self === $fq_class_name || $codebase->class_extends($context->self, $fq_class_name))) {
            $method_id = new Method_Identifier($fq_class_name, $method_name_lc);
            if ((string) $method_id !== $source->get_id()) {
                if ($context->collect_initializations) {
                    if (isset($context->initialized_methods[(string) $method_id])) {
                        return;
                    }
                    $context->initialized_methods[(string) $method_id] = true;
                }
                $project_analyzer->get_method_mutations($method_id, $context, $source->get_root_file_path(), $source->get_root_file_name());
            }
        } elseif ($context->collect_initializations && $context->self && ($context->self === $fq_class_name || $codebase->classlikes->class_extends($context->self, $fq_class_name)) && $source->get_method_name() !== $method_name) {
            $method_id = new Method_Identifier($fq_class_name, $method_name_lc);
            $declaring_method_id = $codebase->methods->get_declaring_method_id($method_id);
            if (isset($context->vars_in_scope['$this'])) {
                foreach ($context->vars_in_scope['$this']->get_atomic_types() as $atomic_type) {
                    if ($atomic_type instanceof T_Named_Object) {
                        if ($fq_class_name === $atomic_type->value) {
                            $alt_declaring_method_id = $declaring_method_id;
                        } else {
                            $fq_class_name = $atomic_type->value;
                            $method_id = new Method_Identifier($fq_class_name, $method_name_lc);
                            $alt_declaring_method_id = $codebase->methods->get_declaring_method_id($method_id);
                        }
                        if ($alt_declaring_method_id) {
                            $declaring_method_id = $alt_declaring_method_id;
                            break;
                        }
                        if (!$atomic_type->extra_types) {
                            continue;
                        }
                        foreach ($atomic_type->extra_types as $intersection_type) {
                            if ($intersection_type instanceof T_Named_Object) {
                                $fq_class_name = $intersection_type->value;
                                $method_id = new Method_Identifier($fq_class_name, $method_name_lc);
                                $alt_declaring_method_id = $codebase->methods->get_declaring_method_id($method_id);
                                if ($alt_declaring_method_id) {
                                    $declaring_method_id = $alt_declaring_method_id;
                                    break 2;
                                }
                            }
                        }
                    }
                }
            }
            if (!$declaring_method_id) {
                // can happen for __call
                return;
            }
            if (isset($context->initialized_methods[(string) $declaring_method_id])) {
                return;
            }
            $context->initialized_methods[(string) $declaring_method_id] = true;
            $method_storage = $codebase->methods->get_storage($declaring_method_id);
            $class_analyzer = $source->get_source();
            $is_final = $method_storage->final;
            if ($method_name !== $declaring_method_id->method_name) {
                $appearing_method_id = $codebase->methods->get_appearing_method_id($method_id);
                if ($appearing_method_id) {
                    $appearing_class_storage = $codebase->classlike_storage_provider->get($appearing_method_id->fq_class_name);
                    if (isset($appearing_class_storage->trait_final_map[$method_name_lc])) {
                        $is_final = true;
                    }
                }
            }
            if ($class_analyzer instanceof Class_Like_Analyzer && !$method_storage->is_static && ($context->collect_nonprivate_initializations || $method_storage->visibility === Class_Like_Analyzer::VISIBILITY_PRIVATE || $is_final)) {
                $local_vars_in_scope = [];
                foreach ($context->vars_in_scope as $var_id => $type) {
                    if (str_starts_with($var_id, '$this->')) {
                        if ($type->initialized) {
                            $local_vars_in_scope[$var_id] = $context->vars_in_scope[$var_id];
                            $context->remove($var_id, false);
                        }
                    } elseif ($var_id !== '$this') {
                        $local_vars_in_scope[$var_id] = $context->vars_in_scope[$var_id];
                    }
                }
                $local_vars_possibly_in_scope = $context->vars_possibly_in_scope;
                $old_calling_method_id = $context->calling_method_id;
                if ($fq_class_name === $source->get_fqcln()) {
                    $class_analyzer->get_method_mutations($declaring_method_id->method_name, $context);
                } else {
                    $declaring_fq_class_name = $declaring_method_id->fq_class_name;
                    $old_self = $context->self;
                    $context->self = $declaring_fq_class_name;
                    $project_analyzer->get_method_mutations($declaring_method_id, $context, $source->get_root_file_path(), $source->get_root_file_name());
                    $context->self = $old_self;
                }
                $context->calling_method_id = $old_calling_method_id;
                foreach ($local_vars_in_scope as $var => $type) {
                    $context->vars_in_scope[$var] = $type;
                }
                foreach ($local_vars_possibly_in_scope as $var => $_) {
                    $context->vars_possibly_in_scope[$var] = true;
                }
            }
        }
    }
    /**
     * @param  list<PhpParser\Node\Arg>   $args
     */
    public static function check_method_args(?Method_Identifier $method_id, array $args, Template_Result $template_result, Context $context, Code_Location $code_location, Statements_Analyzer $statements_analyzer): bool
    {
        $codebase = $statements_analyzer->get_codebase();
        if (!$method_id) {
            return Arguments_Analyzer::analyze($statements_analyzer, $args, null, null, true, $context, $template_result) !== false;
        }
        $method_params = $codebase->methods->get_method_params($method_id, $statements_analyzer, $args, $context);
        $fq_class_name = $method_id->fq_class_name;
        $method_name = $method_id->method_name;
        $fq_class_name = strtolower($codebase->classlikes->get_un_aliased_name($fq_class_name));
        $class_storage = $codebase->classlike_storage_provider->get($fq_class_name);
        $method_storage = null;
        if (isset($class_storage->declaring_method_ids[$method_name])) {
            $declaring_method_id = $class_storage->declaring_method_ids[$method_name];
            $declaring_fq_class_name = $declaring_method_id->fq_class_name;
            if ($declaring_fq_class_name !== $fq_class_name) {
                $declaring_class_storage = $codebase->classlike_storage_provider->get($declaring_fq_class_name);
            } else {
                $declaring_class_storage = $class_storage;
            }
            $method_storage = $codebase->methods->get_storage($declaring_method_id);
            if ($declaring_class_storage->user_defined && !$method_storage->has_docblock_param_types && isset($declaring_class_storage->documenting_method_ids[$method_name])) {
                $documenting_method_id = $declaring_class_storage->documenting_method_ids[$method_name];
                $documenting_method_storage = $codebase->methods->get_storage($documenting_method_id);
                if ($documenting_method_storage->template_types) {
                    $method_storage = $documenting_method_storage;
                }
            }
            if (!$context->is_suppressing_exceptions($statements_analyzer)) {
                $context->merge_function_exceptions($method_storage, $code_location);
            }
        }
        if (Arguments_Analyzer::analyze($statements_analyzer, $args, $method_params, (string) $method_id, $method_storage->allow_named_arg_calls ?? true, $context, $template_result) === false) {
            return false;
        }
        if (Arguments_Analyzer::check_arguments_match($statements_analyzer, $args, $method_id, $method_params, $method_storage, $class_storage, $template_result, $code_location, $context) === false) {
            return false;
        }
        if ($template_result->template_types) {
            self::check_template_result($statements_analyzer, $template_result, $code_location, strtolower((string) $method_id));
        }
        return true;
    }
    /**
     * This gets all the template params (and their types) that we think
     * we'll need to know about
     *
     * @return array<string, array<string, Union>>
     * @param array<string, non-empty-array<string, Union>> $existing_template_types
     * @param array<string, array<string, Union>> $class_template_params
     */
    public static function get_template_types_for_call(Codebase $codebase, ?Class_Like_Storage $declaring_class_storage, ?string $appearing_class_name, ?Class_Like_Storage $calling_class_storage, array $existing_template_types = [], array $class_template_params = []): array
    {
        $template_types = $existing_template_types;
        if ($declaring_class_storage) {
            if ($calling_class_storage && $declaring_class_storage !== $calling_class_storage && $calling_class_storage->template_extended_params) {
                foreach ($calling_class_storage->template_extended_params as $class_name => $type_map) {
                    foreach ($type_map as $template_name => $type) {
                        if ($class_name === $declaring_class_storage->name) {
                            $output_type = null;
                            foreach ($type->get_atomic_types() as $atomic_type) {
                                if ($atomic_type instanceof T_Template_Param) {
                                    $output_type_candidate = self::get_generic_param_for_offset($atomic_type->defining_class, $atomic_type->param_name, $calling_class_storage->template_extended_params, $class_template_params + $template_types);
                                } else {
                                    $output_type_candidate = new Union([$atomic_type]);
                                }
                                $output_type = Type::combine_union_types($output_type_candidate, $output_type);
                            }
                            $template_types[$template_name][$declaring_class_storage->name] = $output_type;
                        }
                    }
                }
            } elseif ($declaring_class_storage->template_types) {
                foreach ($declaring_class_storage->template_types as $template_name => $type_map) {
                    foreach ($type_map as $key => $type) {
                        $template_types[$template_name][$key] = $class_template_params[$template_name][$key] ?? $type;
                    }
                }
            }
        }
        foreach ($template_types as $key => $type_map) {
            foreach ($type_map as $class => $type) {
                $template_types[$key][$class] = Type_Expander::expand_union($codebase, $type, $appearing_class_name, $calling_class_storage->name ?? null, null, true, false, $calling_class_storage->final ?? false);
            }
        }
        return $template_types;
    }
    /**
     * @param  array<string, array<string, Union>>  $template_extended_params
     * @param  array<string, array<string, Union>>  $found_generic_params
     */
    public static function get_generic_param_for_offset(string $fq_class_name, string $template_name, array $template_extended_params, array $found_generic_params): Union
    {
        if (isset($found_generic_params[$template_name][$fq_class_name])) {
            return $found_generic_params[$template_name][$fq_class_name];
        }
        foreach ($template_extended_params as $extended_class_name => $type_map) {
            foreach ($type_map as $extended_template_name => $extended_type) {
                foreach ($extended_type->get_atomic_types() as $extended_atomic_type) {
                    if ($extended_atomic_type instanceof T_Template_Param && $extended_atomic_type->param_name === $template_name && $extended_atomic_type->defining_class === $fq_class_name) {
                        return self::get_generic_param_for_offset($extended_class_name, $extended_template_name, $template_extended_params, $found_generic_params);
                    }
                }
            }
        }
        return Type::get_mixed();
    }
    /**
     * @param PhpParser\Node\Scalar\String_|PhpParser\Node\Expr\Array_|PhpParser\Node\Expr\BinaryOp\Concat $callable_arg
     * @return list<non-empty-string>
     */
    public static function get_function_ids_from_callable_arg(File_Source $file_source, Php_Parser\Node\Expr $callable_arg): array
    {
        if ($callable_arg instanceof Php_Parser\Node\Expr\Binary_Op\Concat) {
            if ($callable_arg->left instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $callable_arg->left->class instanceof Name && $callable_arg->left->name instanceof Identifier && strtolower($callable_arg->left->name->name) === 'class' && !in_array(strtolower($callable_arg->left->class->get_first()), ['self', 'static', 'parent']) && $callable_arg->right instanceof Php_Parser\Node\Scalar\String_ && preg_match('/^::[A-Za-z0-9]+$/', $callable_arg->right->value)) {
                $r = $callable_arg->left->class->get_attribute('resolvedName') . $callable_arg->right->value;
                assert($r !== '');
                return [$r];
            }
            return [];
        }
        if ($callable_arg instanceof Php_Parser\Node\Scalar\String_) {
            $potential_id = (string) preg_replace('/^\\\\/', '', $callable_arg->value, 1);
            if (preg_match('/^[A-Za-z0-9_]+(\\\\[A-Za-z0-9_]+)*(::[A-Za-z0-9_]+)?$/', $potential_id)) {
                assert($potential_id !== '');
                return [$potential_id];
            }
            return [];
        }
        if (count($callable_arg->items) !== 2) {
            return [];
        }
        /** @psalm-suppress PossiblyNullPropertyFetch */
        if ($callable_arg->items[0]->key || $callable_arg->items[1]->key) {
            return [];
        }
        if (!isset($callable_arg->items[0]) || !isset($callable_arg->items[1])) {
            throw new UnexpectedValueException('These should never be unset');
        }
        $class_arg = $callable_arg->items[0]->value;
        $method_name_arg = $callable_arg->items[1]->value;
        if (!$method_name_arg instanceof Php_Parser\Node\Scalar\String_) {
            return [];
        }
        if ($class_arg instanceof Php_Parser\Node\Scalar\String_) {
            return [preg_replace('/^\\\\/', '', $class_arg->value, 1) . '::' . $method_name_arg->value];
        }
        if ($class_arg instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $class_arg->name instanceof Identifier && strtolower($class_arg->name->name) === 'class' && $class_arg->class instanceof Name) {
            $fq_class_name = Class_Like_Analyzer::get_fqcln_from_name_object($class_arg->class, $file_source->get_aliases());
            return [$fq_class_name . '::' . $method_name_arg->value];
        }
        if (!$file_source instanceof Statements_Analyzer || !$class_arg_type = $file_source->node_data->get_type($class_arg)) {
            return [];
        }
        $method_ids = [];
        foreach ($class_arg_type->get_atomic_types() as $type_part) {
            if ($type_part instanceof T_Named_Object) {
                $method_id = $type_part->value . '::' . $method_name_arg->value;
                foreach ($type_part->extra_types as $extra_type) {
                    if ($extra_type instanceof T_Template_Param || $extra_type instanceof T_Object_With_Properties || $extra_type instanceof T_Callable_Object) {
                        throw new UnexpectedValueException('Shouldn’t get a generic param here');
                    }
                    $method_id .= '&' . $extra_type->value . '::' . $method_name_arg->value;
                }
                $method_ids[] = '$' . $method_id;
            }
        }
        return $method_ids;
    }
    /**
     * @param  non-empty-string     $function_id
     * @param  bool                 $can_be_in_root_scope if true, the function can be shortened to the root version
     */
    public static function check_function_exists(Statements_Analyzer $statements_analyzer, string &$function_id, Code_Location $code_location, bool $can_be_in_root_scope): bool
    {
        $cased_function_id = $function_id;
        $function_id = strtolower($function_id);
        $codebase = $statements_analyzer->get_codebase();
        if (!$codebase->functions->function_exists($statements_analyzer, $function_id)) {
            /** @var non-empty-lowercase-string */
            $root_function_id = (string) preg_replace('/.*\\\\/', '', $function_id);
            if ($can_be_in_root_scope && $function_id !== $root_function_id && $codebase->functions->function_exists($statements_analyzer, $root_function_id)) {
                $function_id = $root_function_id;
            } else {
                Issue_Buffer::maybe_add(new Undefined_Function('Function ' . $cased_function_id . ' does not exist' . ', consider enabling the allFunctionsGlobal config option if scanning legacy codebases', $code_location, $function_id), $statements_analyzer->get_suppressed_issues());
                return false;
            }
        }
        return true;
    }
    /**
     * @param Identifier|Name $expr
     * @param  Possibilities[] $var_assertions
     * @param  list<PhpParser\Node\Arg> $args
     */
    public static function apply_assertions_to_context(Php_Parser\Node_Abstract $expr, ?string $this_name, array $var_assertions, array $args, Template_Result $template_result, Context $context, Statements_Analyzer $statements_analyzer): void
    {
        $type_assertions = [];
        $asserted_keys = [];
        foreach ($var_assertions as $var_possibilities) {
            $assertion_var_id = null;
            $arg_value = null;
            if (is_int($var_possibilities->var_id)) {
                if (!isset($args[$var_possibilities->var_id])) {
                    continue;
                }
                $arg_value = $args[$var_possibilities->var_id]->value;
                $arg_var_id = Expression_Identifier::get_extended_var_id($arg_value, null, $statements_analyzer);
                if ($arg_var_id) {
                    $assertion_var_id = $arg_var_id;
                }
            } elseif ($var_possibilities->var_id === '$this' && $this_name !== null) {
                $assertion_var_id = $this_name;
            } elseif (str_starts_with((string) $var_possibilities->var_id, '$this->') && $this_name !== null) {
                $assertion_var_id = $this_name . str_replace('$this->', '->', $var_possibilities->var_id);
            } elseif (str_starts_with((string) $var_possibilities->var_id, 'self::') && $context->self) {
                $assertion_var_id = $context->self . str_replace('self::', '::', $var_possibilities->var_id);
            } elseif (str_contains((string) $var_possibilities->var_id, '::$')) {
                // allow assertions to bring external static props into scope
                $assertion_var_id = $var_possibilities->var_id;
            } elseif (isset($context->vars_in_scope[$var_possibilities->var_id])) {
                $assertion_var_id = $var_possibilities->var_id;
            } elseif (str_contains((string) $var_possibilities->var_id, '->')) {
                $exploded = explode('->', $var_possibilities->var_id);
                if (count($exploded) < 2) {
                    Issue_Buffer::maybe_add(new Invalid_Docblock('Assert notation is malformed', new Code_Location($statements_analyzer, $expr)));
                    continue;
                }
                [$var_id, $property] = $exploded;
                $var_id = is_numeric($var_id) ? (int) $var_id : $var_id;
                if (!is_int($var_id) || !isset($args[$var_id])) {
                    Issue_Buffer::maybe_add(new Invalid_Docblock('Variable ' . $var_id . ' is not an argument so cannot be asserted', new Code_Location($statements_analyzer, $expr)));
                    continue;
                }
                /** @var PhpParser\Node\Expr\Variable $arg_value */
                $arg_value = $args[$var_id]->value;
                $arg_var_id = Expression_Identifier::get_extended_var_id($arg_value, null, $statements_analyzer);
                if (!$arg_var_id) {
                    Issue_Buffer::maybe_add(new Invalid_Docblock('Variable being asserted as argument ' . ($var_id + 1) . ' cannot be found in local scope', new Code_Location($statements_analyzer, $expr)));
                    continue;
                }
                if (count($exploded) === 2) {
                    $failed_message = Assertion_Finder::is_property_immutable_on_argument($property, $statements_analyzer->get_node_type_provider(), $statements_analyzer->get_codebase()->classlike_storage_provider, $arg_value);
                    if (null !== $failed_message) {
                        Issue_Buffer::maybe_add(new Invalid_Docblock($failed_message, new Code_Location($statements_analyzer, $expr)));
                        continue;
                    }
                }
                $assertion_var_id = str_replace((string) $var_id, $arg_var_id, $var_possibilities->var_id);
            }
            $codebase = $statements_analyzer->get_codebase();
            if ($assertion_var_id) {
                $orred_rules = [];
                foreach ($var_possibilities->rule as $assertion_rule) {
                    $assertion_type_atomic = $assertion_rule->get_atomic_type();
                    if ($assertion_type_atomic) {
                        $assertion_type = Template_Inferred_Type_Replacer::replace(new Union([$assertion_type_atomic]), $template_result, $codebase);
                        if (count($assertion_type->get_atomic_types()) === 1) {
                            foreach ($assertion_type->get_atomic_types() as $atomic_type) {
                                if ($assertion_type_atomic instanceof T_Template_Param && $assertion_type_atomic->as->get_id() === $atomic_type->get_id()) {
                                    continue;
                                }
                                $assertion_rule = $assertion_rule->set_atomic_type($atomic_type);
                                $orred_rules[] = $assertion_rule;
                            }
                        } elseif (isset($context->vars_in_scope[$assertion_var_id])) {
                            $asserted_type = $context->vars_in_scope[$assertion_var_id];
                            if ($assertion_rule instanceof Is_Identical) {
                                $intersection = Type::intersect_union_types($assertion_type, $asserted_type, $codebase);
                                if ($intersection === null) {
                                    Issue_Buffer::maybe_add(new Type_Does_Not_Contain_Type($asserted_type->get_id() . ' is not contained by ' . $assertion_type->get_id(), new Code_Location($statements_analyzer->get_source(), $expr), $asserted_type->get_id() . ' ' . $assertion_type->get_id()), $statements_analyzer->get_suppressed_issues());
                                    $intersection = Type::get_never();
                                } elseif ($intersection->get_id(true) === $asserted_type->get_id(true)) {
                                    continue;
                                }
                                foreach ($intersection->get_atomic_types() as $atomic_type) {
                                    $orred_rules[] = new Is_Identical($atomic_type);
                                }
                            } elseif ($assertion_rule instanceof Is_Type) {
                                if (!Union_Type_Comparator::can_expression_types_be_identical($codebase, $assertion_type, $asserted_type)) {
                                    Issue_Buffer::maybe_add(new Type_Does_Not_Contain_Type($asserted_type->get_id() . ' is not contained by ' . $assertion_type->get_id(), new Code_Location($statements_analyzer->get_source(), $expr), $asserted_type->get_id() . ' ' . $assertion_type->get_id()), $statements_analyzer->get_suppressed_issues());
                                }
                            } else {
                                // Ignore negations and loose assertions with union types
                            }
                        }
                    } else {
                        $orred_rules[] = $assertion_rule;
                    }
                }
                if ($orred_rules) {
                    if (isset($type_assertions[$assertion_var_id])) {
                        $type_assertions[$assertion_var_id] = array_merge($type_assertions[$assertion_var_id], [$orred_rules]);
                    } else {
                        $type_assertions[$assertion_var_id] = [$orred_rules];
                    }
                }
            } elseif ($arg_value && count($var_possibilities->rule) === 1) {
                $assert_clauses = [];
                $single_rule = $var_possibilities->rule[0];
                if ($single_rule instanceof Truthy) {
                    $assert_clauses = Formula_Generator::get_formula(spl_object_id($arg_value), spl_object_id($arg_value), $arg_value, $context->self, $statements_analyzer, $statements_analyzer->get_codebase());
                } elseif ($single_rule instanceof Falsy) {
                    $assert_clauses = Algebra::negate_formula(Formula_Generator::get_formula(spl_object_id($arg_value), spl_object_id($arg_value), $arg_value, $context->self, $statements_analyzer, $codebase));
                } elseif ($single_rule instanceof Is_Type && $single_rule->type instanceof T_True) {
                    $conditional = new Virtual_Identical($arg_value, new Virtual_Const_Fetch(new Virtual_Name('true')));
                    $assert_clauses = Formula_Generator::get_formula(mt_rand(0, 1000000), mt_rand(0, 1000000), $conditional, $context->self, $statements_analyzer, $codebase);
                }
                $simplified_clauses = Algebra::simplify_cnf([...$context->clauses, ...$assert_clauses]);
                $assert_type_assertions = Algebra::get_truths_from_formula($simplified_clauses);
                $type_assertions = [...$type_assertions, ...$assert_type_assertions];
            }
        }
        $changed_var_ids = [];
        foreach ($type_assertions as $var_id => $_) {
            $asserted_keys[$var_id] = true;
        }
        $codebase = $statements_analyzer->get_codebase();
        if ($type_assertions) {
            $template_type_map = [];
            // while in an and, we allow scope to boil over to support
            // statements of the form if ($x && $x->foo())
            [$op_vars_in_scope, $op_references_in_scope] = Reconciler::reconcile_keyed_types($type_assertions, $type_assertions, $context->vars_in_scope, $context->references_in_scope, $changed_var_ids, $asserted_keys, $statements_analyzer, $template_type_map, $context->inside_loop, new Code_Location($statements_analyzer->get_source(), $expr));
            foreach ($changed_var_ids as $var_id => $_) {
                if (isset($op_vars_in_scope[$var_id])) {
                    $first_appearance = $statements_analyzer->get_first_appearance($var_id);
                    if ($first_appearance && isset($context->vars_in_scope[$var_id]) && $context->vars_in_scope[$var_id]->has_mixed()) {
                        if (!$context->collect_initializations && !$context->collect_mutations && $statements_analyzer->get_file_path() === $statements_analyzer->get_root_file_path() && (!($parent_source = $statements_analyzer->get_source()) instanceof Function_Like_Analyzer || !$parent_source->get_source() instanceof Trait_Analyzer)) {
                            $codebase->analyzer->decrement_mixed_count($statements_analyzer->get_file_path());
                        }
                        Issue_Buffer::remove($statements_analyzer->get_file_path(), 'MixedAssignment', $first_appearance->raw_file_start);
                    }
                    $op_vars_in_scope[$var_id] = $op_vars_in_scope[$var_id]->set_from_docblock(true);
                }
            }
            $context->vars_in_scope = $op_vars_in_scope;
            $context->references_in_scope = $op_references_in_scope;
        }
    }
    /**
     * This method looks for problems with a generated TemplateResult.
     *
     * The TemplateResult object contains upper bounds and lower bounds for each template param.
     *
     * Those upper bounds represent a series of constraints like
     *
     * Lower bound:
     * T >: X (the type param T matches X, or is a supertype of X)
     * Upper bound:
     * T <: Y (the type param T matches Y, or is a subtype of Y)
     * Equality (currently represented as an upper bound with a special flag)
     * T = Z  (the template T must match Z)
     *
     * This method attempts to reconcile those constraints.
     *
     * Valid constraints:
     *
     * T <: int|float, T >: int --- implies T is an int
     * T = int --- implies T is an int
     *
     * Invalid constraints:
     *
     * T <: int|string, T >: string|float --- implies T <: int and T >: float, which is impossible
     * T = int, T = string --- implies T is a string _and_ and int, which is impossible
     */
    public static function check_template_result(Statements_Analyzer $statements_analyzer, Template_Result $template_result, Code_Location $code_location, ?string $function_id): void
    {
        if ($template_result->lower_bounds && $template_result->upper_bounds) {
            foreach ($template_result->upper_bounds as $template_name => $defining_map) {
                foreach ($defining_map as $defining_id => $upper_bound) {
                    if (isset($template_result->lower_bounds[$template_name][$defining_id])) {
                        $lower_bound_type = Template_Standin_Type_Replacer::get_most_specific_type_from_bounds($template_result->lower_bounds[$template_name][$defining_id], $statements_analyzer->get_codebase());
                        $upper_bound_type = $upper_bound->type;
                        $union_comparison_result = new Type_Comparison_Result();
                        if (count($template_result->upper_bounds_unintersectable_types) > 1) {
                            [$lower_bound_type, $upper_bound_type] = $template_result->upper_bounds_unintersectable_types;
                        }
                        if (!Union_Type_Comparator::is_contained_by($statements_analyzer->get_codebase(), $lower_bound_type, $upper_bound_type, false, false, $union_comparison_result)) {
                            if ($union_comparison_result->type_coerced) {
                                if ($union_comparison_result->type_coerced_from_mixed) {
                                    Issue_Buffer::maybe_add(new Mixed_Argument_Type_Coercion('Type ' . $lower_bound_type->get_id() . ' should be a subtype of ' . $upper_bound_type->get_id(), $code_location, $function_id), $statements_analyzer->get_suppressed_issues());
                                } else {
                                    Issue_Buffer::maybe_add(new Argument_Type_Coercion('Type ' . $lower_bound_type->get_id() . ' should be a subtype of ' . $upper_bound_type->get_id(), $code_location, $function_id), $statements_analyzer->get_suppressed_issues());
                                }
                            } elseif ($union_comparison_result->scalar_type_match_found) {
                                Issue_Buffer::maybe_add(new Invalid_Scalar_Argument('Type ' . $lower_bound_type->get_id() . ' should be a subtype of ' . $upper_bound_type->get_id(), $code_location, $function_id), $statements_analyzer->get_suppressed_issues());
                            } else {
                                Issue_Buffer::maybe_add(new Invalid_Argument('Type ' . $lower_bound_type->get_id() . ' should be a subtype of ' . $upper_bound_type->get_id(), $code_location, $function_id), $statements_analyzer->get_suppressed_issues());
                            }
                        }
                    } else {
                        $template_result->lower_bounds[$template_name][$defining_id] = [new Template_Bound($upper_bound->type)];
                    }
                }
            }
        }
        // Attempt to identify invalid lower bounds
        foreach ($template_result->lower_bounds as $template_name => $lower_bounds) {
            foreach ($lower_bounds as $lower_bounds) {
                if (count($lower_bounds) > 1) {
                    $bounds_with_equality = array_filter($lower_bounds, static fn($lower_bound): bool => (bool) $lower_bound->equality_bound_classlike);
                    if (!$bounds_with_equality) {
                        continue;
                    }
                    $equality_types = array_unique(array_map(static fn($bound_with_equality): string => $bound_with_equality->type->get_id(), $bounds_with_equality));
                    if (count($equality_types) > 1) {
                        Issue_Buffer::maybe_add(new Invalid_Argument('Incompatible types found for ' . $template_name . ' (must have only one of ' . implode(', ', $equality_types) . ')', $code_location, $function_id), $statements_analyzer->get_suppressed_issues());
                    } else {
                        foreach ($lower_bounds as $lower_bound) {
                            if ($lower_bound->equality_bound_classlike === null) {
                                foreach ($bounds_with_equality as $bound_with_equality) {
                                    if (Union_Type_Comparator::is_contained_by($statements_analyzer->get_codebase(), $lower_bound->type, $bound_with_equality->type)) {
                                        continue 2;
                                    }
                                }
                                Issue_Buffer::maybe_add(new Invalid_Argument('Incompatible types found for ' . $template_name . ' (' . $lower_bound->type->get_id() . ' is not in ' . implode(', ', $equality_types) . ')', $code_location, $function_id), $statements_analyzer->get_suppressed_issues());
                            }
                        }
                    }
                }
            }
        }
    }
}