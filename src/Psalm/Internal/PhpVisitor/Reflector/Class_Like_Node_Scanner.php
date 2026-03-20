<?php

declare (strict_types=1);
namespace Psalm\Internal\Php_Visitor\Reflector;

use Exception;
use InvalidArgumentException;
use LogicException;
use Php_Parser;
use Php_Parser\Node\Expr\Binary_Op\Concat;
use Php_Parser\Node\Identifier;
use Php_Parser\Node\Intersection_Type;
use Php_Parser\Node\Name;
use Php_Parser\Node\Nullable_Type;
use Php_Parser\Node\Union_Type;
use Psalm\Aliases;
use Psalm\Code_Location;
use Psalm\Code_Location\Docblock_Type_Location;
use Psalm\Codebase;
use Psalm\Config;
use Psalm\Doc_Comment;
use Psalm\Exception\Docblock_Parse_Exception;
use Psalm\Exception\Incorrect_Docblock_Exception;
use Psalm\Exception\Invalid_Classlike_Override_Exception;
use Psalm\Exception\Type_Parse_Tree_Exception;
use Psalm\Internal\Analyzer\Class_Analyzer;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Comment_Analyzer;
use Psalm\Internal\Analyzer\Namespace_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Simple_Type_Inferer;
use Psalm\Internal\Codebase\Property_Map;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Provider\Node_Data_Provider;
use Psalm\Internal\Scanner\Class_Like_Docblock_Comment;
use Psalm\Internal\Scanner\File_Scanner;
use Psalm\Internal\Scanner\Unresolved_Constant_Component;
use Psalm\Internal\Type\Type_Alias;
use Psalm\Internal\Type\Type_Alias\Class_Type_Alias;
use Psalm\Internal\Type\Type_Alias\Inline_Type_Alias;
use Psalm\Internal\Type\Type_Alias\Linkable_Type_Alias;
use Psalm\Internal\Type\Type_Parser;
use Psalm\Internal\Type\Type_Tokenizer;
use Psalm\Issue\Constant_Declaration_In_Trait;
use Psalm\Issue\Duplicate_Class;
use Psalm\Issue\Duplicate_Constant;
use Psalm\Issue\Duplicate_Enum_Case;
use Psalm\Issue\Duplicate_Property;
use Psalm\Issue\Invalid_Attribute;
use Psalm\Issue\Invalid_Docblock;
use Psalm\Issue\Invalid_Enum_Backing_Type;
use Psalm\Issue\Invalid_Enum_Case_Value;
use Psalm\Issue\Invalid_Type_Import;
use Psalm\Issue\Missing_Class_Const_Type;
use Psalm\Issue\Missing_Docblock_Type;
use Psalm\Issue\Missing_Property_Type;
use Psalm\Issue\ParseError;
use Psalm\Issue_Buffer;
use Psalm\Storage\Attribute_Storage;
use Psalm\Storage\Class_Constant_Storage;
use Psalm\Storage\Class_Like_Storage;
use Psalm\Storage\Enum_Case_Storage;
use Psalm\Storage\File_Storage;
use Psalm\Storage\Method_Storage;
use Psalm\Storage\Property_Hook_Storage;
use Psalm\Storage\Property_Storage;
use Psalm\Type;
use Psalm\Type\Atomic\T_Generic_Object;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_merge;
use function array_pop;
use function array_shift;
use function array_values;
use function assert;
use function count;
use function implode;
use function ltrim;
use function preg_match;
use function preg_split;
use function sprintf;
use function strtolower;
use function trim;
use function usort;
use const PREG_SPLIT_DELIM_CAPTURE;
use const PREG_SPLIT_NO_EMPTY;
/**
 * @internal
 */
