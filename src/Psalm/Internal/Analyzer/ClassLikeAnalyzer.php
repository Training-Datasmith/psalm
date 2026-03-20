<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer;

use InvalidArgumentException;
use Override;
use Php_Parser;
use Psalm\Aliases;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\File_Manipulation\File_Manipulation_Buffer;
use Psalm\Internal\Provider\Node_Data_Provider;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Internal\Type\Template_Result;
use Psalm\Internal\Type\Template_Standin_Type_Replacer;
use Psalm\Issue\Inaccessible_Property;
use Psalm\Issue\Invalid_Class;
use Psalm\Issue\Invalid_Template_Param;
use Psalm\Issue\Missing_Dependency;
use Psalm\Issue\Missing_Template_Param;
use Psalm\Issue\Reserved_Word;
use Psalm\Issue\Too_Many_Template_Params;
use Psalm\Issue\Undefined_Attribute_Class;
use Psalm\Issue\Undefined_Class;
use Psalm\Issue\Undefined_Docblock_Class;
use Psalm\Issue_Buffer;
use Psalm\Plugin\Event_Handler\Event\After_Class_Like_Existence_Check_Event;
use Psalm\Statements_Source;
use Psalm\Storage\Class_Like_Storage;
use Psalm\Type;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_keys;
use function array_pop;
use function array_search;
use function count;
use function explode;
use function gettype;
use function in_array;
use function preg_match;
use function preg_replace;
use function strtolower;
/**
 * @internal
 */
