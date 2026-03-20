<?php

declare (strict_types=1);
namespace Psalm\Internal\Stubs\Generator;

use Php_Parser;
use Psalm\Codebase;
use Psalm\Internal\Codebase\Constant_Type_Resolver;
use Psalm\Node\Name\Virtual_Fully_Qualified;
use Psalm\Node\Stmt\Virtual_Class;
use Psalm\Node\Stmt\Virtual_Class_Const;
use Psalm\Node\Stmt\Virtual_Class_Method;
use Psalm\Node\Stmt\Virtual_Interface;
use Psalm\Node\Stmt\Virtual_Property;
use Psalm\Node\Stmt\Virtual_Trait;
use Psalm\Node\Virtual_Const;
use Psalm\Node\Virtual_Property_Item;
use Psalm\Storage\Class_Like_Storage;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Scanner\Parsed_Docblock;
use Psalm\Type;
use Psalm\Type\Union;
use ReflectionProperty;
use UnexpectedValueException;
use function array_slice;
use function rtrim;
/**
 * @internal
 */
final class Class_Like_Stub_Generator
{
    /**
     * @return PhpParser\Node\Stmt\Class_|PhpParser\Node\Stmt\Interface_|PhpParser\Node\Stmt\Trait_
     */
    public static function get_class_like_node(Codebase $codebase, Class_Like_Storage $storage, string $classlike_name): Php_Parser\Node\Stmt\Class_Like
    {
        $subnodes = ['stmts' => [...self::get_constant_nodes($codebase, $storage), ...self::get_property_nodes($storage), ...self::get_method_nodes($storage)]];
        $docblock = new Parsed_Docblock('', []);
        $template_offset = 0;
        foreach ($storage->template_types ?: [] as $template_name => $map) {
            $type = array_values($map)[0];
            $key = isset($storage->template_covariants[$template_offset]) ? 'template-covariant' : 'template';
            $docblock->tags[$key][] = $template_name . ' as ' . $type->to_namespaced_string(null, [], null, false);
            $template_offset++;
        }
        $attrs = ['comments' => $docblock->tags ? [new Php_Parser\Comment\Doc(rtrim($docblock->render('        ')))] : []];
        if ($storage->is_interface) {
            if ($storage->direct_interface_parents) {
                $subnodes['extends'] = [];
                foreach ($storage->direct_interface_parents as $direct_interface_parent) {
                    $subnodes['extends'][] = new Virtual_Fully_Qualified($direct_interface_parent);
                }
            }
            return new Virtual_Interface($classlike_name, $subnodes, $attrs);
        }
        if ($storage->is_trait) {
            return new Virtual_Trait($classlike_name, $subnodes, $attrs);
        }
        if ($storage->parent_class) {
            $subnodes['extends'] = new Virtual_Fully_Qualified($storage->parent_class);
        } else if ($storage->direct_class_interfaces) {
            $subnodes['implements'] = [];
            foreach ($storage->direct_class_interfaces as $direct_class_interface) {
                $subnodes['implements'][] = new Virtual_Fully_Qualified($direct_class_interface);
            }
        }
        return new Virtual_Class($classlike_name, $subnodes, $attrs);
    }
    /**
     * @return list<PhpParser\Node\Stmt\ClassConst>
     */
    private static function get_constant_nodes(Codebase $codebase, Class_Like_Storage $storage): array
    {
        $constant_nodes = [];
        foreach ($storage->constants as $constant_name => $constant_storage) {
            if ($constant_storage->unresolved_node) {
                $type = new Union([Constant_Type_Resolver::resolve($codebase->classlikes, $constant_storage->unresolved_node)]);
            } elseif ($constant_storage->type) {
                $type = $constant_storage->type;
            } else {
                throw new UnexpectedValueException('bad');
            }
            $constant_nodes[] = new Virtual_Class_Const([new Virtual_Const($constant_name, Stubs_Generator::get_expression_from_type($type))], $constant_storage->visibility === Class_Like_Analyzer::VISIBILITY_PUBLIC ? Php_Parser\Modifiers::PUBLIC : ($constant_storage->visibility === Class_Like_Analyzer::VISIBILITY_PROTECTED ? Php_Parser\Modifiers::PROTECTED : Php_Parser\Modifiers::PRIVATE));
        }
        return $constant_nodes;
    }
    /**
     * @return list<PhpParser\Node\Stmt\Property>
     */
    private static function get_property_nodes(Class_Like_Storage $storage): array
    {
        $namespace_name = implode('\\', array_slice(explode('\\', $storage->name), 0, -1));
        $property_nodes = [];
        foreach ($storage->properties as $property_name => $property_storage) {
            $flag = match ($property_storage->visibility) {
                Class_Like_Analyzer::VISIBILITY_PRIVATE => Php_Parser\Modifiers::PRIVATE,
                Class_Like_Analyzer::VISIBILITY_PROTECTED => Php_Parser\Modifiers::PROTECTED,
                default => Php_Parser\Modifiers::PUBLIC,
            };
            $docblock = new Parsed_Docblock('', []);
            if ($property_storage->type && $property_storage->signature_type !== $property_storage->type) {
                $docblock->tags['var'][] = $property_storage->type->to_namespaced_string($namespace_name, [], null, false);
            }
            $property_nodes[] = new Virtual_Property($flag | ($property_storage->is_static ? Php_Parser\Modifiers::STATIC : 0), [new Virtual_Property_Item($property_name, $property_storage->suggested_type ? Stubs_Generator::get_expression_from_type($property_storage->suggested_type) : null)], ['comments' => $docblock->tags ? [new Php_Parser\Comment\Doc(rtrim($docblock->render('        ')))] : []], $property_storage->signature_type ? Stubs_Generator::get_parser_type_from_psalm_type($property_storage->signature_type) : null);
        }
        return $property_nodes;
    }
    /**
     * @return list<PhpParser\Node\Stmt\ClassMethod>
     */
    private static function get_method_nodes(Class_Like_Storage $storage): array
    {
        $namespace_name = implode('\\', array_slice(explode('\\', $storage->name), 0, -1));
        $method_nodes = [];
        foreach ($storage->methods as $method_storage) {
            if (!$method_storage->cased_name) {
                throw new UnexpectedValueException('very bad');
            }
            $flag = match ($method_storage->visibility) {
                ReflectionProperty::IS_PRIVATE => Php_Parser\Modifiers::PRIVATE,
                ReflectionProperty::IS_PROTECTED => Php_Parser\Modifiers::PROTECTED,
                default => Php_Parser\Modifiers::PUBLIC,
            };
            $docblock = new Parsed_Docblock('', []);
            foreach ($method_storage->template_types ?: [] as $template_name => $map) {
                $type = array_values($map)[0];
                $docblock->tags['template'][] = $template_name . ' as ' . $type->to_namespaced_string($namespace_name, [], null, false);
            }
            foreach ($method_storage->params as $param) {
                if ($param->type && $param->type !== $param->signature_type) {
                    $docblock->tags['param'][] = $param->type->to_namespaced_string($namespace_name, [], null, false) . ' $' . $param->name;
                }
            }
            if ($method_storage->return_type && $method_storage->signature_return_type !== $method_storage->return_type) {
                $docblock->tags['return'][] = $method_storage->return_type->to_namespaced_string($namespace_name, [], null, false);
            }
            foreach ($method_storage->throws ?: [] as $exception_name => $_) {
                $docblock->tags['throws'][] = Type::get_string_from_fqcln($exception_name, $namespace_name, [], null, false);
            }
            $method_nodes[] = new Virtual_Class_Method($method_storage->cased_name, ['flags' => $flag | ($method_storage->is_static ? Php_Parser\Modifiers::STATIC : 0) | ($method_storage->abstract ? Php_Parser\Modifiers::ABSTRACT : 0), 'params' => Stubs_Generator::get_function_param_nodes($method_storage), 'returnType' => $method_storage->signature_return_type ? Stubs_Generator::get_parser_type_from_psalm_type($method_storage->signature_return_type) : null, 'stmts' => $storage->is_interface || $method_storage->abstract ? null : []], ['comments' => $docblock->tags ? [new Php_Parser\Comment\Doc(rtrim($docblock->render('        ')))] : []]);
        }
        return $method_nodes;
    }
}