final class Class_Like_Node_Scanner
{
    private readonly string $file_path;
    private readonly Config $config;
    /**
     * @var array<string, InlineTypeAlias>
     */
    private array $classlike_type_aliases = [];
    /**
     * @var array<string, array<string, Union>>
     */
    public array $class_template_types = [];
    public ?Class_Like_Storage $storage = null;
    /**
     * @var array<string, TypeAlias>
     */
    public array $type_aliases = [];
    public function __construct(private readonly Codebase $codebase, private readonly File_Storage $file_storage, private readonly File_Scanner $file_scanner, private readonly Aliases $aliases, private readonly ?Name $namespace_name)
    {
        $this->file_path = $file_storage->file_path;
        $this->config = Config::get_instance();
    }
    /**
     * @return false|null
     * @psalm-suppress ComplexMethod
     */
    public function start(Php_Parser\Node\Stmt\Class_Like $node): ?bool
    {
        $class_location = new Code_Location($this->file_scanner, $node);
        $name_location = null;
        $storage = null;
        $class_name = null;
        $is_classlike_overridden = false;
        if ($node->name === null) {
            if (!$node instanceof Php_Parser\Node\Stmt\Class_) {
                throw new LogicException('Anonymous classes are always classes');
            }
            $fq_classlike_name = Class_Analyzer::get_anonymous_class_name($node, $this->aliases, $this->file_path);
        } else {
            $name_location = new Code_Location($this->file_scanner, $node->name);
            $fq_classlike_name = ($this->aliases->namespace ? $this->aliases->namespace . '\\' : '') . $node->name->name;
            assert($fq_classlike_name !== "");
            $fq_classlike_name_lc = strtolower($fq_classlike_name);
            $class_name = $node->name->name;
            if ($this->codebase->classlike_storage_provider->has($fq_classlike_name_lc)) {
                $duplicate_storage = $this->codebase->classlike_storage_provider->get($fq_classlike_name_lc);
                // don't override data from files that are getting analyzed with data from stubs
                // if the stubs contain the same class
                if (!$duplicate_storage->stubbed && $this->codebase->register_stub_files && $duplicate_storage->stmt_location && $this->config->is_in_project_dirs($duplicate_storage->stmt_location->file_path)) {
                    return false;
                }
                if (!$this->codebase->register_stub_files) {
                    if (!$duplicate_storage->stmt_location || $duplicate_storage->stmt_location->file_path !== $this->file_path || $class_location->get_hash() !== $duplicate_storage->stmt_location->get_hash()) {
                        Issue_Buffer::maybe_add(new Duplicate_Class('Class ' . $fq_classlike_name . ' has already been defined' . ($duplicate_storage->location ? ' in ' . $duplicate_storage->location->file_path : ''), $name_location));
                        $this->file_storage->has_visitor_issues = true;
                        $duplicate_storage->has_visitor_issues = true;
                        return false;
                    }
                } elseif (!$duplicate_storage->location || $duplicate_storage->location->file_path !== $this->file_path || $class_location->get_hash() !== $duplicate_storage->location->get_hash()) {
                    $is_classlike_overridden = true;
                    // we're overwriting some methods
                    $storage = $this->storage = $duplicate_storage;
                    $this->codebase->classlike_storage_provider->make_new(strtolower($fq_classlike_name));
                    $storage->populated = false;
                    $storage->class_implements = [];
                    // we do this because reflection reports
                    $storage->parent_interfaces = [];
                    $storage->stubbed = true;
                    $storage->aliases = $this->aliases;
                    foreach ($storage->dependent_classlikes as $dependent_name_lc => $_) {
                        try {
                            $dependent_storage = $this->codebase->classlike_storage_provider->get($dependent_name_lc);
                        } catch (InvalidArgumentException) {
                            continue;
                        }
                        $dependent_storage->populated = false;
                        $this->codebase->classlike_storage_provider->make_new($dependent_name_lc);
                    }
                }
            }
        }
        $fq_classlike_name_lc = strtolower($fq_classlike_name);
        $this->file_storage->classlikes_in_file[$fq_classlike_name_lc] = $fq_classlike_name;
        if (!$storage) {
            $this->storage = $storage = $this->codebase->classlike_storage_provider->create($fq_classlike_name);
        }
        if ($class_name && isset($this->aliases->uses[strtolower($class_name)]) && $this->aliases->uses[strtolower($class_name)] !== $fq_classlike_name) {
            Issue_Buffer::maybe_add(new ParseError('Class name ' . $class_name . ' clashes with a use statement alias', $name_location ?? $class_location));
            $storage->has_visitor_issues = true;
            $this->file_storage->has_visitor_issues = true;
        }
        $storage->stmt_location = $class_location;
        $storage->location = $name_location;
        if ($this->namespace_name) {
            $storage->namespace_name_location = new Code_Location($this->file_scanner, $this->namespace_name);
        }
        $storage->user_defined = !$this->codebase->register_stub_files;
        $storage->stubbed = $this->codebase->register_stub_files;
        $storage->aliases = $this->aliases;
        if ($node instanceof Php_Parser\Node\Stmt\Class_) {
            $storage->abstract = $node->is_abstract();
            $storage->final = $node->is_final();
            $storage->readonly = $node->is_readonly();
            $this->codebase->classlikes->add_fully_qualified_class_name($fq_classlike_name, $this->file_path);
            if ($node->extends) {
                $parent_fqcln = Class_Like_Analyzer::get_fqcln_from_name_object($node->extends, $this->aliases);
                $parent_fqcln = $this->codebase->classlikes->get_un_aliased_name($parent_fqcln);
                $this->codebase->scanner->queue_class_like_for_scanning($parent_fqcln, $this->file_scanner->will_analyze);
                $parent_fqcln_lc = strtolower($parent_fqcln);
                $storage->parent_class = $parent_fqcln;
                $storage->parent_classes[$parent_fqcln_lc] = $parent_fqcln;
                $this->file_storage->required_classes[strtolower($parent_fqcln)] = $parent_fqcln;
            }
        } elseif ($node instanceof Php_Parser\Node\Stmt\Interface_) {
            $storage->is_interface = true;
            $this->codebase->classlikes->add_fully_qualified_interface_name($fq_classlike_name, $this->file_path);
            foreach ($node->extends as $interface) {
                $interface_fqcln = Class_Like_Analyzer::get_fqcln_from_name_object($interface, $this->aliases);
                $interface_fqcln = $this->codebase->classlikes->get_un_aliased_name($interface_fqcln);
                $interface_fqcln_lc = strtolower($interface_fqcln);
                $this->codebase->scanner->queue_class_like_for_scanning($interface_fqcln);
                $storage->parent_interfaces[$interface_fqcln_lc] = $interface_fqcln;
                $storage->direct_interface_parents[$interface_fqcln_lc] = $interface_fqcln;
                $this->file_storage->required_interfaces[$interface_fqcln_lc] = $interface_fqcln;
            }
        } elseif ($node instanceof Php_Parser\Node\Stmt\Trait_) {
            $storage->is_trait = true;
            $this->codebase->classlikes->add_fully_qualified_trait_name($fq_classlike_name, $this->file_path);
        } elseif ($node instanceof Php_Parser\Node\Stmt\Enum_) {
            $storage->is_enum = true;
            $storage->final = true;
            if ($node->scalar_type) {
                if ($node->scalar_type->name === 'string' || $node->scalar_type->name === 'int') {
                    $storage->enum_type = $node->scalar_type->name;
                    $storage->class_implements['backedenum'] = 'BackedEnum';
                    $storage->direct_class_interfaces['backedenum'] = 'BackedEnum';
                    $this->file_storage->required_interfaces['backedenum'] = 'BackedEnum';
                    $this->codebase->scanner->queue_class_like_for_scanning('BackedEnum');
                    $storage->declaring_method_ids['from'] = new Method_Identifier('BackedEnum', 'from');
                    $storage->appearing_method_ids['from'] = $storage->declaring_method_ids['from'];
                    $storage->declaring_method_ids['tryfrom'] = new Method_Identifier('BackedEnum', 'tryfrom');
                    $storage->appearing_method_ids['tryfrom'] = $storage->declaring_method_ids['tryfrom'];
                } else {
                    Issue_Buffer::maybe_add(new Invalid_Enum_Backing_Type('Enums cannot be backed by ' . $node->scalar_type->name . ', string or int expected', new Code_Location($this->file_scanner, $node->scalar_type), $fq_classlike_name));
                    $this->file_storage->has_visitor_issues = true;
                    $storage->has_visitor_issues = true;
                }
            }
            $this->codebase->scanner->queue_class_like_for_scanning('UnitEnum');
            $storage->class_implements['unitenum'] = 'UnitEnum';
            $storage->direct_class_interfaces['unitenum'] = 'UnitEnum';
            $this->file_storage->required_interfaces['unitenum'] = 'UnitEnum';
            $storage->final = true;
            $storage->declaring_method_ids['cases'] = new Method_Identifier('UnitEnum', 'cases');
            $storage->appearing_method_ids['cases'] = $storage->declaring_method_ids['cases'];
            $this->codebase->classlikes->add_fully_qualified_enum_name($fq_classlike_name, $this->file_path);
        } else {
            throw new UnexpectedValueException('Unknown classlike type');
        }
        if ($node instanceof Php_Parser\Node\Stmt\Class_ || $node instanceof Php_Parser\Node\Stmt\Enum_) {
            foreach ($node->implements as $interface) {
                $interface_fqcln = Class_Like_Analyzer::get_fqcln_from_name_object($interface, $this->aliases);
                $interface_fqcln_lc = strtolower($interface_fqcln);
                $this->codebase->scanner->queue_class_like_for_scanning($interface_fqcln);
                $storage->class_implements[$interface_fqcln_lc] = $interface_fqcln;
                $storage->direct_class_interfaces[$interface_fqcln_lc] = $interface_fqcln;
                $this->file_storage->required_interfaces[$interface_fqcln_lc] = $interface_fqcln;
            }
        }
        $docblock_info = null;
        $doc_comment = $node->get_doc_comment();
        if ($doc_comment) {
            try {
                $docblock_info = Class_Like_Docblock_Parser::parse($node, $doc_comment, $this->aliases);
                $this->type_aliases += $this->get_imported_type_aliases($docblock_info, $fq_classlike_name);
            } catch (Docblock_Parse_Exception $e) {
                $storage->docblock_issues[] = new Invalid_Docblock($e->get_message() . ' in docblock for ' . $fq_classlike_name, $name_location ?? $class_location);
            }
        }
        foreach ($node->get_comments() as $comment) {
            if (!$comment instanceof Php_Parser\Comment\Doc) {
                continue;
            }
            try {
                $type_aliases = self::get_type_aliases_from_comment($comment, $this->aliases, $this->type_aliases, $fq_classlike_name);
                foreach ($type_aliases as $type_alias) {
                    // finds issues, if there are any
                    Type_Parser::parse_tokens($type_alias->replacement_tokens);
                }
                $this->type_aliases += $type_aliases;
                if ($type_aliases) {
                    $this->classlike_type_aliases = $type_aliases;
                }
            } catch (Docblock_Parse_Exception|Type_Parse_Tree_Exception $e) {
                $storage->docblock_issues[] = new Invalid_Docblock($e->get_message(), new Code_Location($this->file_scanner, $node, null, true));
            }
        }
        if ($docblock_info) {
            if ($docblock_info->stub_override && !$is_classlike_overridden) {
                throw new Invalid_Classlike_Override_Exception('Class/interface/trait ' . $fq_classlike_name . ' is marked as stub override,' . ' but no original counterpart found');
            }
            if ($docblock_info->templates) {
                $storage->template_types = [];
                usort($docblock_info->templates, static fn(array $l, array $r): int => $l[4] > $r[4] ? 1 : -1);
                foreach ($docblock_info->templates as $i => $template_map) {
                    $template_name = $template_map[0];
                    if ($template_map[1] !== null && $template_map[2] !== null) {
                        if (trim($template_map[2])) {
                            $type_string = $template_map[2];
                            try {
                                $type_string = Comment_Analyzer::split_doc_line($type_string)[0];
                            } catch (Docblock_Parse_Exception $e) {
                                $storage->docblock_issues[] = new Invalid_Docblock($e->get_message() . ' in docblock for ' . $fq_classlike_name, $name_location ?? $class_location);
                                continue;
                            }
                            $type_string = Comment_Analyzer::sanitize_docblock_type($type_string);
                            try {
                                $template_type = Type_Parser::parse_tokens(Type_Tokenizer::get_fully_qualified_tokens($type_string, $this->aliases, $storage->template_types, $this->type_aliases), null, $storage->template_types, $this->type_aliases);
                            } catch (Type_Parse_Tree_Exception $e) {
                                $storage->docblock_issues[] = new Invalid_Docblock($e->get_message() . ' in docblock for ' . $fq_classlike_name, $name_location ?? $class_location);
                                continue;
                            }
                            $storage->template_types[$template_name] = [$fq_classlike_name => $template_type];
                        } else {
                            $storage->docblock_issues[] = new Invalid_Docblock('Template missing as type', $name_location ?? $class_location);
                        }
                    } else {
                        /** @psalm-suppress PropertyTypeCoercion due to a Psalm bug */
                        $storage->template_types[$template_name][$fq_classlike_name] = Type::get_mixed();
                    }
                    $storage->template_covariants[$i] = $template_map[3];
                }
                $this->class_template_types = $storage->template_types;
            }
            foreach ($docblock_info->template_extends as $extended_class_name) {
                $this->extend_templated_type($storage, $node, $extended_class_name);
            }
            foreach ($docblock_info->template_implements as $implemented_class_name) {
                $this->implement_templated_type($storage, $node, $implemented_class_name);
            }
            if ($docblock_info->yield) {
                try {
                    $yield_type_tokens = Type_Tokenizer::get_fully_qualified_tokens($docblock_info->yield, $this->aliases, $storage->template_types, $this->type_aliases);
                    $yield_type = Type_Parser::parse_tokens($yield_type_tokens, null, $storage->template_types ?: [], $this->type_aliases, true);
                    /** @psalm-suppress UnusedMethodCall */
                    $yield_type->queue_class_likes_for_scanning($this->codebase, $this->file_storage, $storage->template_types ?: []);
                    $storage->yield = $yield_type;
                } catch (Type_Parse_Tree_Exception) {
                    // do nothing
                }
            }
            if ($docblock_info->extension_requirement !== null) {
                $storage->extension_requirement = (string) Type_Parser::parse_tokens(Type_Tokenizer::get_fully_qualified_tokens($docblock_info->extension_requirement, $this->aliases, $this->class_template_types, $this->type_aliases), null, $this->class_template_types, $this->type_aliases);
            }
            foreach ($docblock_info->implementation_requirements as $implementation_requirement) {
                $storage->implementation_requirements[] = (string) Type_Parser::parse_tokens(Type_Tokenizer::get_fully_qualified_tokens($implementation_requirement, $this->aliases, $this->class_template_types, $this->type_aliases), null, $this->class_template_types, $this->type_aliases);
            }
            $storage->sealed_properties = $docblock_info->sealed_properties;
            $storage->sealed_methods = $docblock_info->sealed_methods;
            if ($docblock_info->inheritors) {
                try {
                    $storage->inheritors = Type_Parser::parse_tokens(Type_Tokenizer::get_fully_qualified_tokens($docblock_info->inheritors, $storage->aliases, $storage->template_types ?? [], $storage->type_aliases, $fq_classlike_name), null, $storage->template_types ?? [], $storage->type_aliases, true);
                } catch (Type_Parse_Tree_Exception $e) {
                    $storage->docblock_issues[] = new Invalid_Docblock('@psalm-inheritors contains invalid reference:' . $e->get_message(), $name_location ?? $class_location);
                }
            }
            foreach ($docblock_info->properties as $property) {
                $pseudo_property_type_tokens = Type_Tokenizer::get_fully_qualified_tokens($property['type'], $this->aliases, $this->class_template_types, $this->type_aliases);
                try {
                    $pseudo_property_type = Type_Parser::parse_tokens($pseudo_property_type_tokens, null, $this->class_template_types, $this->type_aliases, true);
                    /** @psalm-suppress UnusedMethodCall */
                    $pseudo_property_type->queue_class_likes_for_scanning($this->codebase, $this->file_storage, $storage->template_types ?: []);
                    if ($property['tag'] !== 'property-read' && $property['tag'] !== 'psalm-property-read') {
                        $storage->pseudo_property_set_types[$property['name']] = $pseudo_property_type;
                    }
                    if ($property['tag'] !== 'property-write' && $property['tag'] !== 'psalm-property-write') {
                        $storage->pseudo_property_get_types[$property['name']] = $pseudo_property_type;
                    }
                } catch (Type_Parse_Tree_Exception $e) {
                    $storage->docblock_issues[] = new Invalid_Docblock($e->get_message() . ' in docblock for ' . $fq_classlike_name, $name_location ?? $class_location);
                }
            }
            foreach ($docblock_info->methods as $method) {
                $functionlike_node_scanner = new Function_Like_Node_Scanner($this->codebase, $this->file_scanner, $this->file_storage, $this->aliases, $this->type_aliases, $this->storage, []);
                /** @var MethodStorage */
                $pseudo_method_storage = $functionlike_node_scanner->start($method, true);
                $lc_method_name = strtolower($method->name->name);
                if ($pseudo_method_storage->is_static) {
                    $storage->pseudo_static_methods[$lc_method_name] = $pseudo_method_storage;
                } else {
                    $storage->pseudo_methods[$lc_method_name] = $pseudo_method_storage;
                    $storage->declaring_pseudo_method_ids[$lc_method_name] = new Method_Identifier($fq_classlike_name, $lc_method_name);
                }
            }
            $storage->deprecated = $docblock_info->deprecated;
            if (count($docblock_info->psalm_internal) !== 0) {
                $storage->internal = $docblock_info->psalm_internal;
            } elseif ($docblock_info->internal && $this->aliases->namespace) {
                $storage->internal = [Namespace_Analyzer::get_name_space_root($this->aliases->namespace)];
            }
            if ($docblock_info->final && !$storage->final) {
                $storage->final = true;
                $storage->final_from_docblock = true;
            }
            $storage->preserve_constructor_signature = $docblock_info->consistent_constructor;
            if ($storage->preserve_constructor_signature) {
                $has_constructor = false;
                foreach ($node->stmts as $stmt) {
                    if ($stmt instanceof Php_Parser\Node\Stmt\Class_Method && $stmt->name->name === '__construct') {
                        $has_constructor = true;
                        break;
                    }
                }
                if (!$has_constructor) {
                    self::register_empty_constructor($storage);
                }
            }
            $storage->enforce_template_inheritance = $docblock_info->consistent_templates;
            foreach ($docblock_info->mixins as $key => $mixin) {
                $mixin_type = Type_Parser::parse_tokens(Type_Tokenizer::get_fully_qualified_tokens($mixin, $this->aliases, $this->class_template_types, $this->type_aliases, $fq_classlike_name), null, $this->class_template_types, $this->type_aliases, true);
                /** @psalm-suppress UnusedMethodCall */
                $mixin_type->queue_class_likes_for_scanning($this->codebase, $this->file_storage, $storage->template_types ?: []);
                if ($mixin_type->is_single()) {
                    $mixin_type = $mixin_type->get_single_atomic();
                    if ($mixin_type instanceof T_Named_Object) {
                        $storage->named_mixins[] = $mixin_type;
                    }
                    if ($mixin_type instanceof T_Template_Param) {
                        $storage->templated_mixins[] = $mixin_type;
                    }
                }
                if ($key === 0) {
                    $storage->mixin_declaring_fqcln = $storage->name;
                }
            }
            $storage->mutation_free = $docblock_info->mutation_free;
            $storage->external_mutation_free = $docblock_info->external_mutation_free;
            $storage->specialize_instance = $docblock_info->taint_specialize;
            $storage->override_property_visibility = $docblock_info->override_property_visibility;
            $storage->override_method_visibility = $docblock_info->override_method_visibility;
            $storage->suppressed_issues = $docblock_info->suppressed_issues;
            if ($docblock_info->description) {
                $storage->description = $docblock_info->description;
            }
            $storage->public_api = $docblock_info->public_api;
        }
        foreach ($node->stmts as $node_stmt) {
            if ($node_stmt instanceof Php_Parser\Node\Stmt\Class_Const) {
                $this->visit_class_const_declaration($node_stmt, $storage, $fq_classlike_name);
            } elseif ($node_stmt instanceof Php_Parser\Node\Stmt\Enum_Case && $node instanceof Php_Parser\Node\Stmt\Enum_) {
                $this->visit_enum_declaration($node_stmt, $storage, $fq_classlike_name);
            }
        }
        if ($storage->is_enum) {
            $name_types = [];
            $values_types = [];
            foreach ($storage->enum_cases as $name => $enum_case_storage) {
                $name_types[] = Type::get_atomic_string_from_literal($name);
                if ($storage->enum_type !== null && $enum_case_storage->value !== null) {
                    if ($enum_case_storage->value instanceof Unresolved_Constant_Component) {
                        // backed enum with a type yet unknown
                        $values_types[] = new Type\Atomic\T_Mixed();
                    } else {
                        $values_types[] = $enum_case_storage->value;
                    }
                }
            }
            if ($name_types !== []) {
                $storage->declaring_property_ids['name'] = $storage->name;
                $storage->appearing_property_ids['name'] = "{$storage->name}::\$name";
                $storage->properties['name'] = new Property_Storage();
                $storage->properties['name']->type = new Union($name_types);
            }
            if ($values_types !== []) {
                $storage->declaring_property_ids['value'] = $storage->name;
                $storage->appearing_property_ids['value'] = "{$storage->name}::\$value";
                $storage->properties['value'] = new Property_Storage();
                $storage->properties['value']->type = new Union($values_types);
            }
        }
        foreach ($node->stmts as $node_stmt) {
            if ($node_stmt instanceof Php_Parser\Node\Stmt\Property) {
                $this->visit_property_declaration($node_stmt, $this->config, $storage, $fq_classlike_name);
            }
        }
        foreach ($node->attr_groups as $attr_group) {
            foreach ($attr_group->attrs as $attr) {
                $attribute = Attribute_Resolver::resolve($this->codebase, $this->file_scanner, $this->file_storage, $this->aliases, $attr, $this->storage->name ?? null);
                if ($attribute->fq_class_name === 'Psalm\Deprecated' || $attribute->fq_class_name === 'JetBrains\PhpStorm\Deprecated' || $attribute->fq_class_name === 'Deprecated') {
                    $storage->deprecated = true;
                }
                if ($attribute->fq_class_name === 'Psalm\Internal' && !$storage->internal) {
                    $storage->internal = [Namespace_Analyzer::get_name_space_root($fq_classlike_name)];
                }
                if ($attribute->fq_class_name === 'Psalm\Immutable' || $attribute->fq_class_name === 'JetBrains\PhpStorm\Immutable') {
                    $storage->mutation_free = true;
                    $storage->external_mutation_free = true;
                }
                if ($attribute->fq_class_name === 'Psalm\ExternalMutationFree') {
                    $storage->external_mutation_free = true;
                }
                if ($attribute->fq_class_name === 'AllowDynamicProperties' && $storage->readonly) {
                    Issue_Buffer::maybe_add(new Invalid_Attribute('Readonly classes cannot have dynamic properties', new Code_Location($this->file_scanner, $attr, null, true)));
                    continue;
                }
                $storage->attributes[] = $attribute;
            }
        }
        return null;
    }
    public function finish(Php_Parser\Node\Stmt\Class_Like $node): Class_Like_Storage
    {
        if (!$this->storage) {
            throw new UnexpectedValueException('Storage should exist in ' . $this->file_path . ' at ' . $node->get_line());
        }
        $classlike_storage = $this->storage;
        $fq_classlike_name = $classlike_storage->name;
        if (Property_Map::in_property_map($fq_classlike_name)) {
            $mapped_properties = Property_Map::get_property_map()[strtolower($fq_classlike_name)];
            foreach ($mapped_properties as $property_name => $public_mapped_property) {
                $property_type = Type::parse_string($public_mapped_property);
                /** @psalm-suppress UnusedMethodCall */
                $property_type->queue_class_likes_for_scanning($this->codebase, $this->file_storage);
                if (!isset($classlike_storage->properties[$property_name])) {
                    $classlike_storage->properties[$property_name] = new Property_Storage();
                }
                $property_id = $fq_classlike_name . '::$' . $property_name;
                if ($property_id === 'DateInterval::$days') {
                    /** @psalm-suppress InaccessibleProperty We just parsed this type */
                    $property_type->ignore_falsable_issues = true;
                }
                $classlike_storage->properties[$property_name]->type = $property_type;
                $classlike_storage->declaring_property_ids[$property_name] = $fq_classlike_name;
                $classlike_storage->appearing_property_ids[$property_name] = $property_id;
            }
        }
        $converted_aliases = [];
        foreach ($this->classlike_type_aliases as $key => $type) {
            try {
                $union = Type_Parser::parse_tokens($type->replacement_tokens, null, [], $this->type_aliases, true);
                $converted_aliases[$key] = new Class_Type_Alias(array_values($union->get_atomic_types()));
            } catch (Type_Parse_Tree_Exception $e) {
                $classlike_storage->docblock_issues[] = new Invalid_Docblock('@psalm-type ' . $key . ' contains invalid reference: ' . $e->get_message(), new Code_Location($this->file_scanner, $node, null, true));
            } catch (Exception) {
                $classlike_storage->docblock_issues[] = new Invalid_Docblock('@psalm-type ' . $key . ' contains invalid references', new Code_Location($this->file_scanner, $node, null, true));
            }
        }
        $classlike_storage->type_aliases = $converted_aliases;
        return $classlike_storage;
    }
    public function handle_trait_use(Php_Parser\Node\Stmt\Trait_Use $node): void
    {
        $storage = $this->storage;
        if (!$storage) {
            throw new UnexpectedValueException('bad');
        }
        foreach ($node->adaptations as $adaptation) {
            if ($adaptation instanceof Php_Parser\Node\Stmt\Trait_Use_Adaptation\Alias) {
                $old_name = strtolower($adaptation->method->name);
                $new_name = $old_name;
                if ($adaptation->new_name) {
                    $new_name = strtolower($adaptation->new_name->name);
                    if ($new_name !== $old_name) {
                        $storage->trait_alias_map[$new_name] = $old_name;
                        $storage->trait_alias_map_cased[$adaptation->new_name->name] = $adaptation->method->name;
                    }
                }
                if ($adaptation->new_modifier) {
                    switch ($adaptation->new_modifier) {
                        case 1:
                            $storage->trait_visibility_map[$new_name] = Class_Like_Analyzer::VISIBILITY_PUBLIC;
                            break;
                        case 2:
                            $storage->trait_visibility_map[$new_name] = Class_Like_Analyzer::VISIBILITY_PROTECTED;
                            break;
                        case 4:
                            $storage->trait_visibility_map[$new_name] = Class_Like_Analyzer::VISIBILITY_PRIVATE;
                            break;
                        case 32:
                            $storage->trait_final_map[$new_name] = true;
                            break;
                    }
                }
            }
        }
        foreach ($node->traits as $trait) {
            $trait_fqcln = Class_Like_Analyzer::get_fqcln_from_name_object($trait, $this->aliases);
            $this->codebase->scanner->queue_class_like_for_scanning($trait_fqcln, $this->file_scanner->will_analyze);
            $storage->used_traits[strtolower($trait_fqcln)] = $trait_fqcln;
            $this->file_storage->required_classes[strtolower($trait_fqcln)] = $trait_fqcln;
        }
        if ($node_comment = $node->get_doc_comment()) {
            $comments = Doc_Comment::parse_preserving_length($node_comment);
            if (isset($comments->combined_tags['use'])) {
                foreach ($comments->combined_tags['use'] as $template_line) {
                    $this->use_templated_type($storage, $node, Comment_Analyzer::sanitize_docblock_type($template_line));
                }
            }
            if (isset($comments->tags['template-extends']) || isset($comments->tags['extends']) || isset($comments->tags['template-implements']) || isset($comments->tags['implements'])) {
                $storage->docblock_issues[] = new Invalid_Docblock('You must use @use or @template-use to parameterize traits', new Code_Location($this->file_scanner, $node, null, true));
            }
        }
    }
    private function extend_templated_type(Class_Like_Storage $storage, Php_Parser\Node\Stmt\Class_Like $node, string $extended_class_name): void
    {
        if (trim($extended_class_name) === '') {
            $storage->docblock_issues[] = new Invalid_Docblock('Extended class cannot be empty in docblock for ' . $storage->name, new Code_Location($this->file_scanner, $node, null, true));
            return;
        }
        try {
            $extended_union_type = Type_Parser::parse_tokens(Type_Tokenizer::get_fully_qualified_tokens($extended_class_name, $this->aliases, $this->class_template_types, $this->type_aliases), null, $this->class_template_types, $this->type_aliases, true);
        } catch (Type_Parse_Tree_Exception $e) {
            $storage->docblock_issues[] = new Invalid_Docblock($e->get_message() . ' in docblock for ' . $storage->name, new Code_Location($this->file_scanner, $node, null, true));
            return;
        }
        if (!$extended_union_type->is_single()) {
            $storage->docblock_issues[] = new Invalid_Docblock('@template-extends cannot be a union type', new Code_Location($this->file_scanner, $node, null, true));
        }
        /** @psalm-suppress UnusedMethodCall */
        $extended_union_type->queue_class_likes_for_scanning($this->codebase, $this->file_storage, $storage->template_types ?: []);
        foreach ($extended_union_type->get_atomic_types() as $atomic_type) {
            if (!$atomic_type instanceof T_Generic_Object) {
                $storage->docblock_issues[] = new Invalid_Docblock('@template-extends has invalid class ' . $atomic_type->get_id(), new Code_Location($this->file_scanner, $node, null, true));
                return;
            }
            $generic_class_lc = strtolower($atomic_type->value);
            if (!isset($storage->parent_classes[$generic_class_lc]) && !isset($storage->parent_interfaces[$generic_class_lc])) {
                $storage->docblock_issues[] = new Invalid_Docblock('@template-extends must include the name of an extended class,' . ' got ' . $atomic_type->get_id(), new Code_Location($this->file_scanner, $node, null, true));
            }
            $extended_type_parameters = [];
            $storage->template_type_extends_count[$atomic_type->value] = count($atomic_type->type_params);
            foreach ($atomic_type->type_params as $type_param) {
                $extended_type_parameters[] = $type_param;
            }
            $storage->template_extended_offsets[$atomic_type->value] = $extended_type_parameters;
        }
    }
    private function implement_templated_type(Class_Like_Storage $storage, Php_Parser\Node\Stmt\Class_Like $node, string $implemented_class_name): void
    {
        if (trim($implemented_class_name) === '') {
            $storage->docblock_issues[] = new Invalid_Docblock('Extended class cannot be empty in docblock for ' . $storage->name, new Code_Location($this->file_scanner, $node, null, true));
            return;
        }
        try {
            $implemented_union_type = Type_Parser::parse_tokens(Type_Tokenizer::get_fully_qualified_tokens($implemented_class_name, $this->aliases, $this->class_template_types, $this->type_aliases), null, $this->class_template_types, $this->type_aliases, true);
        } catch (Type_Parse_Tree_Exception $e) {
            $storage->docblock_issues[] = new Invalid_Docblock($e->get_message() . ' in docblock for ' . $storage->name, new Code_Location($this->file_scanner, $node, null, true));
            return;
        }
        if (!$implemented_union_type->is_single()) {
            $storage->docblock_issues[] = new Invalid_Docblock('@template-implements cannot be a union type', new Code_Location($this->file_scanner, $node, null, true));
            return;
        }
        /** @psalm-suppress UnusedMethodCall */
        $implemented_union_type->queue_class_likes_for_scanning($this->codebase, $this->file_storage, $storage->template_types ?: []);
        foreach ($implemented_union_type->get_atomic_types() as $atomic_type) {
            if (!$atomic_type instanceof T_Generic_Object) {
                $storage->docblock_issues[] = new Invalid_Docblock('@template-implements has invalid class ' . $atomic_type->get_id(), new Code_Location($this->file_scanner, $node, null, true));
                return;
            }
            $generic_class_lc = strtolower($atomic_type->value);
            if (!isset($storage->class_implements[$generic_class_lc])) {
                $storage->docblock_issues[] = new Invalid_Docblock('@template-implements must include the name of an implemented class,' . ' got ' . $atomic_type->get_id(), new Code_Location($this->file_scanner, $node, null, true));
                return;
            }
            $implemented_type_parameters = [];
            $storage->template_type_implements_count[$generic_class_lc] = count($atomic_type->type_params);
            foreach ($atomic_type->type_params as $type_param) {
                $implemented_type_parameters[] = $type_param;
            }
            $storage->template_extended_offsets[$atomic_type->value] = $implemented_type_parameters;
        }
    }
    private function use_templated_type(Class_Like_Storage $storage, Php_Parser\Node\Stmt\Trait_Use $node, string $used_class_name): void
    {
        if (trim($used_class_name) === '') {
            $storage->docblock_issues[] = new Invalid_Docblock('Extended class cannot be empty in docblock for ' . $storage->name, new Code_Location($this->file_scanner, $node, null, true));
            return;
        }
        try {
            $used_union_type = Type_Parser::parse_tokens(Type_Tokenizer::get_fully_qualified_tokens($used_class_name, $this->aliases, $this->class_template_types, $this->type_aliases), null, $this->class_template_types, $this->type_aliases, true);
        } catch (Type_Parse_Tree_Exception $e) {
            $storage->docblock_issues[] = new Invalid_Docblock($e->get_message() . ' in docblock for ' . $storage->name, new Code_Location($this->file_scanner, $node, null, true));
            return;
        }
        if (!$used_union_type->is_single()) {
            $storage->docblock_issues[] = new Invalid_Docblock('@template-use cannot be a union type', new Code_Location($this->file_scanner, $node, null, true));
            return;
        }
        /** @psalm-suppress UnusedMethodCall */
        $used_union_type->queue_class_likes_for_scanning($this->codebase, $this->file_storage, $storage->template_types ?: []);
        foreach ($used_union_type->get_atomic_types() as $atomic_type) {
            if (!$atomic_type instanceof T_Generic_Object) {
                $storage->docblock_issues[] = new Invalid_Docblock('@template-use has invalid class ' . $atomic_type->get_id(), new Code_Location($this->file_scanner, $node, null, true));
                return;
            }
            $generic_class_lc = strtolower($atomic_type->value);
            if (!isset($storage->used_traits[$generic_class_lc])) {
                $storage->docblock_issues[] = new Invalid_Docblock('@template-use must include the name of an used class,' . ' got ' . $atomic_type->get_id(), new Code_Location($this->file_scanner, $node, null, true));
                return;
            }
            $used_type_parameters = [];
            $storage->template_type_uses_count[$generic_class_lc] = count($atomic_type->type_params);
            foreach ($atomic_type->type_params as $type_param) {
                $used_type_parameters[] = $type_param->replace_class_like('self', $storage->name);
            }
            $storage->template_extended_offsets[$atomic_type->value] = $used_type_parameters;
        }
    }
    private static function register_empty_constructor(Class_Like_Storage $class_storage): void
    {
        $method_name_lc = '__construct';
        if (isset($class_storage->methods[$method_name_lc])) {
            return;
        }
        $storage = $class_storage->methods['__construct'] = new Method_Storage();
        $storage->cased_name = '__construct';
        $storage->defining_fqcln = $class_storage->name;
        $storage->mutation_free = $storage->external_mutation_free = true;
        $storage->mutation_free_inferred = true;
        $class_storage->declaring_method_ids['__construct'] = new Method_Identifier($class_storage->name, '__construct');
        $class_storage->inheritable_method_ids['__construct'] = $class_storage->declaring_method_ids['__construct'];
        $class_storage->appearing_method_ids['__construct'] = $class_storage->declaring_method_ids['__construct'];
        $class_storage->overridden_method_ids['__construct'] = [];
        $storage->visibility = Class_Like_Analyzer::VISIBILITY_PUBLIC;
    }
    private function visit_class_const_declaration(Php_Parser\Node\Stmt\Class_Const $stmt, Class_Like_Storage $storage, string $fq_classlike_name): void
    {
        if ($storage->is_trait && $this->codebase->analysis_php_version_id < 80200) {
            Issue_Buffer::maybe_add(new Constant_Declaration_In_Trait('Traits cannot declare constants until PHP 8.2.0', new Code_Location($this->file_scanner, $stmt)));
            return;
        }
        $existing_constants = $storage->constants;
        $comment = $stmt->get_doc_comment();
        $var_comment = null;
        $deprecated = false;
        $description = null;
        $config = $this->config;
        if ($comment && $comment->get_text() && ($config->use_docblock_types || $config->use_docblock_property_types)) {
            $comments = Doc_Comment::parse_preserving_length($comment);
            if (isset($comments->tags['deprecated'])) {
                $deprecated = true;
            }
            $description = $comments->description;
            try {
                $var_comments = Comment_Analyzer::get_type_from_comment($comment, $this->file_scanner, $this->aliases, [], $this->type_aliases);
                $var_comment = array_pop($var_comments);
            } catch (Incorrect_Docblock_Exception $e) {
                $storage->docblock_issues[] = new Missing_Docblock_Type($e->get_message(), new Code_Location($this->file_scanner, $stmt, null, true));
            } catch (Docblock_Parse_Exception $e) {
                $storage->docblock_issues[] = new Invalid_Docblock($e->get_message(), new Code_Location($this->file_scanner, $stmt, null, true));
            }
        }
        foreach ($stmt->consts as $const) {
            if (isset($storage->constants[$const->name->name]) || isset($storage->enum_cases[$const->name->name])) {
                Issue_Buffer::maybe_add(new Duplicate_Constant('Constant names should be unique', new Code_Location($this->file_scanner, $const), $fq_classlike_name));
                continue;
            }
            $inferred_type = Simple_Type_Inferer::infer($this->codebase, new Node_Data_Provider(), $const->value, $this->aliases, null, $existing_constants, $fq_classlike_name);
            $type_location = null;
            if ($var_comment && $var_comment->type !== null) {
                $const_type = $var_comment->type;
                if ($var_comment->type_start !== null && $var_comment->type_end !== null && $var_comment->line_number !== null) {
                    $type_location = new Docblock_Type_Location($this->file_scanner, $var_comment->type_start, $var_comment->type_end, $var_comment->line_number);
                }
            } else {
                $const_type = $inferred_type;
            }
            $suppressed_issues = $var_comment ? $var_comment->suppressed_issues : [];
            $attributes = [];
            foreach ($stmt->attr_groups as $attr_group) {
                foreach ($attr_group->attrs as $attr) {
                    $attributes[] = $attr = Attribute_Resolver::resolve($this->codebase, $this->file_scanner, $this->file_storage, $this->aliases, $attr, $this->storage->name ?? null);
                    if ($attr->fq_class_name === 'Psalm\Deprecated' || $attr->fq_class_name === 'JetBrains\PhpStorm\Deprecated' || $attr->fq_class_name === 'Deprecated') {
                        $deprecated = true;
                    }
                }
            }
            $unresolved_node = null;
            if ($inferred_type && !($const->value instanceof Concat && $inferred_type->is_single() && $inferred_type->get_single_atomic()::class === T_String::class)) {
                $exists = true;
            } else {
                $exists = false;
                $unresolved_const_expr = Expression_Resolver::get_unresolved_class_const_expr($const->value, $this->aliases, $fq_classlike_name, $storage->parent_class);
                if ($unresolved_const_expr) {
                    $unresolved_node = $unresolved_const_expr;
                } else {
                    $const_type = Type::get_mixed();
                }
            }
            $storage->constants[$const->name->name] = $constant_storage = new Class_Constant_Storage($const_type, $inferred_type, $stmt->is_protected() ? Class_Like_Analyzer::VISIBILITY_PROTECTED : ($stmt->is_private() ? Class_Like_Analyzer::VISIBILITY_PRIVATE : Class_Like_Analyzer::VISIBILITY_PUBLIC), new Code_Location($this->file_scanner, $const->name), $type_location, new Code_Location($this->file_scanner, $const), $deprecated, $stmt->is_final(), $unresolved_node, $attributes, $suppressed_issues, $description);
            if ($this->codebase->analysis_php_version_id >= 80300 && !$storage->final && $stmt->type === null) {
                Issue_Buffer::maybe_add(new Missing_Class_Const_Type(sprintf('Class constant "%s::%s" should have a declared type.', $storage->name, $const->name->name), new Code_Location($this->file_scanner, $const)), $suppressed_issues);
            }
            if ($exists) {
                $existing_constants[$const->name->name] = $constant_storage;
            }
        }
    }
    private function visit_enum_declaration(Php_Parser\Node\Stmt\Enum_Case $stmt, Class_Like_Storage $storage, string $fq_classlike_name): void
    {
        if (isset($storage->constants[$stmt->name->name])) {
            Issue_Buffer::maybe_add(new Duplicate_Constant('Constant names should be unique', new Code_Location($this->file_scanner, $stmt), $fq_classlike_name));
            return;
        }
        $enum_value = null;
        $case_location = new Code_Location($this->file_scanner, $stmt);
        if ($stmt->expr !== null) {
            $case_type = Simple_Type_Inferer::infer($this->codebase, new Node_Data_Provider(), $stmt->expr, $this->aliases, $this->file_scanner, $storage->constants, $fq_classlike_name);
            if ($case_type) {
                if ($case_type->is_single_int_literal()) {
                    $enum_value = $case_type->get_single_int_literal();
                } elseif ($case_type->is_single_string_literal()) {
                    $enum_value = $case_type->get_single_string_literal();
                } else {
                    Issue_Buffer::maybe_add(new Invalid_Enum_Case_Value('Case of a backed enum should have either string or int value', $case_location, $fq_classlike_name));
                }
            } else {
                $enum_value = Expression_Resolver::get_unresolved_class_const_expr($stmt->expr, $this->aliases, $fq_classlike_name, $storage->parent_class);
            }
        }
        if (!isset($storage->enum_cases[$stmt->name->name])) {
            $case = new Enum_Case_Storage($enum_value, $case_location);
            $attrs = $this->get_attribute_storage_from_statement($this->codebase, $this->file_scanner, $this->file_storage, $this->aliases, $stmt, $this->storage->name ?? null);
            foreach ($attrs as $attribute) {
                if ($attribute->fq_class_name === 'Psalm\Deprecated' || $attribute->fq_class_name === 'JetBrains\PhpStorm\Deprecated' || $attribute->fq_class_name === 'Deprecated') {
                    $case->deprecated = true;
                    break;
                }
            }
            $comment = $stmt->get_doc_comment();
            if ($comment) {
                $comments = Doc_Comment::parse_preserving_length($comment);
                if (isset($comments->tags['deprecated'])) {
                    $case->deprecated = true;
                }
            }
            $storage->enum_cases[$stmt->name->name] = $case;
        } else {
            Issue_Buffer::maybe_add(new Duplicate_Enum_Case('Enum case names should be unique', $case_location, $fq_classlike_name));
        }
    }
    /**
     * @param PhpParser\Node\Stmt\Property|PhpParser\Node\Stmt\EnumCase $stmt
     * @return list<AttributeStorage>
     */
    private function get_attribute_storage_from_statement(Codebase $codebase, File_Scanner $file_scanner, File_Storage $file_storage, Aliases $aliases, Php_Parser\Node\Stmt $stmt, ?string $fq_classlike_name): array
    {
        $storages = [];
        foreach ($stmt->attr_groups as $attr_group) {
            foreach ($attr_group->attrs as $attr) {
                $storages[] = Attribute_Resolver::resolve($codebase, $file_scanner, $file_storage, $aliases, $attr, $fq_classlike_name);
            }
        }
        return $storages;
    }
    /**
     * @param non-empty-string $fq_classlike_name
     */
    private function visit_property_declaration(Php_Parser\Node\Stmt\Property $stmt, Config $config, Class_Like_Storage $storage, string $fq_classlike_name): void
    {
        $comment = $stmt->get_doc_comment();
        $var_comment = null;
        $property_is_initialized = false;
        $existing_constants = $storage->constants;
        if ($comment && $comment->get_text() && ($config->use_docblock_types || $config->use_docblock_property_types)) {
            if (preg_match('/[ \t\*]+@psalm-suppress[ \t]+PropertyNotSetInConstructor/', (string) $comment)) {
                $property_is_initialized = true;
            }
            if (preg_match('/[ \t\*]+@property[ \t]+/', (string) $comment)) {
                $storage->docblock_issues[] = new Invalid_Docblock('@property is valid only in docblocks for class', new Code_Location($this->file_scanner, $stmt, null, true));
            }
            try {
                $var_comments = Comment_Analyzer::get_type_from_comment($comment, $this->file_scanner, $this->aliases, !$stmt->is_static() ? $this->class_template_types : [], $this->type_aliases);
                $var_comment = array_pop($var_comments);
            } catch (Incorrect_Docblock_Exception $e) {
                $storage->docblock_issues[] = new Missing_Docblock_Type($e->get_message(), new Code_Location($this->file_scanner, $stmt, null, true));
            } catch (Docblock_Parse_Exception $e) {
                $storage->docblock_issues[] = new Invalid_Docblock($e->get_message(), new Code_Location($this->file_scanner, $stmt, null, true));
            }
        }
        $signature_type = null;
        $signature_type_location = null;
        if ($stmt->type) {
            $parser_property_type = $stmt->type;
            /** @var Identifier|IntersectionType|Name|NullableType|UnionType $parser_property_type */
            $signature_type_location = new Code_Location($this->file_scanner, $parser_property_type, null, false, Code_Location::FUNCTION_RETURN_TYPE);
            $signature_type = Type_Hint_Resolver::resolve($parser_property_type, $signature_type_location, $this->codebase, $this->file_storage, $this->storage, $this->aliases, $this->codebase->analysis_php_version_id);
        }
        $doc_var_group_type = $var_comment->type ?? null;
        if ($doc_var_group_type) {
            /** @psalm-suppress UnusedMethodCall */
            $doc_var_group_type->queue_class_likes_for_scanning($this->codebase, $this->file_storage);
        }
        foreach ($stmt->props as $property) {
            $doc_var_location = null;
            if (isset($storage->properties[$property->name->name])) {
                Issue_Buffer::maybe_add(new Duplicate_Property('Property ' . $fq_classlike_name . '::$' . $property->name->name . ' has already been defined', new Code_Location($this->file_scanner, $stmt, null, true), $fq_classlike_name . '::$' . $property->name->name));
            }
            $property_storage = $storage->properties[$property->name->name] = new Property_Storage();
            $property_storage->is_static = $stmt->is_static();
            $property_storage->type = $signature_type;
            $property_storage->signature_type = $signature_type;
            $property_storage->signature_type_location = $signature_type_location;
            $property_storage->type_location = $signature_type_location;
            $property_storage->location = new Code_Location($this->file_scanner, $property->name);
            $property_storage->stmt_location = new Code_Location($this->file_scanner, $stmt);
            $property_storage->has_default = (bool) $property->default;
            $property_storage->deprecated = $var_comment && $var_comment->deprecated;
            $property_storage->suppressed_issues = $var_comment ? $var_comment->suppressed_issues : [];
            $property_storage->internal = $var_comment ? $var_comment->psalm_internal : [];
            if (count($property_storage->internal) === 0 && $var_comment && $var_comment->internal) {
                $property_storage->internal = [Namespace_Analyzer::get_name_space_root($fq_classlike_name)];
            }
            $property_storage->readonly = $storage->readonly || $stmt->is_readonly() || $var_comment && $var_comment->readonly;
            $property_storage->allow_private_mutation = $var_comment && $var_comment->allow_private_mutation;
            $property_storage->description = $var_comment ? $var_comment->description : null;
            if (!$signature_type && $storage->readonly) {
                Issue_Buffer::maybe_add(new Missing_Property_Type('Properties of readonly classes must have a type', new Code_Location($this->file_scanner, $stmt, null, true), $fq_classlike_name . '::$' . $property->name->name));
            }
            if (!$signature_type && !$doc_var_group_type) {
                if ($property->default) {
                    $property_storage->suggested_type = Simple_Type_Inferer::infer($this->codebase, new Node_Data_Provider(), $property->default, $this->aliases, null, $existing_constants, $fq_classlike_name);
                }
                $property_storage->type = null;
            } else {
                if ($var_comment && $var_comment->type_start && $var_comment->type_end && $var_comment->line_number) {
                    $doc_var_location = new Docblock_Type_Location($this->file_scanner, $var_comment->type_start, $var_comment->type_end, $var_comment->line_number);
                }
                if ($doc_var_group_type) {
                    $property_storage->type = $doc_var_group_type;
                }
            }
            if ($property_storage->type && $property_storage->type !== $property_storage->signature_type) {
                if (!$property_storage->signature_type) {
                    $property_storage->type_location = $doc_var_location;
                }
                if ($property_storage->signature_type) {
                    $all_typehint_types_match = true;
                    $signature_atomic_types = $property_storage->signature_type->get_atomic_types();
                    foreach ($property_storage->type->get_atomic_types() as $key => $type) {
                        if (isset($signature_atomic_types[$key])) {
                            /** @psalm-suppress InaccessibleProperty We just created this type */
                            $type->from_docblock = false;
                        } else {
                            $all_typehint_types_match = false;
                        }
                    }
                    if ($all_typehint_types_match) {
                        /** @psalm-suppress InaccessibleProperty We just created this type */
                        $property_storage->type->from_docblock = false;
                    }
                    if ($property_storage->signature_type->is_nullable() && !$property_storage->type->is_nullable()) {
                        $property_storage->type = $property_storage->type->get_builder()->add_type(new T_Null())->freeze();
                    }
                }
                /** @psalm-suppress UnusedMethodCall */
                $property_storage->type->queue_class_likes_for_scanning($this->codebase, $this->file_storage);
            }
            if ($stmt->is_public()) {
                $property_storage->visibility = Class_Like_Analyzer::VISIBILITY_PUBLIC;
            } elseif ($stmt->is_protected()) {
                $property_storage->visibility = Class_Like_Analyzer::VISIBILITY_PROTECTED;
            } elseif ($stmt->is_private()) {
                $property_storage->visibility = Class_Like_Analyzer::VISIBILITY_PRIVATE;
            }
            $property_id = $fq_classlike_name . '::$' . $property->name->name;
            $storage->declaring_property_ids[$property->name->name] = $fq_classlike_name;
            $storage->appearing_property_ids[$property->name->name] = $property_id;
            if ($property_is_initialized) {
                $storage->initialized_properties[$property->name->name] = true;
            }
            if (!$stmt->is_private()) {
                $storage->inheritable_property_ids[$property->name->name] = $property_id;
            }
            $attrs = $this->get_attribute_storage_from_statement($this->codebase, $this->file_scanner, $this->file_storage, $this->aliases, $stmt, $this->storage->name ?? null);
            foreach ($attrs as $attribute) {
                if ($attribute->fq_class_name === 'Psalm\Deprecated' || $attribute->fq_class_name === 'JetBrains\PhpStorm\Deprecated' || $attribute->fq_class_name === 'Deprecated') {
                    $property_storage->deprecated = true;
                }
                if ($attribute->fq_class_name === 'Psalm\Internal' && !$property_storage->internal) {
                    $property_storage->internal = [Namespace_Analyzer::get_name_space_root($fq_classlike_name)];
                }
                if ($attribute->fq_class_name === 'Psalm\Readonly') {
                    $property_storage->readonly = true;
                }
                $property_storage->attributes[] = $attribute;
            }
            // Process property hooks
            foreach ($stmt->hooks as $hook) {
                $hook_name = strtolower($hook->name->to_string());
                if ($hook_name === 'get' || $hook_name === 'set') {
                    $hook_storage = new Property_Hook_Storage($hook_name === 'get', $hook->is_final(), $hook->by_ref, new Code_Location($this->file_scanner, $hook, null, true));
                    if ($hook_storage->is_get) {
                        $property_storage->hook_get = $hook_storage;
                    } else {
                        $property_storage->hook_set = $hook_storage;
                    }
                } else {
                    $storage->docblock_issues[] = new ParseError('Property hooks must be either "get" or "set"', new Code_Location($this->file_scanner, $stmt, null, true));
                }
            }
            // Validate interface properties
            if ($storage->is_interface && $this->codebase->analysis_php_version_id >= 80400) {
                if (!$property_storage->hook_get && !$property_storage->hook_set) {
                    $storage->docblock_issues[] = new ParseError('Interface properties must have at least one hook', new Code_Location($this->file_scanner, $stmt, null, true));
                }
                if ($stmt->is_static()) {
                    $storage->docblock_issues[] = new ParseError('Interface properties cannot be static', new Code_Location($this->file_scanner, $stmt, null, true));
                }
                // Interface properties must be explicitly declared as public
                if (!$stmt->is_public() || ($stmt->flags & Php_Parser\Modifiers::VISIBILITY_MASK) === 0) {
                    $storage->docblock_issues[] = new ParseError('Interface properties must be public', new Code_Location($this->file_scanner, $stmt, null, true));
                }
            }
        }
    }
    /**
     * @return array<string, LinkableTypeAlias>
     */
    private function get_imported_type_aliases(Class_Like_Docblock_Comment $comment, string $fq_classlike_name): array
    {
        /** @var array<string, LinkableTypeAlias> $results */
        $results = [];
        foreach ($comment->imported_types as $import_type_entry) {
            $imported_type_data = $import_type_entry['parts'];
            $location = new Docblock_Type_Location($this->file_scanner, $import_type_entry['start_offset'], $import_type_entry['end_offset'], $import_type_entry['line_number']);
            // There are two valid forms:
            // @psalm-import Thing from Something
            // @psalm-import Thing from Something as Alias
            // but there could be leftovers after that
            if (count($imported_type_data) < 3) {
                $this->file_storage->docblock_issues[] = new Invalid_Type_Import('Invalid import in docblock for ' . $fq_classlike_name . ', expecting "<TypeName> from <ClassName>",' . ' got "' . implode(' ', $imported_type_data) . '" instead.', $location);
                continue;
            }
            if ($imported_type_data[1] === 'from' && !empty($imported_type_data[0]) && !empty($imported_type_data[2])) {
                $type_alias_name = $as_alias_name = $imported_type_data[0];
                $declaring_classlike_name = $imported_type_data[2];
            } else {
                $this->file_storage->docblock_issues[] = new Invalid_Type_Import('Invalid import in docblock for ' . $fq_classlike_name . ', expecting "<TypeName> from <ClassName>", got "' . implode(' ', [$imported_type_data[0], $imported_type_data[1], $imported_type_data[2]]) . '" instead.', $location);
                continue;
            }
            if (count($imported_type_data) >= 4 && $imported_type_data[3] === 'as') {
                // long form
                if (empty($imported_type_data[4])) {
                    $this->file_storage->docblock_issues[] = new Invalid_Type_Import('Invalid import in docblock for ' . $fq_classlike_name . ', expecting "as <TypeName>", got "' . $imported_type_data[3] . ' ' . ($imported_type_data[4] ?? '') . '" instead.', $location);
                    continue;
                }
                $as_alias_name = $imported_type_data[4];
            }
            $declaring_fq_classlike_name = Type::get_fqcln_from_string($declaring_classlike_name, $this->aliases);
            $this->codebase->scanner->queue_class_like_for_scanning($declaring_fq_classlike_name);
            $this->file_storage->referenced_classlikes[strtolower($declaring_fq_classlike_name)] = $declaring_fq_classlike_name;
            $results[$as_alias_name] = new Linkable_Type_Alias($declaring_fq_classlike_name, $type_alias_name, $import_type_entry['line_number'], $import_type_entry['start_offset'], $import_type_entry['end_offset']);
        }
        return $results;
    }
    /**
     * @param  array<string, TypeAlias> $type_aliases
     * @return array<string, InlineTypeAlias>
     * @throws DocblockParseException if there was a problem parsing the docblock
     */
    public static function get_type_aliases_from_comment(Php_Parser\Comment\Doc $comment, Aliases $aliases, ?array $type_aliases, ?string $self_fqcln): array
    {
        $parsed_docblock = Doc_Comment::parse_preserving_length($comment);
        if (!isset($parsed_docblock->tags['psalm-type']) && !isset($parsed_docblock->tags['phpstan-type'])) {
            return [];
        }
        $type_alias_comment_lines = array_merge($parsed_docblock->tags['phpstan-type'] ?? [], $parsed_docblock->tags['psalm-type'] ?? []);
        return self::get_type_aliases_from_comment_lines($type_alias_comment_lines, $aliases, $type_aliases, $self_fqcln);
    }
    /**
     * @param  array<string>    $type_alias_comment_lines
     * @param  array<string, TypeAlias> $type_aliases
     * @return array<string, InlineTypeAlias>
     * @throws DocblockParseException if there was a problem parsing the docblock
     */
    private static function get_type_aliases_from_comment_lines(array $type_alias_comment_lines, Aliases $aliases, ?array $type_aliases, ?string $self_fqcln): array
    {
        $type_alias_tokens = [];
        foreach ($type_alias_comment_lines as $var_line) {
            $var_line = trim($var_line);
            if (!$var_line) {
                continue;
            }
            $var_line_parts = preg_split('/( |=)/', $var_line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
            if (!$var_line_parts) {
                continue;
            }
            $type_alias = array_shift($var_line_parts);
            if (!isset($var_line_parts[0])) {
                continue;
            }
            while (isset($var_line_parts[0]) && $var_line_parts[0] === ' ') {
                array_shift($var_line_parts);
            }
            if (!isset($var_line_parts[0])) {
                continue;
            }
            if ($var_line_parts[0] === '=') {
                array_shift($var_line_parts);
            }
            if (!isset($var_line_parts[0])) {
                continue;
            }
            while (isset($var_line_parts[0]) && $var_line_parts[0] === ' ') {
                array_shift($var_line_parts);
            }
            $type_string = implode('', $var_line_parts);
            $type_string = ltrim($type_string, "* \n\r");
            try {
                $type_string = Comment_Analyzer::split_doc_line($type_string)[0];
            } catch (Docblock_Parse_Exception $e) {
                throw new Docblock_Parse_Exception($type_string . ' is not a valid type: ' . $e->get_message());
            }
            $type_string = Comment_Analyzer::sanitize_docblock_type($type_string);
            try {
                $type_tokens = Type_Tokenizer::get_fully_qualified_tokens($type_string, $aliases, null, $type_alias_tokens + $type_aliases, $self_fqcln);
            } catch (Type_Parse_Tree_Exception $e) {
                throw new Docblock_Parse_Exception($type_string . ' is not a valid type: ' . $e->get_message());
            }
            $type_alias_tokens[$type_alias] = new Inline_Type_Alias($type_tokens);
        }
        return $type_alias_tokens;
    }
}