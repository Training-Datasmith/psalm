<?php

declare (strict_types=1);
namespace Psalm\Internal\Type_Visitor;

use InvalidArgumentException;
use Override;
use Psalm\Code_Location;
use Psalm\Code_Location\Docblock_Type_Location;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Class_Like_Name_Options;
use Psalm\Internal\Analyzer\Method_Analyzer;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Internal\Type\Template_Bound;
use Psalm\Internal\Type\Template_Inferred_Type_Replacer;
use Psalm\Internal\Type\Template_Result;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Issue\Deprecated_Class;
use Psalm\Issue\Deprecated_Interface;
use Psalm\Issue\Invalid_Template_Param;
use Psalm\Issue\Missing_Template_Param;
use Psalm\Issue\Reserved_Word;
use Psalm\Issue\Too_Many_Template_Params;
use Psalm\Issue\Undefined_Constant;
use Psalm\Issue_Buffer;
use Psalm\Statements_Source;
use Psalm\Storage\Method_Storage;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Class_Constant;
use Psalm\Type\Atomic\T_Generic_Object;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Resource;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Mutable_Union;
use Psalm\Type\Type_Node;
use Psalm\Type\Type_Visitor;
use Psalm\Type\Union;
use ReflectionProperty;
use function array_keys;
use function array_search;
use function count;
use function md5;
use function str_contains;
use function str_starts_with;
use function strtolower;
/**
 * @internal
 */
