<?php

declare (strict_types=1);
namespace Psalm\Internal\Codebase;

use InvalidArgumentException;
use Php_Parser;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\Analyzer\Source_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Class_Template_Param_Collector;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Provider\Class_Like_Storage_Provider;
use Psalm\Internal\Provider\File_Reference_Provider;
use Psalm\Internal\Provider\Method_Existence_Provider;
use Psalm\Internal\Provider\Method_Params_Provider;
use Psalm\Internal\Provider\Method_Return_Type_Provider;
use Psalm\Internal\Provider\Method_Visibility_Provider;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Internal\Type\Template_Inferred_Type_Replacer;
use Psalm\Internal\Type\Template_Result;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Internal\Type_Visitor\Type_Localizer;
use Psalm\Statements_Source;
use Psalm\Storage\Class_Like_Storage;
use Psalm\Storage\Function_Like_Parameter;
use Psalm\Storage\Method_Storage;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Callable;
use Psalm\Type\Atomic\T_Closure;
use Psalm\Type\Atomic\T_Enum_Case;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_pop;
use function assert;
use function count;
use function explode;
use function in_array;
use function reset;
use function strtolower;
/**
 * @internal
 *
 * Handles information about class methods
 */
final class Methods
{
    public bool $collect_locations = false;
    public Method_Return_Type_Provider $return_type_provider;
    public Method_Params_Provider $params_provider;
    public Method_Existence_Provider $existence_provider;
    public Method_Visibility_Provider $visibility_provider;
    public function __construct(private readonly Class_Like_Storage_Provider $classlike_storage_provider, public File_Reference_Provider $file_reference_provider, private readonly Class_Likes $classlikes)
    {
        $this->return_type_provider = new Method_Return_Type_Provider();
        $this->existence_provider = new Method_Existence_Provider();
        $this->visibility_provider = new Method_Visibility_Provider();
        $this->params_provider = new Method_Params_Provider();
    }
    /**
     * Whether or not a given method exists
     *
     * If you pass true in $is_used argument the method return is considered used
     *
     * @param lowercase-string|null $calling_method_id
     */
    public function method_exists(Method_Identifier $method_id, ?string $calling_method_id = null, ?Code_Location $code_location = null, ?Statements_Source $source = null, ?string $source_file_path = null, bool $use_method_existence_provider = true, bool $is_used = false, bool $with_pseudo = false): bool
    {
        $fq_class_name = $method_id->fq_class_name;
        $method_name = $method_id->method_name;
        if ($use_method_existence_provider && $this->existence_provider->has($fq_class_name)) {
            $method_exists = $this->existence_provider->does_method_exist($fq_class_name, $method_name, $source, $code_location);
            if ($method_exists !== null) {
                return $method_exists;
            }
        }
        $old_method_id = null;
        $fq_class_name = strtolower($this->classlikes->get_un_aliased_name($fq_class_name));
        try {
            $class_storage = $this->classlike_storage_provider->get($fq_class_name);
        } catch (InvalidArgumentException) {
            return false;
        }
        if ($class_storage->is_enum) {
            if ($method_name === 'cases') {
                return true;
            }
            if ($class_storage->enum_type && in_array($method_name, ['from', 'tryFrom'], true)) {
                return true;
            }
        }
        $source_file_path = $source ? $source->get_file_path() : $source_file_path;
        $calling_class_name = $source ? $source->get_fqcln() : null;
        if (!$calling_class_name && $calling_method_id) {
            $calling_class_name = explode('::', $calling_method_id)[0];
        }
        $declaring_method_id = $class_storage->declaring_method_ids[$method_name] ?? null;
        if ($declaring_method_id === null && $with_pseudo) {
            $declaring_method_id = $class_storage->declaring_pseudo_method_ids[$method_name] ?? null;
        }
        if ($declaring_method_id !== null) {
            if ($calling_method_id === strtolower((string) $declaring_method_id)) {
                return true;
            }
            $declaring_fq_class_name = strtolower($declaring_method_id->fq_class_name);
            if ($declaring_fq_class_name !== strtolower((string) $calling_class_name)) {
                if ($calling_method_id) {
                    $this->file_reference_provider->add_method_reference_to_class($calling_method_id, $declaring_fq_class_name);
                } elseif ($source_file_path) {
                    $this->file_reference_provider->add_non_method_reference_to_class($source_file_path, $declaring_fq_class_name);
                }
            }
            if ((string) $method_id !== (string) $declaring_method_id && $class_storage->user_defined && isset($class_storage->potential_declaring_method_ids[$method_name])) {
                foreach ($class_storage->potential_declaring_method_ids[$method_name] as $potential_id => $_) {
                    if ($calling_method_id) {
                        $this->file_reference_provider->add_method_reference_to_class_member($calling_method_id, $potential_id, $is_used);
                    } elseif ($source_file_path) {
                        $this->file_reference_provider->add_file_reference_to_class_member($source_file_path, $potential_id, $is_used);
                    }
                }
            } else if ($calling_method_id) {
                $this->file_reference_provider->add_method_reference_to_class_member($calling_method_id, strtolower((string) $declaring_method_id), $is_used);
            } elseif ($source_file_path) {
                $this->file_reference_provider->add_file_reference_to_class_member($source_file_path, strtolower((string) $declaring_method_id), $is_used);
            }
            if ($this->collect_locations && $code_location) {
                $this->file_reference_provider->add_calling_location_for_class_method($code_location, strtolower((string) $declaring_method_id));
            }
            foreach ($class_storage->class_implements as $fq_interface_name) {
                $interface_method_id_lc = strtolower($fq_interface_name . '::' . $method_name);
                if ($this->collect_locations && $code_location) {
                    $this->file_reference_provider->add_calling_location_for_class_method($code_location, $interface_method_id_lc);
                }
                if ($calling_method_id) {
                    $this->file_reference_provider->add_method_reference_to_class_member($calling_method_id, $interface_method_id_lc, $is_used);
                } elseif ($source_file_path) {
                    $this->file_reference_provider->add_file_reference_to_class_member($source_file_path, $interface_method_id_lc, $is_used);
                }
            }
            $declaring_method_class = $declaring_method_id->fq_class_name;
            $declaring_method_name = $declaring_method_id->method_name;
            $declaring_class_storage = $this->classlike_storage_provider->get($declaring_method_class);
            if (isset($declaring_class_storage->overridden_method_ids[$declaring_method_name])) {
                $overridden_method_ids = $declaring_class_storage->overridden_method_ids[$declaring_method_name];
                foreach ($overridden_method_ids as $overridden_method_id) {
                    if ($this->collect_locations && $code_location) {
                        $this->file_reference_provider->add_calling_location_for_class_method($code_location, strtolower((string) $overridden_method_id));
                    }
                    if ($calling_method_id) {
                        // also store failures in case the method is added later
                        $this->file_reference_provider->add_method_reference_to_class_member($calling_method_id, strtolower((string) $overridden_method_id), $is_used);
                    } elseif ($source_file_path) {
                        $this->file_reference_provider->add_file_reference_to_class_member($source_file_path, strtolower((string) $overridden_method_id), $is_used);
                    }
                }
            }
            return true;
        }
        if ($source_file_path && $fq_class_name !== strtolower((string) $calling_class_name)) {
            if ($calling_method_id) {
                $this->file_reference_provider->add_method_reference_to_class($calling_method_id, $fq_class_name);
            } else {
                $this->file_reference_provider->add_non_method_reference_to_class($source_file_path, $fq_class_name);
            }
        }
        if ($class_storage->abstract && isset($class_storage->overridden_method_ids[$method_name])) {
            return true;
        }
        // support checking oldstyle constructors
        if ($method_name === '__construct') {
            $method_name_parts = explode('\\', $fq_class_name);
            $old_constructor_name = array_pop($method_name_parts);
            $old_method_id = $fq_class_name . '::' . $old_constructor_name;
        }
        if (!$class_storage->user_defined && (Internal_Call_Map_Handler::in_call_map((string) $method_id) || $old_method_id && Internal_Call_Map_Handler::in_call_map($old_method_id))) {
            return true;
        }
        foreach ($class_storage->parent_classes + $class_storage->used_traits as $potential_future_declaring_fqcln) {
            $potential_id = strtolower($potential_future_declaring_fqcln) . '::' . $method_name;
            if ($calling_method_id) {
                // also store failures in case the method is added later
                $this->file_reference_provider->add_method_reference_to_missing_class_member($calling_method_id, $potential_id);
            } elseif ($source_file_path) {
                $this->file_reference_provider->add_file_reference_to_missing_class_member($source_file_path, $potential_id);
            }
        }
        if ($calling_method_id) {
            // also store failures in case the method is added later
            $this->file_reference_provider->add_method_reference_to_missing_class_member($calling_method_id, strtolower((string) $method_id));
        } elseif ($source_file_path) {
            $this->file_reference_provider->add_file_reference_to_missing_class_member($source_file_path, strtolower((string) $method_id));
        }
        return false;
    }
    /**
     * @param  list<PhpParser\Node\Arg> $args
     * @return list<FunctionLikeParameter>
     */
    public function get_method_params(Method_Identifier $method_id, ?Statements_Source $source = null, ?array $args = null, ?Context $context = null): array
    {
        $fq_class_name = $method_id->fq_class_name;
        $method_name = $method_id->method_name;
        if ($this->params_provider->has($fq_class_name)) {
            $method_params = $this->params_provider->get_method_params($fq_class_name, $method_name, $args, $source, $context);
            if ($method_params !== null) {
                return $method_params;
            }
        }
        $declaring_method_id = $this->get_declaring_method_id($method_id, true);
        $callmap_id = $declaring_method_id ?? $method_id;
        // functions
        if (Internal_Call_Map_Handler::in_call_map((string) $callmap_id)) {
            $class_storage = $this->classlike_storage_provider->get($callmap_id->fq_class_name);
            $declaring_method_name = $declaring_method_id->method_name ?? $method_name;
            if (!$class_storage->stubbed || empty($class_storage->methods[$declaring_method_name]->stubbed)) {
                $function_callables = Internal_Call_Map_Handler::get_callables_from_call_map((string) $callmap_id);
                if ($function_callables === null) {
                    throw new UnexpectedValueException('Not expecting $function_callables to be null for ' . $callmap_id);
                }
                if (!$source || $args === null || count($function_callables) === 1) {
                    assert($function_callables[0]->params !== null);
                    return $function_callables[0]->params;
                }
                if ($context && $source instanceof Statements_Analyzer) {
                    $was_inside_call = $context->inside_call;
                    $context->inside_call = true;
                    foreach ($args as $arg) {
                        Expression_Analyzer::analyze($source, $arg->value, $context);
                    }
                    $context->inside_call = $was_inside_call;
                }
                $matching_callable = Internal_Call_Map_Handler::get_matching_callable_from_call_map_options($source->get_codebase(), $function_callables, $args, $source->get_node_type_provider(), (string) $callmap_id);
                assert($matching_callable->params !== null);
                return $matching_callable->params;
            }
        }
        if ($declaring_method_id) {
            $storage = $this->get_storage($declaring_method_id, true);
            $params = $storage->params;
            if ($storage->has_docblock_param_types) {
                return $params;
            }
            $appearing_method_id = $this->get_appearing_method_id($declaring_method_id);
            if (!$appearing_method_id) {
                return $params;
            }
            $appearing_fq_class_name = $appearing_method_id->fq_class_name;
            $appearing_method_name = $appearing_method_id->method_name;
            $class_storage = $this->classlike_storage_provider->get($appearing_fq_class_name);
            if (!isset($class_storage->overridden_method_ids[$appearing_method_name])) {
                return $params;
            }
            if (!isset($class_storage->documenting_method_ids[$appearing_method_name])) {
                return $params;
            }
            $overridden_method_id = $class_storage->documenting_method_ids[$appearing_method_name];
            $overridden_storage = $this->get_storage($overridden_method_id);
            $overriding_fq_class_name = $overridden_method_id->fq_class_name;
            foreach ($params as $i => $param) {
                if (isset($overridden_storage->params[$i]->type) && $overridden_storage->params[$i]->has_docblock_type) {
                    $params[$i] = clone $param;
                    /** @var Union $params[$i]->type */
                    $params[$i]->type = $overridden_storage->params[$i]->type;
                    if ($source) {
                        $overridden_class_storage = $this->classlike_storage_provider->get($overriding_fq_class_name);
                        $params[$i]->type = self::localize_type($source->get_codebase(), $params[$i]->type, $appearing_fq_class_name, $overridden_class_storage->name);
                    }
                    if ($params[$i]->signature_type && $params[$i]->signature_type->is_nullable()) {
                        $params[$i]->type = $params[$i]->type->get_builder()->add_type(new T_Null())->freeze();
                    }
                    $params[$i]->type_location = $overridden_storage->params[$i]->type_location;
                }
            }
            return $params;
        }
        throw new UnexpectedValueException('Cannot get method params for ' . $method_id);
    }
    public static function localize_type(Codebase $codebase, Union $type, string $appearing_fq_class_name, string $base_fq_class_name): Union
    {
        $class_storage = $codebase->classlike_storage_provider->get($appearing_fq_class_name);
        $extends = $class_storage->template_extended_params;
        if (!$extends) {
            return $type;
        }
        (new Type_Localizer($extends, $base_fq_class_name))->traverse($type);
        return $type;
    }
    /**
     * @param array<string, array<string, Union>> $extends
     * @return list<Atomic>
     */
    public static function get_extended_templated_types(T_Template_Param $atomic_type, array $extends): array
    {
        $extra_added_types = [];
        if (isset($extends[$atomic_type->defining_class][$atomic_type->param_name])) {
            $extended_param = $extends[$atomic_type->defining_class][$atomic_type->param_name];
            foreach ($extended_param->get_atomic_types() as $extended_atomic_type) {
                if ($extended_atomic_type instanceof T_Template_Param) {
                    $extra_added_types = [...$extra_added_types, ...self::get_extended_templated_types($extended_atomic_type, $extends)];
                } else {
                    $extra_added_types[] = $extended_atomic_type;
                }
            }
        } else {
            $extra_added_types[] = $atomic_type;
        }
        return $extra_added_types;
    }
    public function is_variadic(Method_Identifier $method_id): bool
    {
        $declaring_method_id = $this->get_declaring_method_id($method_id);
        if (!$declaring_method_id) {
            return false;
        }
        return $this->get_storage($declaring_method_id)->variadic;
    }
    /**
     * @param  list<PhpParser\Node\Arg>|null $args
     */
    public function get_method_return_type(Method_Identifier $method_id, ?string &$self_class, ?Source_Analyzer $source_analyzer = null, ?array $args = null, ?Template_Result $template_result = null): ?Union
    {
        $original_fq_class_name = $method_id->fq_class_name;
        $original_method_name = $method_id->method_name;
        $adjusted_fq_class_name = $this->classlikes->get_un_aliased_name($original_fq_class_name);
        if ($adjusted_fq_class_name !== $original_fq_class_name) {
            $original_fq_class_name = strtolower($adjusted_fq_class_name);
        }
        $original_class_storage = $this->classlike_storage_provider->get($original_fq_class_name);
        if (isset($original_class_storage->pseudo_methods[$original_method_name])) {
            return $original_class_storage->pseudo_methods[$original_method_name]->return_type;
        }
        $declaring_method_id = $this->get_declaring_method_id($method_id);
        if (!$declaring_method_id) {
            return null;
        }
        $appearing_method_id = $this->get_appearing_method_id($method_id);
        if (!$appearing_method_id) {
            $class_storage = $this->classlike_storage_provider->get($original_fq_class_name);
            if ($class_storage->abstract && isset($class_storage->overridden_method_ids[$original_method_name])) {
                $appearing_method_id = reset($class_storage->overridden_method_ids[$original_method_name]);
                assert($appearing_method_id !== false);
            } else {
                return null;
            }
        }
        $appearing_fq_class_name = $appearing_method_id->fq_class_name;
        $appearing_method_name = $appearing_method_id->method_name;
        $appearing_fq_class_storage = $this->classlike_storage_provider->get($appearing_fq_class_name);
        if ($appearing_fq_class_name === 'UnitEnum' && $original_class_storage->is_enum) {
            if ($original_method_name === 'cases') {
                if ($original_class_storage->enum_cases === []) {
                    return Type::get_empty_array();
                }
                $types = [];
                foreach ($original_class_storage->enum_cases as $case_name => $_) {
                    $types[] = new Union([new T_Enum_Case($original_fq_class_name, $case_name)]);
                }
                $list = new T_Keyed_Array($types, null, null, true);
                return new Union([$list]);
            }
        }
        if ($appearing_fq_class_name === 'BackedEnum' && $original_class_storage->is_enum && $original_class_storage->enum_type) {
            if (($original_method_name === 'from' || $original_method_name === 'tryfrom') && $source_analyzer && isset($args[0]) && $first_arg_type = $source_analyzer->get_node_type_provider()->get_type($args[0]->value)) {
                $types = [];
                foreach ($original_class_storage->enum_cases as $case_name => $case_storage) {
                    $case_value = $case_storage->get_value($this->classlikes);
                    if (Union_Type_Comparator::is_contained_by(
                        $source_analyzer->get_codebase(),
                        // XXX: why TString? Perhaps it should be string|int?
                        new Union([$case_value ?? new T_String()]),
                        $first_arg_type
                    )) {
                        $types[] = new T_Enum_Case($original_fq_class_name, $case_name);
                    }
                }
                if ($types) {
                    if ($original_method_name === 'tryfrom') {
                        $types[] = new T_Null();
                    }
                    return new Union($types);
                }
                return $original_method_name === 'tryfrom' ? Type::get_null() : Type::get_never();
            }
        }
        if (!$appearing_fq_class_storage->user_defined && !$appearing_fq_class_storage->stubbed && Internal_Call_Map_Handler::in_call_map((string) $appearing_method_id)) {
            if ((string) $appearing_method_id === 'Closure::fromcallable' && isset($args[0]) && $source_analyzer && ($first_arg_type = $source_analyzer->get_node_type_provider()->get_type($args[0]->value)) && $first_arg_type->is_single()) {
                foreach ($first_arg_type->get_atomic_types() as $atomic_type) {
                    if ($atomic_type instanceof T_Callable || $atomic_type instanceof T_Closure) {
                        $callable_type = $atomic_type;
                        return new Union([new T_Closure('Closure', $callable_type->params, $callable_type->return_type)]);
                    }
                    if ($atomic_type instanceof T_Named_Object && $this->method_exists(new Method_Identifier($atomic_type->value, '__invoke'))) {
                        $invokable_storage = $this->get_storage(new Method_Identifier($atomic_type->value, '__invoke'));
                        return new Union([new T_Closure('Closure', $invokable_storage->params, $invokable_storage->return_type)]);
                    }
                }
            }
            $callmap_callables = Internal_Call_Map_Handler::get_callables_from_call_map((string) $appearing_method_id);
            if (!$callmap_callables || $callmap_callables[0]->return_type === null) {
                throw new UnexpectedValueException('Shouldn’t get here');
            }
            $return_type_candidate = $callmap_callables[0]->return_type;
            if ($return_type_candidate->is_falsable()) {
                return $return_type_candidate->set_properties(['ignore_falsable_issues' => true]);
            }
            return $return_type_candidate;
        }
        $class_storage = $this->classlike_storage_provider->get($appearing_fq_class_name);
        $storage = $this->get_storage($declaring_method_id);
        $candidate_type = $storage->return_type;
        if ($candidate_type && $candidate_type->is_void()) {
            return $candidate_type;
        }
        if (isset($class_storage->documenting_method_ids[$appearing_method_name])) {
            $overridden_method_id = $class_storage->documenting_method_ids[$appearing_method_name];
            // special override to allow inference of Iterator types
            if ($overridden_method_id->fq_class_name === 'Iterator' && $storage->return_type && $storage->return_type === $storage->signature_return_type) {
                return $storage->return_type;
            }
            $overridden_storage = $this->get_storage($overridden_method_id);
            if ($overridden_storage->return_type) {
                if ($overridden_storage->return_type->is_null()) {
                    return Type::get_void();
                }
                if (!$candidate_type || !$source_analyzer) {
                    $self_class = $overridden_method_id->fq_class_name;
                    return $overridden_storage->return_type;
                }
                if ($candidate_type->get_id() === $overridden_storage->return_type->get_id()) {
                    $self_class = $appearing_fq_class_storage->name;
                    return $candidate_type;
                }
                $overridden_class_storage = $this->classlike_storage_provider->get($overridden_method_id->fq_class_name);
                $overridden_storage_return_type = Type_Expander::expand_union($source_analyzer->get_codebase(), $overridden_storage->return_type, $overridden_method_id->fq_class_name, $appearing_fq_class_name, $overridden_class_storage->parent_class, true, false, $storage->final);
                $old_contained_by_new = Union_Type_Comparator::is_contained_by($source_analyzer->get_codebase(), $candidate_type, $overridden_storage_return_type);
                $new_contained_by_old = Union_Type_Comparator::is_contained_by($source_analyzer->get_codebase(), $overridden_storage_return_type, $candidate_type);
                if (!$old_contained_by_new && !$new_contained_by_old || $old_contained_by_new && $new_contained_by_old) {
                    $found_generic_params = Class_Template_Param_Collector::collect($source_analyzer->get_codebase(), $appearing_fq_class_storage, $appearing_fq_class_storage, $appearing_method_name, null, true);
                    if ($found_generic_params) {
                        $passed_template_result = $template_result;
                        $template_result = new Template_Result([], $found_generic_params);
                        if ($passed_template_result !== null) {
                            $template_result = $template_result->merge($passed_template_result);
                        }
                        $overridden_storage_return_type = Template_Inferred_Type_Replacer::replace($overridden_storage_return_type, $template_result, $source_analyzer->get_codebase());
                    }
                    $attempted_intersection = null;
                    if ($old_contained_by_new) {
                        //implicitly $new_contained_by_old as well
                        try {
                            $attempted_intersection = Type::intersect_union_types($candidate_type, $overridden_storage_return_type, $source_analyzer->get_codebase());
                        } catch (InvalidArgumentException) {
                            // TODO: fix
                        }
                    } else {
                        $attempted_intersection = Type::intersect_union_types($overridden_storage_return_type, $candidate_type, $source_analyzer->get_codebase());
                    }
                    if ($attempted_intersection) {
                        $self_class = $overridden_method_id->fq_class_name;
                        return $attempted_intersection;
                    }
                    $self_class = $appearing_fq_class_storage->name;
                    return $candidate_type;
                }
                if ($old_contained_by_new) {
                    $self_class = $appearing_fq_class_storage->name;
                    return $candidate_type;
                }
                $self_class = $overridden_method_id->fq_class_name;
                return $overridden_storage_return_type;
            }
        }
        if ($candidate_type) {
            $self_class = $appearing_fq_class_storage->name;
            return $candidate_type;
        }
        if (!isset($class_storage->overridden_method_ids[$appearing_method_name])) {
            return null;
        }
        foreach ($class_storage->overridden_method_ids[$appearing_method_name] as $overridden_method_id) {
            $overridden_storage = $this->get_storage($overridden_method_id);
            if ($overridden_storage->return_type) {
                if ($overridden_storage->return_type->is_null()) {
                    if ($candidate_type && !$candidate_type->is_void()) {
                        return null;
                    }
                    $candidate_type = Type::get_void();
                    continue;
                }
                $fq_overridden_class = $overridden_method_id->fq_class_name;
                $overridden_class_storage = $this->classlike_storage_provider->get($fq_overridden_class);
                $overridden_return_type = $overridden_storage->return_type;
                $self_class = $overridden_class_storage->name;
                if ($candidate_type && $source_analyzer && !$candidate_type->is_mixed()) {
                    $old_contained_by_new = Union_Type_Comparator::is_contained_by($source_analyzer->get_codebase(), $candidate_type, $overridden_return_type);
                    $new_contained_by_old = Union_Type_Comparator::is_contained_by($source_analyzer->get_codebase(), $overridden_return_type, $candidate_type);
                    if (!$old_contained_by_new && !$new_contained_by_old || $old_contained_by_new && $new_contained_by_old) {
                        $attempted_intersection = Type::intersect_union_types($candidate_type, $overridden_return_type, $source_analyzer->get_codebase());
                        if ($attempted_intersection) {
                            $candidate_type = $attempted_intersection;
                            continue;
                        }
                        return null;
                    }
                    if ($old_contained_by_new) {
                        continue;
                    }
                }
                $candidate_type = $overridden_return_type;
            }
        }
        return $candidate_type;
    }
    public function get_method_returns_by_ref(Method_Identifier $method_id): bool
    {
        $method_id = $this->get_declaring_method_id($method_id);
        if (!$method_id) {
            return false;
        }
        $fq_class_storage = $this->classlike_storage_provider->get($method_id->fq_class_name);
        if (!$fq_class_storage->user_defined && Internal_Call_Map_Handler::in_call_map((string) $method_id)) {
            return false;
        }
        return $this->get_storage($method_id)->returns_by_ref;
    }
    public function get_method_return_type_location(Method_Identifier $method_id, ?Code_Location &$defined_location = null): ?Code_Location
    {
        $method_id = $this->get_declaring_method_id($method_id);
        if ($method_id === null) {
            return null;
        }
        $storage = $this->get_storage($method_id);
        // if function exists in stubs and in analyzed code
        // use the return type location of the analyzed code instead of the stubbed location
        if ($storage->stubbed) {
            return null;
        }
        if (!$storage->return_type_location) {
            $overridden_method_ids = $this->get_overridden_method_ids($method_id);
            foreach ($overridden_method_ids as $overridden_method_id) {
                $overridden_storage = $this->get_storage($overridden_method_id);
                if ($overridden_storage->return_type_location && !$overridden_storage->stubbed) {
                    $defined_location = $overridden_storage->return_type_location;
                    break;
                }
            }
        }
        return $storage->return_type_location;
    }
    /**
     * @param lowercase-string $method_name_lc
     * @param lowercase-string $declaring_method_name_lc
     */
    public function set_declaring_method_id(string $fq_class_name, string $method_name_lc, string $declaring_fq_class_name, string $declaring_method_name_lc): void
    {
        $class_storage = $this->classlike_storage_provider->get($fq_class_name);
        $class_storage->declaring_method_ids[$method_name_lc] = new Method_Identifier($declaring_fq_class_name, $declaring_method_name_lc);
    }
    /**
     * @param lowercase-string $method_name_lc
     * @param lowercase-string $appearing_method_name_lc
     */
    public function set_appearing_method_id(string $fq_class_name, string $method_name_lc, string $appearing_fq_class_name, string $appearing_method_name_lc): void
    {
        $class_storage = $this->classlike_storage_provider->get($fq_class_name);
        $class_storage->appearing_method_ids[$method_name_lc] = new Method_Identifier($appearing_fq_class_name, $appearing_method_name_lc);
    }
    /** @psalm-mutation-free */
    public function get_declaring_method_id(Method_Identifier $method_id, bool $with_pseudo = false): ?Method_Identifier
    {
        $fq_class_name = $this->classlikes->get_un_aliased_name($method_id->fq_class_name);
        $class_storage = $this->classlike_storage_provider->get($fq_class_name);
        $method_name = $method_id->method_name;
        if (isset($class_storage->declaring_method_ids[$method_name])) {
            return $class_storage->declaring_method_ids[$method_name];
        }
        if ($class_storage->abstract && isset($class_storage->overridden_method_ids[$method_name])) {
            assert(!empty($class_storage->overridden_method_ids[$method_name]));
            return reset($class_storage->overridden_method_ids[$method_name]);
        }
        if ($with_pseudo && isset($class_storage->declaring_pseudo_method_ids[$method_name])) {
            return $class_storage->declaring_pseudo_method_ids[$method_name];
        }
        return null;
    }
    /**
     * Get the class this method appears in (vs is declared in, which could give a trait
     */
    public function get_appearing_method_id(Method_Identifier $method_id): ?Method_Identifier
    {
        $fq_class_name = $this->classlikes->get_un_aliased_name($method_id->fq_class_name);
        $class_storage = $this->classlike_storage_provider->get($fq_class_name);
        $method_name = $method_id->method_name;
        return $class_storage->appearing_method_ids[$method_name] ?? null;
    }
    /**
     * @return array<string, MethodIdentifier>
     */
    public function get_overridden_method_ids(Method_Identifier $method_id): array
    {
        $class_storage = $this->classlike_storage_provider->get($method_id->fq_class_name);
        $method_name = $method_id->method_name;
        return $class_storage->overridden_method_ids[$method_name] ?? [];
    }
    public function get_cased_method_id(Method_Identifier $original_method_id): string
    {
        $method_id = $this->get_declaring_method_id($original_method_id);
        if ($method_id === null) {
            return (string) $original_method_id;
        }
        $fq_class_name = $method_id->fq_class_name;
        $new_method_name = $method_id->method_name;
        $old_fq_class_name = $original_method_id->fq_class_name;
        $old_method_name = $original_method_id->method_name;
        $storage = $this->get_storage($method_id);
        if ($old_method_name === $new_method_name && strtolower($old_fq_class_name) !== $old_fq_class_name) {
            return $old_fq_class_name . '::' . $storage->cased_name;
        }
        return $fq_class_name . '::' . $storage->cased_name;
    }
    public function get_user_method_storage(Method_Identifier $method_id): ?Method_Storage
    {
        $declaring_method_id = $this->get_declaring_method_id($method_id, true);
        if (!$declaring_method_id) {
            if (Internal_Call_Map_Handler::in_call_map((string) $method_id)) {
                return null;
            }
            throw new UnexpectedValueException('$storage should not be null for ' . $method_id);
        }
        $storage = $this->get_storage($declaring_method_id, true);
        if (!$storage->location) {
            return null;
        }
        return $storage;
    }
    public function get_class_like_storage_for_method(Method_Identifier $method_id): Class_Like_Storage
    {
        $fq_class_name = $method_id->fq_class_name;
        $method_name = $method_id->method_name;
        if ($this->existence_provider->has($fq_class_name)) {
            if ($this->existence_provider->does_method_exist($fq_class_name, $method_name)) {
                return $this->classlike_storage_provider->get($fq_class_name);
            }
        }
        $declaring_method_id = $this->get_declaring_method_id($method_id);
        if ($declaring_method_id === null) {
            if (Internal_Call_Map_Handler::in_call_map((string) $method_id)) {
                $declaring_method_id = $method_id;
            } else {
                throw new UnexpectedValueException('$storage should not be null for ' . $method_id);
            }
        }
        $declaring_fq_class_name = $declaring_method_id->fq_class_name;
        return $this->classlike_storage_provider->get($declaring_fq_class_name);
    }
    /** @psalm-mutation-free */
    public function get_storage(Method_Identifier $method_id, bool $with_pseudo = false): Method_Storage
    {
        try {
            $class_storage = $this->classlike_storage_provider->get($method_id->fq_class_name);
        } catch (InvalidArgumentException $e) {
            throw new UnexpectedValueException($e->get_message());
        }
        $method_name = $method_id->method_name;
        $method_storage = $class_storage->methods[$method_name] ?? null;
        if ($method_storage === null && $with_pseudo) {
            $method_storage = $class_storage->pseudo_methods[$method_name] ?? $class_storage->pseudo_static_methods[$method_name] ?? null;
        }
        if ($method_storage === null) {
            throw new UnexpectedValueException('$storage should not be null for ' . $method_id);
        }
        return $method_storage;
    }
    /** @psalm-mutation-free */
    public function has_storage(Method_Identifier $method_id): bool
    {
        try {
            $class_storage = $this->classlike_storage_provider->get($method_id->fq_class_name);
        } catch (InvalidArgumentException) {
            return false;
        }
        $method_name = $method_id->method_name;
        if (!isset($class_storage->methods[$method_name])) {
            return false;
        }
        return true;
    }
}