abstract class Class_Like_Analyzer extends Source_Analyzer
{
    public const VISIBILITY_PUBLIC = 1;
    public const VISIBILITY_PROTECTED = 2;
    public const VISIBILITY_PRIVATE = 3;
    public const SPECIAL_TYPES = ['int' => 'int', 'string' => 'string', 'float' => 'float', 'bool' => 'bool', 'false' => 'false', 'object' => 'object', 'never' => 'never', 'callable' => 'callable', 'array' => 'array', 'iterable' => 'iterable', 'null' => 'null', 'mixed' => 'mixed'];
    public const GETTYPE_TYPES = ['boolean' => true, 'integer' => true, 'double' => true, 'string' => true, 'array' => true, 'object' => true, 'resource' => true, 'resource (closed)' => true, 'NULL' => true, 'unknown type' => true];
    public File_Analyzer $file_analyzer;
    /**
     * The parent class
     */
    protected ?string $parent_fq_class_name = null;
    protected Class_Like_Storage $storage;
    public function __construct(protected Php_Parser\Node\Stmt\Class_Like $class, Source_Analyzer $source, protected string $fq_class_name)
    {
        $this->source = $source;
        $this->file_analyzer = $source->get_file_analyzer();
        $codebase = $source->get_codebase();
        $this->storage = $codebase->classlike_storage_provider->get($fq_class_name);
    }
    #[Override]
    public function __destruct()
    {
        unset($this->source);
        unset($this->file_analyzer);
    }
    public function get_method_mutations(string $method_name, Context $context): void
    {
        $project_analyzer = $this->get_file_analyzer()->project_analyzer;
        $codebase = $project_analyzer->get_codebase();
        foreach ($this->class->stmts as $stmt) {
            if ($stmt instanceof Php_Parser\Node\Stmt\Class_Method && strtolower($stmt->name->name) === strtolower($method_name)) {
                $method_analyzer = new Method_Analyzer($stmt, $this);
                $method_analyzer->analyze($context, new Node_Data_Provider(), null, true);
                $context->clauses = [];
            } elseif ($stmt instanceof Php_Parser\Node\Stmt\Trait_Use) {
                foreach ($stmt->traits as $trait) {
                    $fq_trait_name = self::get_fqcln_from_name_object($trait, $this->source->get_aliases());
                    $trait_file_analyzer = $project_analyzer->get_file_analyzer_for_class_like($fq_trait_name);
                    $trait_node = $codebase->classlikes->get_trait_node($fq_trait_name);
                    $trait_storage = $codebase->classlike_storage_provider->get($fq_trait_name);
                    $trait_aliases = $trait_storage->aliases;
                    if ($trait_aliases === null) {
                        continue;
                    }
                    $trait_analyzer = new Trait_Analyzer($trait_node, $trait_file_analyzer, $fq_trait_name, $trait_aliases);
                    foreach ($trait_node->stmts as $trait_stmt) {
                        if ($trait_stmt instanceof Php_Parser\Node\Stmt\Class_Method && strtolower($trait_stmt->name->name) === strtolower($method_name)) {
                            $method_analyzer = new Method_Analyzer($trait_stmt, $trait_analyzer);
                            $actual_method_id = $method_analyzer->get_method_id();
                            if ($context->self && $context->self !== $this->fq_class_name) {
                                $analyzed_method_id = $method_analyzer->get_method_id($context->self);
                                $declaring_method_id = $codebase->methods->get_declaring_method_id($analyzed_method_id);
                                if ((string) $actual_method_id !== (string) $declaring_method_id) {
                                    break;
                                }
                            }
                            $method_analyzer->analyze($context, new Node_Data_Provider(), null, true);
                        }
                    }
                    $trait_file_analyzer->clear_source_before_destruction();
                }
            }
        }
    }
    public function get_function_like_analyzer(string $method_name): ?Method_Analyzer
    {
        foreach ($this->class->stmts as $stmt) {
            if ($stmt instanceof Php_Parser\Node\Stmt\Class_Method && strtolower($stmt->name->name) === strtolower($method_name)) {
                return new Method_Analyzer($stmt, $this);
            }
        }
        return null;
    }
    /**
     * @param  array<string>    $suppressed_issues
     */
    public static function check_fully_qualified_class_like_name(Statements_Source $statements_source, string $fq_class_name, Code_Location $code_location, ?string $calling_fq_class_name, ?string $calling_method_id, array $suppressed_issues, ?Class_Like_Name_Options $options = null, bool $check_classes = true): ?bool
    {
        if ($options === null) {
            $options = new Class_Like_Name_Options();
        }
        $codebase = $statements_source->get_codebase();
        if ($fq_class_name === '') {
            if (Issue_Buffer::accepts(new Undefined_Class('Class or interface <empty string> does not exist', $code_location, 'empty string'), $suppressed_issues)) {
                return false;
            }
            return null;
        }
        $fq_class_name = (string) preg_replace('/^\\\\/', '', $fq_class_name, 1);
        if (in_array($fq_class_name, ['callable', 'iterable', 'self', 'static', 'parent'], true)) {
            return true;
        }
        if (preg_match('/(^|\\\\)(int|float|bool|string|void|null|false|true|object|mixed)$/i', $fq_class_name) || strtolower($fq_class_name) === 'resource') {
            $class_name_parts = explode('\\', $fq_class_name);
            $class_name = array_pop($class_name_parts);
            Issue_Buffer::maybe_add(new Reserved_Word($class_name . ' is a reserved word', $code_location, $class_name), $suppressed_issues);
            return null;
        }
        $class_exists = $codebase->classlikes->class_exists($fq_class_name, !$options->inferred ? $code_location : null, $calling_fq_class_name, $calling_method_id);
        $interface_exists = $codebase->classlikes->interface_exists($fq_class_name, !$options->inferred ? $code_location : null, $calling_fq_class_name, $calling_method_id);
        $enum_exists = $codebase->classlikes->enum_exists($fq_class_name, !$options->inferred ? $code_location : null, $calling_fq_class_name, $calling_method_id);
        if (!$class_exists && !($interface_exists && $options->allow_interface) && !($enum_exists && $options->allow_enum)) {
            if (!$check_classes) {
                return null;
            }
            if (!$options->allow_trait || !$codebase->classlikes->trait_exists($fq_class_name, $code_location)) {
                if ($options->from_docblock) {
                    if (Issue_Buffer::accepts(new Undefined_Docblock_Class('Docblock-defined class, interface or enum named ' . $fq_class_name . ' does not exist', $code_location, $fq_class_name), $suppressed_issues)) {
                        return false;
                    }
                } elseif ($options->from_attribute) {
                    if (Issue_Buffer::accepts(new Undefined_Attribute_Class('Attribute class ' . $fq_class_name . ' does not exist', $code_location, $fq_class_name), $suppressed_issues)) {
                        return false;
                    }
                } else if (Issue_Buffer::accepts(new Undefined_Class('Class, interface or enum named ' . $fq_class_name . ' does not exist', $code_location, $fq_class_name), $suppressed_issues)) {
                    return false;
                }
            }
            return null;
        }
        $aliased_name = $codebase->classlikes->get_un_aliased_name($fq_class_name);
        try {
            $class_storage = $codebase->classlike_storage_provider->get($aliased_name);
        } catch (InvalidArgumentException $e) {
            if (!$options->inferred) {
                throw $e;
            }
            return null;
        }
        foreach ($class_storage->invalid_dependencies as $dependency_class_name => $_) {
            // if the implemented/extended class is stubbed, it may not yet have
            // been hydrated
            if ($codebase->classlike_storage_provider->has($dependency_class_name)) {
                continue;
            }
            if (Issue_Buffer::accepts(new Missing_Dependency($fq_class_name . ' depends on class or interface ' . $dependency_class_name . ' that does not exist', $code_location, $fq_class_name), $suppressed_issues)) {
                return false;
            }
        }
        if (!$options->inferred) {
            if ($class_exists && !$codebase->class_has_correct_casing($fq_class_name) || $interface_exists && !$codebase->interface_has_correct_casing($fq_class_name) || $enum_exists && !$codebase->classlikes->enum_has_correct_casing($fq_class_name)) {
                Issue_Buffer::maybe_add(new Invalid_Class('Class, interface or enum ' . $fq_class_name . ' has wrong casing', $code_location, $fq_class_name), $suppressed_issues);
            }
            $event = new After_Class_Like_Existence_Check_Event($fq_class_name, $code_location, $statements_source, $codebase, []);
            $codebase->config->event_dispatcher->dispatch_after_class_like_existence_check($event);
            $file_manipulations = $event->get_file_replacements();
            if ($file_manipulations) {
                File_Manipulation_Buffer::add($code_location->file_path, $file_manipulations);
            }
        }
        return true;
    }
    /**
     * Gets the fully-qualified class name from a Name object
     */
    public static function get_fqcln_from_name_object(Php_Parser\Node\Name $class_name, Aliases $aliases): string
    {
        /** @var string|null */
        $resolved_name = $class_name->get_attribute('resolvedName');
        if ($resolved_name) {
            return $resolved_name;
        }
        if ($class_name instanceof Php_Parser\Node\Name\Fully_Qualified) {
            return $class_name->to_string();
        }
        if (in_array($class_name->get_first(), ['self', 'static', 'parent'], true)) {
            return $class_name->get_first();
        }
        return Type::get_fqcln_from_string($class_name->to_string(), $aliases);
    }
    /**
     * @psalm-mutation-free
     * @return array<lowercase-string, string>
     */
    #[Override]
    public function get_aliased_classes_flipped(): array
    {
        if ($this->source instanceof Namespace_Analyzer || $this->source instanceof File_Analyzer) {
            return $this->source->get_aliased_classes_flipped();
        }
        return [];
    }
    /**
     * @psalm-mutation-free
     * @return array<string, string>
     */
    #[Override]
    public function get_aliased_classes_flipped_replaceable(): array
    {
        if ($this->source instanceof Namespace_Analyzer || $this->source instanceof File_Analyzer) {
            return $this->source->get_aliased_classes_flipped_replaceable();
        }
        return [];
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_fqcln(): string
    {
        return $this->fq_class_name;
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_class_name(): ?string
    {
        return $this->class->name->name ?? null;
    }
    /**
     * @psalm-mutation-free
     * @return array<string, array<string, Union>>|null
     */
    #[Override]
    public function get_template_type_map(): ?array
    {
        return $this->storage->template_types;
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_parent_fqcln(): ?string
    {
        return $this->parent_fq_class_name;
    }
    /** @psalm-mutation-free */
    #[Override]
    public function is_static(): bool
    {
        return false;
    }
    /**
     * Gets the Psalm type from a particular value
     */
    public static function get_type_from_value(mixed $value): Union
    {
        switch (gettype($value)) {
            case 'boolean':
                if ($value) {
                    return Type::get_true();
                }
                return Type::get_false();
            case 'integer':
                return Type::get_int(false, $value);
            case 'double':
                return Type::get_float($value);
            case 'string':
                return Type::get_string($value);
            case 'array':
                return Type::get_array();
            case 'NULL':
                return Type::get_null();
            default:
                return Type::get_mixed();
        }
    }
    /**
     * @param  string[]         $suppressed_issues
     */
    public static function check_property_visibility(string $property_id, Context $context, Source_Analyzer $source, Code_Location $code_location, array $suppressed_issues, bool $emit_issues = true): ?bool
    {
        [$fq_class_name, $property_name] = explode('::$', $property_id);
        $codebase = $source->get_codebase();
        if ($codebase->properties->property_visibility_provider->has($fq_class_name)) {
            $property_visible = $codebase->properties->property_visibility_provider->is_property_visible($source, $fq_class_name, $property_name, true, $context, $code_location);
            if ($property_visible !== null) {
                return $property_visible;
            }
        }
        $declaring_property_class = $codebase->properties->get_declaring_class_for_property($property_id, true);
        $appearing_property_class = $codebase->properties->get_appearing_class_for_property($property_id, true);
        if (!$declaring_property_class || !$appearing_property_class) {
            throw new UnexpectedValueException('Appearing/Declaring classes are not defined for ' . $property_id);
        }
        // if the calling class is the same, we know the property exists, so it must be visible
        if ($appearing_property_class === $context->self) {
            return $emit_issues ? null : true;
        }
        if ($source->get_source() instanceof Trait_Analyzer && strtolower($declaring_property_class) === strtolower((string) $source->get_fqcln())) {
            return $emit_issues ? null : true;
        }
        $class_storage = $codebase->classlike_storage_provider->get($declaring_property_class);
        if (!isset($class_storage->properties[$property_name])) {
            throw new UnexpectedValueException('$storage should not be null for ' . $property_id);
        }
        $storage = $class_storage->properties[$property_name];
        switch ($storage->visibility) {
            case self::VISIBILITY_PUBLIC:
                return $emit_issues ? null : true;
            case self::VISIBILITY_PRIVATE:
                if ($emit_issues) {
                    Issue_Buffer::maybe_add(new Inaccessible_Property('Cannot access private property ' . $property_id . ' from context ' . $context->self, $code_location), $suppressed_issues);
                }
                return null;
            case self::VISIBILITY_PROTECTED:
                if (!$context->self) {
                    if ($emit_issues) {
                        Issue_Buffer::maybe_add(new Inaccessible_Property('Cannot access protected property ' . $property_id, $code_location), $suppressed_issues);
                    }
                    return null;
                }
                if ($codebase->class_extends($appearing_property_class, $context->self)) {
                    return $emit_issues ? null : true;
                }
                if (!$codebase->class_extends($context->self, $appearing_property_class)) {
                    if ($emit_issues) {
                        Issue_Buffer::maybe_add(new Inaccessible_Property('Cannot access protected property ' . $property_id . ' from context ' . $context->self, $code_location), $suppressed_issues);
                    }
                    return null;
                }
        }
        return $emit_issues ? null : true;
    }
    protected function check_template_params(Codebase $codebase, Class_Like_Storage $storage, Class_Like_Storage $parent_storage, Code_Location $code_location, int $given_param_count): void
    {
        $expected_param_count = $parent_storage->template_types === null ? 0 : count($parent_storage->template_types);
        if ($expected_param_count > $given_param_count) {
            Issue_Buffer::maybe_add(new Missing_Template_Param($storage->name . ' has missing template params when extending ' . $parent_storage->name . ', expecting ' . $expected_param_count, $code_location), $storage->suppressed_issues + $this->get_suppressed_issues());
        } elseif ($expected_param_count < $given_param_count) {
            Issue_Buffer::maybe_add(new Too_Many_Template_Params($storage->name . ' has too many template params when extending ' . $parent_storage->name . ', expecting ' . $expected_param_count, $code_location), $storage->suppressed_issues + $this->get_suppressed_issues());
        }
        $storage_param_count = $storage->template_types ? count($storage->template_types) : 0;
        if ($parent_storage->enforce_template_inheritance && $expected_param_count !== $storage_param_count) {
            if ($expected_param_count > $storage_param_count) {
                Issue_Buffer::maybe_add(new Missing_Template_Param($storage->name . ' requires the same number of template params as ' . $parent_storage->name . ' but saw ' . $storage_param_count, $code_location), $storage->suppressed_issues + $this->get_suppressed_issues());
            } else {
                Issue_Buffer::maybe_add(new Too_Many_Template_Params($storage->name . ' requires the same number of template params as ' . $parent_storage->name . ' but saw ' . $storage_param_count, $code_location), $storage->suppressed_issues + $this->get_suppressed_issues());
            }
        }
        if ($parent_storage->template_types && $storage->template_extended_params) {
            $i = 0;
            $previous_extended = [];
            foreach ($parent_storage->template_types as $template_name => $type_map) {
                if (isset($storage->template_extended_params[$parent_storage->name][$template_name])) {
                    $extended_type = $storage->template_extended_params[$parent_storage->name][$template_name];
                    if (isset($parent_storage->template_covariants[$i]) && !$parent_storage->template_covariants[$i]) {
                        foreach ($extended_type->get_atomic_types() as $t) {
                            if ($t instanceof T_Template_Param && $storage->template_types && $storage->template_covariants && ($local_offset = array_search($t->param_name, array_keys($storage->template_types), true)) !== false && !empty($storage->template_covariants[$local_offset])) {
                                Issue_Buffer::maybe_add(new Invalid_Template_Param('Cannot extend an invariant template param ' . $template_name . ' into a covariant context', $code_location), $storage->suppressed_issues + $this->get_suppressed_issues());
                            }
                        }
                    }
                    if ($parent_storage->enforce_template_inheritance) {
                        foreach ($extended_type->get_atomic_types() as $t) {
                            if (!$t instanceof T_Template_Param || !isset($storage->template_types[$t->param_name])) {
                                Issue_Buffer::maybe_add(new Invalid_Template_Param('Cannot extend a strictly-enforced parent template param ' . $template_name . ' with a non-template type', $code_location), $storage->suppressed_issues + $this->get_suppressed_issues());
                            } elseif ($storage->template_types[$t->param_name][$storage->name]->get_id() !== $template_type->get_id()) {
                                Issue_Buffer::maybe_add(new Invalid_Template_Param('Cannot extend a strictly-enforced parent template param ' . $template_name . ' with constraint ' . $template_type->get_id() . ' with a child template param ' . $t->param_name . ' with different constraint ' . $storage->template_types[$t->param_name][$storage->name]->get_id(), $code_location), $storage->suppressed_issues + $this->get_suppressed_issues());
                            }
                        }
                    }
                    if (!$template_type->is_mixed()) {
                        $template_result = new Template_Result($previous_extended ?: [], []);
                        $template_type_copy = Template_Standin_Type_Replacer::replace($template_type, $template_result, $codebase, null, $extended_type);
                        if (!Union_Type_Comparator::is_contained_by($codebase, $extended_type, $template_type_copy)) {
                            Issue_Buffer::maybe_add(new Invalid_Template_Param('Extended template param ' . $template_name . ' expects type ' . $template_type_copy->get_id() . ', type ' . $extended_type->get_id() . ' given', $code_location), $storage->suppressed_issues + $this->get_suppressed_issues());
                        } else {
                            $previous_extended[$template_name] = [$declaring_class => $extended_type];
                        }
                    } else {
                        $previous_extended[$template_name] = [$declaring_class => $extended_type];
                    }
                }
                $i++;
            }
        }
    }
    /**
     * @return  array<string, string>
     */
    public static function get_classes_for_file(Codebase $codebase, string $file_path): array
    {
        try {
            return $codebase->file_storage_provider->get($file_path)->classlikes_in_file;
        } catch (InvalidArgumentException) {
            return [];
        }
    }
    #[Override]
    public function get_file_analyzer(): File_Analyzer
    {
        return $this->file_analyzer;
    }
}