final class Type_Checker extends Type_Visitor
{
    private bool $has_errors = false;
    /**
     * @param  array<string>    $suppressed_issues
     * @param  array<string, bool> $phantom_classes
     */
    public function __construct(private readonly Statements_Source $source, private readonly Code_Location $code_location, private readonly array $suppressed_issues, private array $phantom_classes = [], private readonly bool $inferred = true, private readonly bool $inherited = false, private bool $prevent_template_covariance = false, private readonly ?string $calling_method_id = null)
    {
    }
    /**
     * @return self::STOP_TRAVERSAL|self::DONT_TRAVERSE_CHILDREN|null
     */
    #[Override]
    protected function enter_node(Type_Node $type): ?int
    {
        if (!$type instanceof Atomic && !$type instanceof Union && !$type instanceof Mutable_Union) {
            return null;
        }
        if ($type->checked) {
            return self::DONT_TRAVERSE_CHILDREN;
        }
        if ($type instanceof T_Named_Object) {
            $this->check_named_object($type);
        } elseif ($type instanceof T_Class_Constant) {
            $this->check_scalar_class_constant($type);
        } elseif ($type instanceof T_Template_Param) {
            $this->check_template_param($type);
        } elseif ($type instanceof T_Resource) {
            $this->check_resource($type);
        }
        /** @psalm-suppress InaccessibleProperty Doesn't affect anything else */
        $type->checked = true;
        return null;
    }
    public function has_errors(): bool
    {
        return $this->has_errors;
    }
    private function check_named_object(T_Named_Object $atomic): void
    {
        $codebase = $this->source->get_codebase();
        if ($this->code_location instanceof Docblock_Type_Location && $codebase->store_node_types && $atomic->offset_start !== null && $atomic->offset_end !== null) {
            $codebase->analyzer->add_offset_reference($this->source->get_file_path(), $this->code_location->raw_file_start + $atomic->offset_start, $this->code_location->raw_file_start + $atomic->offset_end, $atomic->value);
        }
        if ($this->calling_method_id && $atomic->text !== null) {
            $codebase->file_reference_provider->add_method_reference_to_class_member($this->calling_method_id, 'use:' . $atomic->text . ':' . md5($this->source->get_file_path()), false);
        }
        if (!isset($this->phantom_classes[strtolower($atomic->value)]) && Class_Like_Analyzer::check_fully_qualified_class_like_name($this->source, $atomic->value, $this->code_location, $this->source->get_fqcln(), $this->calling_method_id, $this->suppressed_issues, new Class_Like_Name_Options($this->inferred, false, true, true, $atomic->from_docblock)) === false) {
            $this->has_errors = true;
            return;
        }
        $fq_class_name_lc = strtolower($atomic->value);
        if (!$this->inherited && $codebase->classlike_storage_provider->has($fq_class_name_lc) && $this->source->get_fqcln() !== $atomic->value) {
            $class_storage = $codebase->classlike_storage_provider->get($fq_class_name_lc);
            if ($class_storage->deprecated) {
                if ($class_storage->is_interface) {
                    Issue_Buffer::maybe_add(new Deprecated_Interface('Interface ' . $atomic->value . ' is marked as deprecated', $this->code_location, $atomic->value), $this->source->get_suppressed_issues() + $this->suppressed_issues);
                } else {
                    Issue_Buffer::maybe_add(new Deprecated_Class('Class ' . $atomic->value . ' is marked as deprecated', $this->code_location, $atomic->value), $this->source->get_suppressed_issues() + $this->suppressed_issues);
                }
            }
        }
        if ($atomic instanceof T_Generic_Object) {
            $this->check_generic_params($atomic);
        }
    }
    private function check_generic_params(T_Generic_Object $atomic): void
    {
        $codebase = $this->source->get_codebase();
        try {
            $class_storage = $codebase->classlike_storage_provider->get(strtolower($atomic->value));
        } catch (InvalidArgumentException) {
            return;
        }
        $expected_type_params = $class_storage->template_types ?: [];
        $expected_param_covariants = $class_storage->template_covariants;
        $template_type_count = count($expected_type_params);
        $template_param_count = count($atomic->type_params);
        if ($template_type_count > $template_param_count) {
            Issue_Buffer::maybe_add(new Missing_Template_Param($atomic->value . ' has missing template params, expecting ' . $template_type_count, $this->code_location), $this->suppressed_issues);
        } elseif ($template_type_count < $template_param_count) {
            Issue_Buffer::maybe_add(new Too_Many_Template_Params($atomic->get_id() . ' has too many template params, expecting ' . $template_type_count, $this->code_location), $this->suppressed_issues);
        }
        $expected_type_param_keys = array_keys($expected_type_params);
        $template_result = new Template_Result($expected_type_params, []);
        foreach ($atomic->type_params as $i => $type_param) {
            $this->prevent_template_covariance = $this->source instanceof Method_Analyzer && $this->source->get_method_name() !== '__construct' && empty($expected_param_covariants[$i]);
            if (isset($expected_type_param_keys[$i])) {
                $expected_template_name = $expected_type_param_keys[$i];
                foreach ($expected_type_params[$expected_template_name] as $defining_class => $expected_type_param) {
                    $expected_type_param = Template_Inferred_Type_Replacer::replace(Type_Expander::expand_union($codebase, $expected_type_param, $defining_class, null, null), $template_result, $codebase);
                    $type_param = Type_Expander::expand_union($codebase, $type_param, $defining_class, null, null);
                    if (!Union_Type_Comparator::is_contained_by($codebase, $type_param, $expected_type_param)) {
                        Issue_Buffer::maybe_add(new Invalid_Template_Param('Extended template param ' . $expected_template_name . ' of ' . $atomic->get_id() . ' expects type ' . $expected_type_param->get_id() . ', type ' . $type_param->get_id() . ' given', $this->code_location), $this->suppressed_issues);
                    } else {
                        $template_result->lower_bounds[$expected_template_name][$defining_class][] = new Template_Bound($type_param);
                    }
                }
            }
        }
    }
    public function check_scalar_class_constant(T_Class_Constant $atomic): void
    {
        $fq_classlike_name = $atomic->fq_classlike_name === 'self' ? $this->source->get_class_name() : $atomic->fq_classlike_name;
        if (!$fq_classlike_name) {
            return;
        }
        if (Class_Like_Analyzer::check_fully_qualified_class_like_name($this->source, $fq_classlike_name, $this->code_location, null, null, $this->suppressed_issues, new Class_Like_Name_Options($this->inferred, false, true, true, $atomic->from_docblock)) === false) {
            $this->has_errors = true;
            return;
        }
        $const_name = $atomic->const_name;
        if (str_contains($const_name, '*')) {
            Type_Expander::expand_atomic($this->source->get_codebase(), $atomic, $fq_classlike_name, $fq_classlike_name, null, true, true);
            $is_defined = true;
        } else {
            $class_constant_type = $this->source->get_codebase()->classlikes->get_class_constant_type($fq_classlike_name, $atomic->const_name, ReflectionProperty::IS_PRIVATE);
            $is_defined = null !== $class_constant_type;
        }
        if (!$is_defined) {
            Issue_Buffer::maybe_add(new Undefined_Constant('Constant ' . $fq_classlike_name . '::' . $const_name . ' is not defined', $this->code_location), $this->source->get_suppressed_issues());
        }
    }
    public function check_template_param(T_Template_Param $atomic): void
    {
        if ($this->prevent_template_covariance && !str_starts_with($atomic->defining_class, 'fn-') && $atomic->defining_class !== 'class-string-map') {
            $codebase = $this->source->get_codebase();
            $class_storage = $codebase->classlike_storage_provider->get($atomic->defining_class);
            $template_offset = $class_storage->template_types ? array_search($atomic->param_name, array_keys($class_storage->template_types), true) : false;
            if ($template_offset !== false && isset($class_storage->template_covariants[$template_offset]) && $class_storage->template_covariants[$template_offset]) {
                $method_storage = $this->source instanceof Method_Analyzer ? $this->source->get_function_like_storage() : null;
                if ($method_storage instanceof Method_Storage && $method_storage->mutation_free && !$method_storage->mutation_free_inferred) {
                    // do nothing
                } else {
                    Issue_Buffer::maybe_add(new Invalid_Template_Param('Template param ' . $atomic->param_name . ' of ' . $atomic->defining_class . ' is marked covariant and cannot be used here', $this->code_location), $this->source->get_suppressed_issues());
                }
            }
        }
    }
    public function check_resource(T_Resource $atomic): void
    {
        if (!$atomic->from_docblock) {
            Issue_Buffer::maybe_add(new Reserved_Word('\'resource\' is a reserved word', $this->code_location, 'resource'), $this->source->get_suppressed_issues());
        }
    }
}