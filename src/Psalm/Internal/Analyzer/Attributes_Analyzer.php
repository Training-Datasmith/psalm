<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer;

use Attribute as GlobalAttribute;
use Generator;
use Php_Parser\Node\Arg;
use Php_Parser\Node\Attribute;
use Php_Parser\Node\Attribute_Group;
use Php_Parser\Node\Expr\New_;
use Php_Parser\Node\Name\Fully_Qualified;
use Php_Parser\Node\Stmt\Expression;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Codebase\Constant_Type_Resolver;
use Psalm\Internal\Provider\Node_Data_Provider;
use Psalm\Internal\Scanner\Unresolved_Constant_Component;
use Psalm\Issue\Invalid_Attribute;
use Psalm\Issue\Undefined_Class;
use Psalm\Issue_Buffer;
use Psalm\Storage\Class_Like_Storage;
use Psalm\Storage\Has_Attributes_Interface;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Union;
use function array_key_first;
use function array_shift;
use function array_values;
use function assert;
use function count;
use function strtolower;
/**
 * @internal
 */
final class Attributes_Analyzer
{
    private const TARGET_DESCRIPTIONS = [1 => 'class', 2 => 'function', 4 => 'method', 8 => 'property', 16 => 'class constant', 32 => 'function/method parameter', 40 => 'promoted property'];
    /**
     * @param array<array-key, AttributeGroup> $attribute_groups
     * @param key-of<self::TARGET_DESCRIPTIONS> $target
     * @param array<array-key, string> $suppressed_issues
     */
    public static function analyze(Source_Analyzer $source, Context $context, Has_Attributes_Interface $storage, array $attribute_groups, int $target, array $suppressed_issues): void
    {
        $codebase = $source->get_codebase();
        $appearing_non_repeatable_attributes = [];
        foreach (self::iterate_attribute_nodes($attribute_groups) as $attribute) {
            if ($attribute->name instanceof Fully_Qualified) {
                $fq_attribute_name = (string) $attribute->name;
            } else {
                $fq_attribute_name = Class_Like_Analyzer::get_fqcln_from_name_object($attribute->name, $source->get_aliases());
            }
            $attribute_name = (string) $attribute->name;
            $attribute_name_location = new Code_Location($source, $attribute->name);
            $attribute_class_storage = $codebase->classlikes->class_exists($fq_attribute_name) ? $codebase->classlike_storage_provider->get($fq_attribute_name) : null;
            $attribute_class_flags = self::get_attribute_class_flags($source, $attribute_name, $fq_attribute_name, $attribute_name_location, $attribute_class_storage, $suppressed_issues);
            self::analyze_attribute_construction($source, $context, $fq_attribute_name, $attribute, $suppressed_issues, $storage instanceof Class_Like_Storage ? $storage : null);
            if (($attribute_class_flags & Global_Attribute::IS_REPEATABLE) === 0) {
                // Not IS_REPEATABLE
                if (isset($appearing_non_repeatable_attributes[$fq_attribute_name])) {
                    Issue_Buffer::maybe_add(new Invalid_Attribute("Attribute {$attribute_name} is not repeatable", $attribute_name_location), $suppressed_issues);
                }
                $appearing_non_repeatable_attributes[$fq_attribute_name] = true;
            }
            if (($attribute_class_flags & $target) === 0) {
                Issue_Buffer::maybe_add(new Invalid_Attribute("Attribute {$attribute_name} cannot be used on a " . self::TARGET_DESCRIPTIONS[$target], $attribute_name_location), $suppressed_issues);
            }
        }
    }
    /**
     * @param array<array-key, string> $suppressed_issues
     */
    private static function analyze_attribute_construction(Source_Analyzer $source, Context $context, string $fq_attribute_name, Attribute $attribute, array $suppressed_issues, ?Class_Like_Storage $classlike_storage = null): void
    {
        $attribute_name_location = new Code_Location($source, $attribute->name);
        if (Class_Like_Analyzer::check_fully_qualified_class_like_name($source, $fq_attribute_name, $attribute_name_location, null, null, $suppressed_issues, new Class_Like_Name_Options(false, false, false, false, false, true)) === false) {
            return;
        }
        if (strtolower($fq_attribute_name) === 'attribute' && $classlike_storage) {
            if ($classlike_storage->is_trait) {
                Issue_Buffer::maybe_add(new Invalid_Attribute('Traits cannot act as attribute classes', $attribute_name_location), $suppressed_issues);
            } elseif ($classlike_storage->is_interface) {
                Issue_Buffer::maybe_add(new Invalid_Attribute('Interfaces cannot act as attribute classes', $attribute_name_location), $suppressed_issues);
            } elseif ($classlike_storage->abstract) {
                Issue_Buffer::maybe_add(new Invalid_Attribute('Abstract classes cannot act as attribute classes', $attribute_name_location), $suppressed_issues);
            } elseif (isset($classlike_storage->methods['__construct']) && $classlike_storage->methods['__construct']->visibility !== Class_Like_Analyzer::VISIBILITY_PUBLIC) {
                Issue_Buffer::maybe_add(new Invalid_Attribute('Classes with protected/private constructors cannot act as attribute classes', $attribute_name_location), $suppressed_issues);
            } elseif ($classlike_storage->is_enum) {
                Issue_Buffer::maybe_add(new Invalid_Attribute('Enums cannot act as attribute classes', $attribute_name_location), $suppressed_issues);
            }
        }
        $statements_analyzer = new Statements_Analyzer($source, new Node_Data_Provider());
        $statements_analyzer->add_suppressed_issues(array_values($suppressed_issues));
        $had_returned = $context->has_returned;
        $context->has_returned = false;
        Issue_Buffer::start_recording();
        $statements_analyzer->analyze(
            [new Expression(new New_($attribute->name, $attribute->args, $attribute->get_attributes()))],
            // Use a new Context for the Attribute attribute so that it can't access `self`
            strtolower($fq_attribute_name) === "attribute" ? new Context() : $context
        );
        $context->has_returned = $had_returned;
        $issues = Issue_Buffer::clear_recording_level();
        Issue_Buffer::stop_recording();
        foreach ($issues as $issue) {
            if ($issue instanceof Undefined_Class && $issue->fq_classlike_name === $fq_attribute_name) {
                // Remove UndefinedClass for the attribute, since we already added UndefinedAttribute
                continue;
            }
            Issue_Buffer::bubble_up($issue);
        }
    }
    /**
     * @param array<array-key, string> $suppressed_issues
     */
    private static function get_attribute_class_flags(Source_Analyzer $source, string $attribute_name, string $fq_attribute_name, Code_Location $attribute_name_location, ?Class_Like_Storage $attribute_class_storage, array $suppressed_issues): int
    {
        if (strtolower($fq_attribute_name) === "attribute") {
            // We override this here because we still want to analyze attributes
            // for PHP 7.4 when the Attribute class doesn't yet exist.
            return Global_Attribute::TARGET_CLASS;
        }
        if ($attribute_class_storage === null) {
            return Global_Attribute::TARGET_ALL;
            // Defaults to TARGET_ALL
        }
        foreach ($attribute_class_storage->attributes as $attribute_attribute) {
            if ($attribute_attribute->fq_class_name === 'Attribute') {
                if (!$attribute_attribute->args) {
                    return Global_Attribute::TARGET_ALL;
                    // Defaults to TARGET_ALL
                }
                $first_arg = $attribute_attribute->args[array_key_first($attribute_attribute->args)];
                $first_arg_type = $first_arg->type;
                if ($first_arg_type instanceof Unresolved_Constant_Component) {
                    $first_arg_type = new Union([Constant_Type_Resolver::resolve($source->get_codebase()->classlikes, $first_arg_type, $source instanceof Statements_Analyzer ? $source : null)]);
                }
                if (!$first_arg_type->is_single_int_literal()) {
                    return Global_Attribute::TARGET_ALL;
                    // Fall back to default if it's invalid
                }
                return $first_arg_type->get_single_int_literal()->value;
            }
        }
        Issue_Buffer::maybe_add(new Invalid_Attribute("The class {$attribute_name} doesn't have the Attribute attribute", $attribute_name_location), $suppressed_issues);
        return Global_Attribute::TARGET_ALL;
        // Fall back to default if it's invalid
    }
    /**
     * @param iterable<AttributeGroup> $attribute_groups
     * @return Generator<int, Attribute>
     */
    private static function iterate_attribute_nodes(iterable $attribute_groups): Generator
    {
        foreach ($attribute_groups as $attribute_group) {
            foreach ($attribute_group->attrs as $attribute) {
                yield $attribute;
            }
        }
    }
    /**
     * Analyze Reflection getAttributes method calls.
     * @param list<Arg> $args
     */
    public static function analyze_get_attributes(Statements_Analyzer $statements_analyzer, string $method_id, array $args): void
    {
        if (count($args) !== 1) {
            // We skip this analysis if $flags is specified on getAttributes, since the only option
            // is ReflectionAttribute::IS_INSTANCEOF, which causes getAttributes to return children.
            // When returning children we don't want to limit this since a child could add a target.
            return;
        }
        switch ($method_id) {
            case "ReflectionClass::getattributes":
                $target = Global_Attribute::TARGET_CLASS;
                break;
            case "ReflectionFunction::getattributes":
                $target = Global_Attribute::TARGET_FUNCTION;
                break;
            case "ReflectionMethod::getattributes":
                $target = Global_Attribute::TARGET_METHOD;
                break;
            case "ReflectionProperty::getattributes":
                $target = Global_Attribute::TARGET_PROPERTY;
                break;
            case "ReflectionClassConstant::getattributes":
                $target = Global_Attribute::TARGET_CLASS_CONSTANT;
                break;
            case "ReflectionParameter::getattributes":
                $target = Global_Attribute::TARGET_PARAMETER;
                break;
            default:
                return;
        }
        $arg = $args[0];
        if ($arg->name !== null) {
            for (; !empty($args) && ($arg->name->name ?? null) !== "name"; $arg = array_shift($args)) {
            }
            if ($arg->name->name ?? null !== "name") {
                // No named argument for "name" parameter
                return;
            }
        }
        $arg_type = $statements_analyzer->get_node_type_provider()->get_type($arg->value);
        if ($arg_type === null || !$arg_type->is_single() || !$arg_type->has_literal_string()) {
            return;
        }
        $class_string = $arg_type->get_single_atomic();
        assert($class_string instanceof T_Literal_String);
        $codebase = $statements_analyzer->get_codebase();
        if (!$codebase->class_exists($class_string->value)) {
            return;
        }
        $class_storage = $codebase->classlike_storage_provider->get($class_string->value);
        $arg_location = new Code_Location($statements_analyzer, $arg);
        $class_attribute_target = self::get_attribute_class_flags($statements_analyzer, $class_string->value, $class_string->value, $arg_location, $class_storage, $statements_analyzer->get_suppressed_issues());
        if (($class_attribute_target & $target) === 0) {
            Issue_Buffer::maybe_add(new Invalid_Attribute("Attribute {$class_string->value} cannot be used on a " . self::TARGET_DESCRIPTIONS[$target], $arg_location), $statements_analyzer->get_suppressed_issues());
        }
    }
}