<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer;

use Attribute;
use Exception;
use InvalidArgumentException;
use LogicException;
use Php_Parser;
use Php_Parser\Node\Stmt\Class_;
use Psalm\Aliases;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Config;
use Psalm\Context;
use Psalm\Doc_Comment;
use Psalm\Exception\Docblock_Parse_Exception;
use Psalm\File_Manipulation;
use Psalm\Internal\Analyzer\Function_Like\Return_Type_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Class_Template_Param_Collector;
use Psalm\Internal\Analyzer\Statements\Expression\Class_Const_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Atomic_Property_Fetch_Analyzer;
use Psalm\Internal\File_Manipulation\File_Manipulation_Buffer;
use Psalm\Internal\File_Manipulation\Property_Docblock_Manipulator;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Provider\Node_Data_Provider;
use Psalm\Internal\Type\Comparator\Type_Comparison_Result;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Internal\Type\Template_Inferred_Type_Replacer;
use Psalm\Internal\Type\Template_Result;
use Psalm\Internal\Type\Template_Standin_Type_Replacer;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Issue\Deprecated_Class;
use Psalm\Issue\Deprecated_Interface;
use Psalm\Issue\Deprecated_Trait;
use Psalm\Issue\Duplicate_Enum_Case_Value;
use Psalm\Issue\Extension_Requirement_Violation;
use Psalm\Issue\Implementation_Requirement_Violation;
use Psalm\Issue\Inaccessible_Method;
use Psalm\Issue\Inheritor_Violation;
use Psalm\Issue\Internal_Class;
use Psalm\Issue\Invalid_Enum_Case_Value;
use Psalm\Issue\Invalid_Extend_Class;
use Psalm\Issue\Invalid_Interface_Implementation;
use Psalm\Issue\Invalid_Traversable_Implementation;
use Psalm\Issue\Method_Signature_Mismatch;
use Psalm\Issue\Mismatching_Docblock_Property_Type;
use Psalm\Issue\Missing_Constructor;
use Psalm\Issue\Missing_Immutable_Annotation;
use Psalm\Issue\Missing_Property_Type;
use Psalm\Issue\Mutable_Dependency;
use Psalm\Issue\No_Enum_Properties;
use Psalm\Issue\Non_Invariant_Docblock_Property_Type;
use Psalm\Issue\Non_Invariant_Property_Type;
use Psalm\Issue\Overridden_Property_Access;
use Psalm\Issue\ParseError;
use Psalm\Issue\Property_Not_Set_In_Constructor;
use Psalm\Issue\Reserved_Word;
use Psalm\Issue\Undefined_Class;
use Psalm\Issue\Undefined_Interface;
use Psalm\Issue\Undefined_Trait;
use Psalm\Issue\Unimplemented_Abstract_Method;
use Psalm\Issue\Unimplemented_Interface_Method;
use Psalm\Issue_Buffer;
use Psalm\Node\Expr\Virtual_Static_Call;
use Psalm\Node\Expr\Virtual_Variable;
use Psalm\Node\Name\Virtual_Fully_Qualified;
use Psalm\Node\Stmt\Virtual_Class_Method;
use Psalm\Node\Stmt\Virtual_Expression;
use Psalm\Node\Virtual_Arg;
use Psalm\Node\Virtual_Identifier;
use Psalm\Plugin\Event_Handler\Event\After_Class_Like_Analysis_Event;
use Psalm\Statements_Source;
use Psalm\Storage\Class_Like_Storage;
use Psalm\Storage\Function_Like_Parameter;
use Psalm\Storage\Method_Storage;
use Psalm\Type;
use Psalm\Type\Atomic\T_Generic_Object;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Atomic\T_Void;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function array_pop;
use function array_values;
use function assert;
use function count;
use function explode;
use function implode;
use function in_array;
use function preg_match;
use function preg_replace;
use function reset;
use function str_replace;
use function strtolower;
use function substr;
/**
 * @internal
 */
final class Class_Analyzer extends Class_Like_Analyzer
{
    /**
     * @var array<string, Union>
     */
    public array $inferred_property_types = [];
    /**
     * @param PhpParser\Node\Stmt\Class_|PhpParser\Node\Stmt\Enum_ $class
     */
    public function __construct(Php_Parser\Node\Stmt $class, Source_Analyzer $source, ?string $fq_class_name)
    {
        if (!$fq_class_name) {
            if (!$class instanceof Php_Parser\Node\Stmt\Class_) {
                throw new UnexpectedValueException('Anonymous enums are not allowed');
            }
            $fq_class_name = self::get_anonymous_class_name($class, $source->get_aliases(), $source->get_file_path());
        }
        parent::__construct($class, $source, $fq_class_name);
        if ($this->class instanceof Php_Parser\Node\Stmt\Class_ && $this->class->extends) {
            $this->parent_fq_class_name = self::get_fqcln_from_name_object($this->class->extends, $this->source->get_aliases());
        }
    }
    /** @return non-empty-string */
    public static function get_anonymous_class_name(Php_Parser\Node\Stmt\Class_ $class, Aliases $aliases, string $file_path): string
    {
        $class_name = preg_replace('/[^A-Za-z0-9]/', '_', $file_path) . '_' . $class->get_line() . '_' . (int) $class->get_attribute('startFilePos');
        $fq_class_name = Type::get_fqcln_from_string($class_name, $aliases);
        if ($fq_class_name === '') {
            throw new LogicException('Invalid class name, should never happen');
        }
        return $fq_class_name;
    }
    public function analyze(?Context $class_context = null, ?Context $global_context = null): void
    {
        $class = $this->class;
        if (!$class instanceof Php_Parser\Node\Stmt\Class_ && !$class instanceof Php_Parser\Node\Stmt\Enum_) {
            throw new LogicException('Something went badly wrong');
        }
        $fq_class_name = $class_context && $class_context->self ? $class_context->self : $this->fq_class_name;
        $storage = $this->storage;
        if ($storage->has_visitor_issues) {
            return;
        }
        if ($class->name && (preg_match('/(^|\\\\)(int|float|bool|string|void|null|false|true|object|mixed)$/i', $fq_class_name) || strtolower($fq_class_name) === 'resource')) {
            $class_name_parts = explode('\\', $fq_class_name);
            $class_name = array_pop($class_name_parts);
            Issue_Buffer::maybe_add(new Reserved_Word($class_name . ' is a reserved word', new Code_Location($this, $class->name, null, true), $class_name), $storage->suppressed_issues + $this->get_suppressed_issues());
            return;
        }
        $project_analyzer = $this->file_analyzer->project_analyzer;
        $codebase = $this->get_codebase();
        if ($codebase->alter_code && $class->name && $codebase->classes_to_move) {
            if (isset($codebase->classes_to_move[strtolower($this->fq_class_name)])) {
                $destination_class = $codebase->classes_to_move[strtolower($this->fq_class_name)];
                $source_class_parts = explode('\\', $this->fq_class_name);
                $destination_class_parts = explode('\\', $destination_class);
                array_pop($source_class_parts);
                array_pop($destination_class_parts);
                $source_ns = implode('\\', $source_class_parts);
                $destination_ns = implode('\\', $destination_class_parts);
                if (strtolower($source_ns) !== strtolower($destination_ns)) {
                    if ($storage->namespace_name_location) {
                        $bounds = $storage->namespace_name_location->get_selection_bounds();
                        $file_manipulations = [new File_Manipulation($bounds[0], $bounds[1], $destination_ns)];
                        File_Manipulation_Buffer::add($this->get_file_path(), $file_manipulations);
                    } elseif (!$source_ns) {
                        $first_statement_pos = $this->get_file_analyzer()->get_first_statement_offset();
                        if ($first_statement_pos === -1) {
                            $first_statement_pos = (int) $class->get_attribute('startFilePos');
                        }
                        $file_manipulations = [new File_Manipulation($first_statement_pos, $first_statement_pos, 'namespace ' . $destination_ns . ';' . "\n\n", true)];
                        File_Manipulation_Buffer::add($this->get_file_path(), $file_manipulations);
                    }
                }
            }
            $codebase->classlikes->handle_class_like_reference_in_migration($codebase, $this, $class->name, $this->fq_class_name, null);
        }
        foreach ($storage->docblock_issues as $docblock_issue) {
            Issue_Buffer::maybe_add($docblock_issue);
        }
        $parent_fq_class_name = $this->parent_fq_class_name;
        if ($class instanceof Php_Parser\Node\Stmt\Class_ && $class->extends && $parent_fq_class_name) {
            $this->check_parent_class($class, $class->extends, $fq_class_name, $parent_fq_class_name, $storage, $codebase, $class_context);
        }
        $class_union = new Union([new T_Named_Object($fq_class_name)]);
        foreach ($storage->parent_classes + $storage->direct_class_interfaces as $parent_class) {
            $parent_storage = $codebase->classlikes->get_storage_for($parent_class);
            if ($parent_storage && $parent_storage->inheritors) {
                if (!Union_Type_Comparator::is_contained_by($codebase, $class_union, $parent_storage->inheritors)) {
                    Issue_Buffer::maybe_add(new Inheritor_Violation('Class ' . $fq_class_name . ' is not an allowed inheritor of parent class ' . $parent_class, new Code_Location($this, $this->class)), $this->get_suppressed_issues());
                }
            }
        }
        if ($storage->template_types) {
            foreach ($storage->template_types as $param_name => $_) {
                $fq_classlike_name = Type::get_fqcln_from_string($param_name, $this->get_aliases());
                if ($codebase->class_or_interface_exists($fq_classlike_name)) {
                    Issue_Buffer::maybe_add(new Reserved_Word('Cannot use ' . $param_name . ' as template name since the class already exists', new Code_Location($this, $this->class), 'resource'), $this->get_suppressed_issues());
                }
            }
        }
        if (($storage->templated_mixins || $storage->named_mixins) && $storage->mixin_declaring_fqcln === $storage->name) {
            /** @var non-empty-array<int, TTemplateParam|TNamedObject> $mixins */
            $mixins = array_merge($storage->templated_mixins, $storage->named_mixins);
            $union = new Union($mixins);
            $static_self = new T_Named_Object($storage->name, true);
            $union = Type_Expander::expand_union($codebase, $union, $storage->name, $static_self, null);
            /** @psalm-suppress UnusedMethodCall This call actually has the side effect of creating issues */
            $union->check($this, new Code_Location($this, $class->name ?: $class, null, true), $this->get_suppressed_issues());
        }
        if ($storage->template_extended_params) {
            foreach ($storage->template_extended_params as $type_map) {
                foreach ($type_map as $atomic_type) {
                    /** @psalm-suppress UnusedMethodCall This call actually has the side effect of creating issues */
                    $atomic_type->check($this, new Code_Location($this, $class->name ?: $class, null, true), $storage->get_suppressed_issues_for_template_extend_params() + $this->get_suppressed_issues());
                }
            }
        }
        if (!$class_context) {
            $class_context = new Context($this->fq_class_name);
            $class_context->parent = $parent_fq_class_name;
        }
        if ($global_context) {
            $class_context->strict_types = $global_context->strict_types;
        }
        if ($this->check_implemented_interfaces($class_context, $class, $codebase, $fq_class_name, $storage) === false) {
            return;
        }
        if ($storage->invalid_dependencies) {
            return;
        }
        if (!$storage->abstract) {
            foreach ($storage->declaring_method_ids as $declaring_method_id) {
                $method_storage = $codebase->methods->get_storage($declaring_method_id);
                $declaring_class_name = $declaring_method_id->fq_class_name;
                $method_name_lc = $declaring_method_id->method_name;
                if ($method_storage->abstract) {
                    if (Issue_Buffer::accepts(new Unimplemented_Abstract_Method('Method ' . $method_name_lc . ' is not defined on class ' . $this->fq_class_name . ', defined abstract in ' . $declaring_class_name, new Code_Location($this, $class->name ?? $class, $class_context->include_location, true)), $storage->suppressed_issues + $this->get_suppressed_issues())) {
                        return;
                    }
                }
            }
        }
        Attributes_Analyzer::analyze($this, $class_context, $storage, $class->attr_groups, Attribute::TARGET_CLASS, $storage->suppressed_issues + $this->get_suppressed_issues());
        self::add_context_properties($this, $storage, $class_context, $this->fq_class_name, $this->parent_fq_class_name, $class->stmts);
        $constructor_analyzer = null;
        $member_stmts = [];
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Php_Parser\Node\Stmt\Class_Method) {
                $method_analyzer = $this->analyze_class_method($stmt, $storage, $this, $class_context, $global_context);
                if ($stmt->name->name === '__construct') {
                    $constructor_analyzer = $method_analyzer;
                }
            } elseif ($stmt instanceof Php_Parser\Node\Stmt\Trait_Use) {
                if ($this->analyze_trait_use($this->source->get_aliases(), $stmt, $project_analyzer, $storage, $class_context, $global_context, $constructor_analyzer) === false) {
                    return;
                }
            } elseif ($stmt instanceof Php_Parser\Node\Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    if ($storage->is_enum) {
                        Issue_Buffer::maybe_add(new No_Enum_Properties('Enums cannot have properties', new Code_Location($this, $prop), $fq_class_name));
                        continue;
                    }
                    if ($prop->default) {
                        $member_stmts[] = $stmt;
                    }
                    if ($codebase->alter_code) {
                        $property_id = strtolower($this->fq_class_name) . '::$' . $prop->name;
                        $property_storage = $codebase->properties->get_storage($property_id);
                        if ($property_storage->type && $property_storage->type_location && $property_storage->type_location !== $property_storage->signature_type_location) {
                            $replace_type = Type_Expander::expand_union($codebase, $property_storage->type, $this->get_fqcln(), $this->get_fqcln(), $this->get_parent_fqcln());
                            $codebase->classlikes->handle_docblock_type_in_migration($codebase, $this, $replace_type, $property_storage->type_location, null);
                        }
                        foreach ($codebase->properties_to_rename as $original_property_id => $new_property_name) {
                            if ($property_id === $original_property_id) {
                                $file_manipulations = [new File_Manipulation((int) $prop->name->get_attribute('startFilePos'), (int) $prop->name->get_attribute('endFilePos') + 1, '$' . $new_property_name)];
                                File_Manipulation_Buffer::add($this->get_file_path(), $file_manipulations);
                            }
                        }
                    }
                }
            } elseif ($stmt instanceof Php_Parser\Node\Stmt\Class_Const) {
                $member_stmts[] = $stmt;
                foreach ($stmt->consts as $const) {
                    if ($const->name->to_lower_string() === 'class') {
                        Issue_Buffer::maybe_add(new Reserved_Word('A class constant cannot be named \'class\'', new Code_Location($this, $this->class), $this->fq_class_name));
                    }
                    $const_id = strtolower($this->fq_class_name) . '::' . $const->name;
                    foreach ($codebase->class_constants_to_rename as $original_const_id => $new_const_name) {
                        if ($const_id === $original_const_id) {
                            $file_manipulations = [new File_Manipulation((int) $const->name->get_attribute('startFilePos'), (int) $const->name->get_attribute('endFilePos') + 1, $new_const_name)];
                            File_Manipulation_Buffer::add($this->get_file_path(), $file_manipulations);
                        }
                    }
                }
            }
        }
        $statements_analyzer = new Statements_Analyzer($this, new Node_Data_Provider());
        $statements_analyzer->analyze($member_stmts, $class_context, $global_context, true);
        Class_Const_Analyzer::analyze($storage, $this->get_codebase());
        $config = Config::get_instance();
        if ($class instanceof Php_Parser\Node\Stmt\Class_) {
            $this->check_property_initialization($codebase, $config, $storage, $class_context, $global_context, $constructor_analyzer);
        }
        if ($class instanceof Php_Parser\Node\Stmt\Enum_) {
            $this->check_enum();
        }
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Php_Parser\Node\Stmt\Property) {
                $this->analyze_property($this, $stmt, $class_context);
            } elseif ($stmt instanceof Php_Parser\Node\Stmt\Trait_Use) {
                foreach ($stmt->traits as $trait) {
                    $fq_trait_name = self::get_fqcln_from_name_object($trait, $this->source->get_aliases());
                    try {
                        $trait_file_analyzer = $project_analyzer->get_file_analyzer_for_class_like($fq_trait_name);
                    } catch (Exception) {
                        continue;
                    }
                    $trait_storage = $codebase->classlike_storage_provider->get($fq_trait_name);
                    $trait_node = $codebase->classlikes->get_trait_node($fq_trait_name);
                    $trait_aliases = $trait_storage->aliases;
                    if ($trait_aliases === null) {
                        continue;
                    }
                    $trait_analyzer = new Trait_Analyzer($trait_node, $trait_file_analyzer, $fq_trait_name, $trait_aliases);
                    $fq_trait_name_lc = strtolower($fq_trait_name);
                    $this->check_template_params($codebase, $storage, $trait_storage, new Code_Location($this, $trait), $storage->template_type_uses_count[$fq_trait_name_lc] ?? 0);
                    foreach ($trait_node->stmts as $trait_stmt) {
                        if ($trait_stmt instanceof Php_Parser\Node\Stmt\Property) {
                            $this->analyze_property($trait_analyzer, $trait_stmt, $class_context);
                        }
                    }
                    $trait_file_analyzer->clear_source_before_destruction();
                }
            }
        }
        $pseudo_methods = $storage->pseudo_methods + $storage->pseudo_static_methods;
        Method_Comparator::compare_pseudo_methods($pseudo_methods, $this->fq_class_name, $codebase, $storage);
        $event = new After_Class_Like_Analysis_Event($class, $storage, $this, $codebase, []);
        if ($codebase->config->event_dispatcher->dispatch_after_class_like_analysis($event) === false) {
            return;
        }
        $file_manipulations = $event->get_file_replacements();
        if ($file_manipulations) {
            File_Manipulation_Buffer::add($this->get_file_path(), $file_manipulations);
        }
    }
    public static function add_context_properties(Statements_Source $statements_source, Class_Like_Storage $storage, Context $class_context, string $fq_class_name, ?string $parent_fq_class_name, array $stmts = []): void
    {
        $codebase = $statements_source->get_codebase();
        foreach ($storage->appearing_property_ids as $property_name => $appearing_property_id) {
            $property_class_name = $codebase->properties->get_declaring_class_for_property($appearing_property_id, true);
            if ($property_class_name === null) {
                continue;
            }
            $property_class_storage = $codebase->classlike_storage_provider->get($property_class_name);
            $property_storage = $property_class_storage->properties[$property_name];
            if (isset($storage->overridden_property_ids[$property_name])) {
                foreach ($storage->overridden_property_ids[$property_name] as $overridden_property_id) {
                    [$guide_class_name] = explode('::$', $overridden_property_id);
                    $guide_class_storage = $codebase->classlike_storage_provider->get($guide_class_name);
                    $guide_property_storage = $guide_class_storage->properties[$property_name];
                    if ($property_storage->visibility > $guide_property_storage->visibility && $property_storage->location) {
                        Issue_Buffer::maybe_add(new Overridden_Property_Access('Property ' . $fq_class_name . '::$' . $property_name . ' has different access level than ' . $guide_class_name . '::$' . $property_name, $property_storage->location));
                    }
                    if (($property_storage->signature_type && !$guide_property_storage->signature_type || !$property_storage->signature_type && $guide_property_storage->signature_type || $property_storage->signature_type && !$property_storage->signature_type->equals($guide_property_storage->signature_type)) && $property_storage->location) {
                        Issue_Buffer::maybe_add(new Non_Invariant_Property_Type('Property ' . $fq_class_name . '::$' . $property_name . ' has type ' . ($property_storage->signature_type ? $property_storage->signature_type->get_id() : '<empty>') . ", not invariant with " . $guide_class_name . '::$' . $property_name . ' of type ' . ($guide_property_storage->signature_type ? $guide_property_storage->signature_type->get_id() : '<empty>'), $property_storage->location), $property_storage->suppressed_issues);
                    }
                    if ($property_storage->type === null) {
                        // Property type not set, no need to check for docblock invariance
                        continue;
                    }
                    $property_type = $property_storage->type;
                    $guide_property_type = $guide_property_storage->type ?? Type::get_mixed();
                    // Set upper bounds for all templates
                    $lower_bounds = [];
                    $extended_templates = $storage->template_extended_params ?? [];
                    foreach ($extended_templates as $et_name => $et_array) {
                        foreach ($et_array as $et_class_name => $extended_template) {
                            if (!isset($lower_bounds[$et_class_name][$et_name])) {
                                $lower_bounds[$et_class_name][$et_name] = $extended_template;
                            }
                        }
                    }
                    // Get actual types used for templates (to support @template-covariant)
                    $template_standins = new Template_Result($lower_bounds, []);
                    Template_Standin_Type_Replacer::fill_template_result($guide_property_type, $template_standins, $codebase, null, $property_type);
                    // Iterate over parent classes to find template-covariants, and replace the upper bound with the
                    // standin. Since @template-covariant allows child classes, we want to use the standin type
                    // instead of the template extended type.
                    $parent_class = $storage->parent_class;
                    while ($parent_class !== null) {
                        $parent_storage = $codebase->classlike_storage_provider->get($parent_class);
                        foreach ($parent_storage->template_covariants ?? [] as $pt_offset => $covariant) {
                            if ($covariant) {
                                // If template_covariants is set template_types should also be set
                                assert($parent_storage->template_types !== null);
                                $pt_name = array_keys($parent_storage->template_types)[$pt_offset] ?? null;
                                if ($pt_name === null) {
                                    continue;
                                }
                                if (isset($template_standins->lower_bounds[$pt_name][$parent_class])) {
                                    $lower_bounds[$pt_name][$parent_class] = Template_Standin_Type_Replacer::get_most_specific_type_from_bounds($template_standins->lower_bounds[$pt_name][$parent_class], $codebase);
                                }
                            }
                        }
                        $parent_class = $parent_storage->parent_class;
                    }
                    $template_result = new Template_Result([], $lower_bounds);
                    $guide_property_type = Template_Inferred_Type_Replacer::replace($guide_property_type, $template_result, $codebase);
                    $property_type = Template_Inferred_Type_Replacer::replace($property_type, $template_result, $codebase);
                    if ($guide_property_storage->readonly && Union_Type_Comparator::is_contained_by($codebase, $property_type, $guide_property_type, false, false, null, false, false)) {
                        // if the original property is readonly, it cannot be written
                        // therefore invariance is not a problem, if the parent type contains the child type
                    } elseif ($property_storage->location && !$property_type->equals($guide_property_type, false) && $guide_class_storage->user_defined) {
                        Issue_Buffer::maybe_add(new Non_Invariant_Docblock_Property_Type('Property ' . $fq_class_name . '::$' . $property_name . ' has type ' . $property_type->get_id() . ", not invariant with " . $guide_class_name . '::$' . $property_name . ' of type ' . $guide_property_type->get_id(), $property_storage->location), $property_storage->suppressed_issues);
                    }
                }
            }
            if ($property_storage->type) {
                $property_type = $property_storage->type;
                if (!$property_type->is_mixed() && (!$property_storage->is_promoted || strtolower($fq_class_name) !== strtolower($property_class_name) && isset($storage->declaring_method_ids['__construct']) && strtolower($storage->declaring_method_ids['__construct']->fq_class_name) === strtolower($fq_class_name)) && !$property_storage->has_default && !($property_type->is_nullable() && $property_type->from_docblock)) {
                    $property_type = $property_type->set_properties(['initialized' => false, 'from_property' => true, 'from_static_property' => $property_storage->is_static === true]);
                }
            } else if (!$property_storage->has_default && (!$property_storage->is_promoted || strtolower($fq_class_name) !== strtolower($property_class_name) && isset($storage->declaring_method_ids['__construct']) && strtolower($storage->declaring_method_ids['__construct']->fq_class_name) === strtolower($fq_class_name))) {
                $property_type = new Union([new T_Mixed()], ['initialized' => false, 'from_property' => true, 'from_static_property' => $property_storage->is_static === true]);
            } else {
                $property_type = Type::get_mixed();
            }
            $property_type_location = $property_storage->type_location;
            $fleshed_out_type = !$property_type->is_mixed() ? Type_Expander::expand_union($codebase, $property_type, $fq_class_name, $fq_class_name, $parent_fq_class_name, true, false, $storage->final) : $property_type;
            $class_template_params = Class_Template_Param_Collector::collect($codebase, $property_class_storage, $storage, null, new T_Named_Object($fq_class_name), true);
            if ($class_template_params) {
                $this_object_type = self::get_this_object_type($storage, $fq_class_name);
                if (!$this_object_type instanceof T_Generic_Object) {
                    $type_params = [];
                    foreach ($class_template_params as $type_map) {
                        $type_params[] = array_values($type_map)[0];
                    }
                    $this_object_type = new T_Generic_Object($this_object_type->value, $type_params);
                }
                $fleshed_out_type = Atomic_Property_Fetch_Analyzer::localize_property_type($codebase, $fleshed_out_type, $this_object_type, $storage, $property_class_storage);
            }
            if ($property_type_location && !$fleshed_out_type->is_mixed()) {
                $stmt = array_filter($stmts, static fn($stmt): bool => $stmt instanceof Php_Parser\Node\Stmt\Property && isset($stmt->props[0]->name->name) && $stmt->props[0]->name->name === $property_name);
                $suppressed = [];
                if (count($stmt) > 0) {
                    $stmt = array_pop($stmt);
                    $doc_comment = $stmt->get_doc_comment();
                    if ($doc_comment) {
                        try {
                            $doc_block = Doc_Comment::parse_preserving_length($doc_comment);
                            $suppressed = $doc_block->tags['psalm-suppress'] ?? [];
                        } catch (Docblock_Parse_Exception) {
                            // do nothing to keep original behavior
                        }
                    }
                }
                /** @psalm-suppress UnusedMethodCall This call actually has the side effect of creating issues */
                $fleshed_out_type->check($statements_source, $property_type_location, $storage->suppressed_issues + $statements_source->get_suppressed_issues() + $suppressed, [], false);
                if ($property_storage->signature_type) {
                    $union_comparison_result = new Type_Comparison_Result();
                    if (!Union_Type_Comparator::is_contained_by($codebase, $fleshed_out_type, $property_storage->signature_type, false, false, $union_comparison_result) && !$union_comparison_result->type_coerced_from_mixed) {
                        Issue_Buffer::maybe_add(new Mismatching_Docblock_Property_Type('Parameter ' . $property_class_name . '::$' . $property_name . ' has wrong type \'' . $fleshed_out_type . '\', should be \'' . $property_storage->signature_type . '\'', $property_type_location));
                    }
                }
            }
            if ($property_storage->is_static) {
                $property_id = $fq_class_name . '::$' . $property_name;
                $class_context->vars_in_scope[$property_id] = $fleshed_out_type;
            } else {
                $class_context->vars_in_scope['$this->' . $property_name] = $fleshed_out_type;
            }
        }
        foreach ($storage->pseudo_property_get_types as $property_name => $property_type) {
            $property_name = substr($property_name, 1);
            if (isset($class_context->vars_in_scope['$this->' . $property_name])) {
                $fleshed_out_type = !$property_type->is_mixed() ? Type_Expander::expand_union($codebase, $property_type, $fq_class_name, $fq_class_name, $parent_fq_class_name) : $property_type;
                $class_context->vars_in_scope['$this->' . $property_name] = $fleshed_out_type;
            }
        }
    }
    private function check_property_initialization(Codebase $codebase, Config $config, Class_Like_Storage $storage, Context $class_context, ?Context $global_context = null, ?Method_Analyzer $constructor_analyzer = null): void
    {
        if (!$config->report_issue_in_file('PropertyNotSetInConstructor', $this->get_file_path())) {
            return;
        }
        if (!isset($storage->declaring_method_ids['__construct']) && !$config->report_issue_in_file('MissingConstructor', $this->get_file_path())) {
            return;
        }
        // abstract constructors do not have any code, therefore cannot set any properties either
        if (isset($storage->methods['__construct']) && $storage->methods['__construct']->abstract) {
            return;
        }
        $fq_class_name = $class_context->self ?: $this->fq_class_name;
        $fq_class_name_lc = strtolower($fq_class_name);
        $included_file_path = $this->get_file_path();
        $method_already_analyzed = $codebase->analyzer->is_method_already_analyzed($included_file_path, $fq_class_name_lc . '::__construct', true);
        if ($method_already_analyzed && !$codebase->diff_methods) {
            // this can happen when re-analysing a class that has been include()d inside another
            return;
        }
        /** @var PhpParser\Node\Stmt\Class_ */
        $class = $this->class;
        $classlike_storage_provider = $codebase->classlike_storage_provider;
        $class_storage = $classlike_storage_provider->get($fq_class_name_lc);
        $constructor_appearing_fqcln = $fq_class_name_lc;
        $uninitialized_variables = [];
        $uninitialized_properties = [];
        $uninitialized_typed_properties = [];
        $uninitialized_private_properties = false;
        foreach ($storage->appearing_property_ids as $property_name => $appearing_property_id) {
            $property_class_name = $codebase->properties->get_declaring_class_for_property($appearing_property_id, true);
            if ($property_class_name === null) {
                continue;
            }
            $property_class_storage = $classlike_storage_provider->get($property_class_name);
            $property = $property_class_storage->properties[$property_name];
            $property_is_initialized = isset($property_class_storage->initialized_properties[$property_name]);
            if ($property->is_static) {
                continue;
            }
            if ($property->is_promoted && strtolower($property_class_name) !== $fq_class_name_lc && isset($storage->declaring_method_ids['__construct']) && strtolower($storage->declaring_method_ids['__construct']->fq_class_name) === $fq_class_name_lc) {
                $property_is_initialized = false;
            }
            if ($property->has_default) {
                continue;
            }
            if ($property_is_initialized) {
                continue;
            }
            if ($property->type && $property->type->from_docblock && $property->type->is_nullable()) {
                continue;
            }
            if ($codebase->diff_methods && $method_already_analyzed && $property->location) {
                [$start, $end] = $property->location->get_selection_bounds();
                $existing_issues = $codebase->analyzer->get_existing_issues_for_file($this->get_file_path(), $start, $end, 'PropertyNotSetInConstructor');
                if ($existing_issues) {
                    Issue_Buffer::add_issues([$this->get_file_path() => $existing_issues]);
                    continue;
                }
            }
            if ($property->location) {
                $codebase->analyzer->remove_existing_data_for_file($this->get_file_path(), $property->location->raw_file_start, $property->location->raw_file_end, 'PropertyNotSetInConstructor');
            }
            $codebase->file_reference_provider->add_method_reference_to_missing_class_member($fq_class_name_lc . '::__construct', strtolower($property_class_name) . '::$' . $property_name);
            if ($property->visibility === Class_Like_Analyzer::VISIBILITY_PRIVATE) {
                $uninitialized_private_properties = true;
            }
            $uninitialized_variables[] = '$this->' . $property_name;
            $uninitialized_properties[$property_class_name . '::$' . $property_name] = $property;
            if ($property->type && !$property->hook_get) {
                // Complain about all natively typed properties and all non-mixed docblock typed properties
                if (!$property->type->from_docblock || !$property->type->is_mixed()) {
                    $uninitialized_typed_properties[$property_class_name . '::$' . $property_name] = $property;
                }
            }
        }
        if (!$uninitialized_properties) {
            return;
        }
        if (!$storage->abstract && !$constructor_analyzer && isset($storage->declaring_method_ids['__construct']) && isset($storage->appearing_method_ids['__construct']) && $class->extends) {
            $constructor_declaring_fqcln = $storage->declaring_method_ids['__construct']->fq_class_name;
            $constructor_appearing_fqcln = $storage->appearing_method_ids['__construct']->fq_class_name;
            $constructor_class_storage = $classlike_storage_provider->get($constructor_declaring_fqcln);
            // ignore oldstyle constructors and classes without any declared properties
            if ($constructor_class_storage->user_defined && !$constructor_class_storage->stubbed && isset($constructor_class_storage->methods['__construct'])) {
                $constructor_storage = $constructor_class_storage->methods['__construct'];
                $fake_constructor_params = array_map(static function (Function_Like_Parameter $param): Php_Parser\Node\Param {
                    $fake_param = new Php_Parser\Builder\Param($param->name);
                    if ($param->signature_type) {
                        $fake_param->set_type((string) $param->signature_type);
                    }
                    $node = $fake_param->get_node();
                    $attributes = $param->location ? ['startFilePos' => $param->location->raw_file_start, 'endFilePos' => $param->location->raw_file_end, 'startLine' => $param->location->raw_line_number] : [];
                    $node->set_attributes($attributes);
                    return $node;
                }, $constructor_storage->params);
                $fake_constructor_stmt_args = array_map(static function (Function_Like_Parameter $param): Php_Parser\Node\Arg {
                    $attributes = $param->location ? ['startFilePos' => $param->location->raw_file_start, 'endFilePos' => $param->location->raw_file_end, 'startLine' => $param->location->raw_line_number] : [];
                    return new Virtual_Arg(new Virtual_Variable($param->name, $attributes), false, $param->is_variadic, $attributes);
                }, $constructor_storage->params);
                $fake_constructor_attributes = ['startLine' => $class->extends->get_line(), 'startFilePos' => $class->extends->get_attribute('startFilePos'), 'endFilePos' => $class->extends->get_attribute('endFilePos')];
                $fake_call_attributes = $fake_constructor_attributes + ['comments' => [new Php_Parser\Comment\Doc('/** @psalm-suppress InaccessibleMethod */', $class->extends->get_line(), (int) $class->extends->get_attribute('startFilePos'))]];
                $fake_constructor_stmts = [new Virtual_Expression(new Virtual_Static_Call(new Virtual_Fully_Qualified($constructor_declaring_fqcln), new Virtual_Identifier('__construct', $fake_constructor_attributes), $fake_constructor_stmt_args, $fake_call_attributes), $fake_call_attributes)];
                $fake_stmt = new Virtual_Class_Method(new Virtual_Identifier('__construct'), ['flags' => Php_Parser\Modifiers::PUBLIC, 'params' => $fake_constructor_params, 'stmts' => $fake_constructor_stmts], $fake_constructor_attributes);
                $codebase->analyzer->disable_mixed_counts();
                $was_collecting_initializations = $class_context->collect_initializations;
                $class_context->collect_initializations = true;
                $class_context->collect_nonprivate_initializations = !$uninitialized_private_properties;
                $constructor_analyzer = $this->analyze_class_method($fake_stmt, $storage, $this, $class_context, $global_context, true);
                $class_context->collect_initializations = $was_collecting_initializations;
                $codebase->analyzer->enable_mixed_counts();
            }
        }
        if ($constructor_analyzer) {
            $method_context = clone $class_context;
            $method_context->collect_initializations = true;
            $method_context->collect_nonprivate_initializations = !$uninitialized_private_properties;
            $method_context->self = $fq_class_name;
            $this_atomic_object_type = new T_Named_Object($fq_class_name, !$storage->final);
            $method_context->vars_in_scope['$this'] = new Union([$this_atomic_object_type]);
            $method_context->vars_possibly_in_scope['$this'] = true;
            $method_context->calling_method_id = strtolower($fq_class_name) . '::__construct';
            $constructor_analyzer->analyze($method_context, new Node_Data_Provider(), $global_context, true);
            foreach ($uninitialized_properties as $property_id => $property_storage) {
                [, $property_name] = explode('::$', $property_id);
                if (!isset($method_context->vars_in_scope['$this->' . $property_name])) {
                    $end_type = new Union([new T_Void()], ['initialized' => false]);
                } else {
                    $end_type = $method_context->vars_in_scope['$this->' . $property_name];
                }
                $constructor_class_property_storage = $property_storage;
                $error_location = $property_storage->location;
                if ($storage->declaring_property_ids[$property_name] !== $fq_class_name) {
                    $error_location = $storage->location ?: $storage->stmt_location;
                }
                if ($fq_class_name_lc !== $constructor_appearing_fqcln && $property_storage->visibility === Class_Like_Analyzer::VISIBILITY_PRIVATE) {
                    $a_class_storage = $classlike_storage_provider->get($end_type->initialized_class ?: $constructor_appearing_fqcln);
                    if (!isset($a_class_storage->declaring_property_ids[$property_name])) {
                        $constructor_class_property_storage = null;
                    } else {
                        $declaring_property_class = $a_class_storage->declaring_property_ids[$property_name];
                        $constructor_class_property_storage = $classlike_storage_provider->get($declaring_property_class)->properties[$property_name];
                    }
                }
                if ($property_storage->location && $error_location && (!$end_type->initialized || $property_storage !== $constructor_class_property_storage)) {
                    if ($property_storage->type) {
                        $expected_visibility = $uninitialized_private_properties ? 'private or final ' : '';
                        Issue_Buffer::maybe_add(new Property_Not_Set_In_Constructor('Property ' . $class_storage->name . '::$' . $property_name . ' is not defined in constructor of ' . $this->fq_class_name . ' or in any ' . $expected_visibility . 'methods called in the constructor', $error_location, $property_id), $storage->suppressed_issues + $this->get_suppressed_issues());
                    } elseif (!$property_storage->has_default) {
                        if (isset($this->inferred_property_types[$property_name])) {
                            $this->inferred_property_types[$property_name] = $this->inferred_property_types[$property_name]->get_builder()->add_type(new T_Null())->set_from_docblock(true)->freeze();
                        }
                    }
                }
            }
            $codebase->analyzer->set_analyzed_method($included_file_path, $fq_class_name_lc . '::__construct', true);
            return;
        }
        if (!$storage->abstract && $uninitialized_typed_properties) {
            foreach ($uninitialized_typed_properties as $id => $uninitialized_property) {
                if ($uninitialized_property->location) {
                    Issue_Buffer::maybe_add(new Missing_Constructor($class_storage->name . ' has an uninitialized property ' . $id . ', but no constructor', $uninitialized_property->location, $class_storage->name . '::' . $uninitialized_variables[0]), $storage->suppressed_issues + $this->get_suppressed_issues());
                }
            }
        }
    }
    /**
     * @return false|null
     */
    private function analyze_trait_use(Aliases $aliases, Php_Parser\Node\Stmt\Trait_Use $stmt, Project_Analyzer $project_analyzer, Class_Like_Storage $storage, Context $class_context, ?Context $global_context = null, ?Method_Analyzer &$constructor_analyzer = null, ?Trait_Analyzer $previous_trait_analyzer = null): ?bool
    {
        $codebase = $this->get_codebase();
        $previous_context_include_location = $class_context->include_location;
        foreach ($stmt->traits as $trait_name) {
            $trait_location = new Code_Location($this, $trait_name, null, true);
            $class_context->include_location = new Code_Location($this, $trait_name, null, true);
            $fq_trait_name = self::get_fqcln_from_name_object($trait_name, $aliases);
            if (!$codebase->classlikes->has_fully_qualified_trait_name($fq_trait_name, $trait_location)) {
                Issue_Buffer::maybe_add(new Undefined_Trait('Trait ' . $fq_trait_name . ' does not exist', new Code_Location($previous_trait_analyzer ?? $this, $trait_name)), $storage->suppressed_issues + $this->get_suppressed_issues());
                return false;
            }
            if (!$codebase->trait_has_correct_casing($fq_trait_name)) {
                if (Issue_Buffer::accepts(new Undefined_Trait('Trait ' . $fq_trait_name . ' has wrong casing', new Code_Location($previous_trait_analyzer ?? $this, $trait_name)), $storage->suppressed_issues + $this->get_suppressed_issues())) {
                    return false;
                }
                continue;
            }
            $fq_trait_name_resolved = $codebase->classlikes->get_un_aliased_name($fq_trait_name);
            $trait_storage = $codebase->classlike_storage_provider->get($fq_trait_name_resolved);
            if ($trait_storage->deprecated) {
                Issue_Buffer::maybe_add(new Deprecated_Trait('Trait ' . $fq_trait_name . ' is deprecated', new Code_Location($previous_trait_analyzer ?? $this, $trait_name)), $storage->suppressed_issues + $this->get_suppressed_issues());
            }
            if ($trait_storage->extension_requirement !== null) {
                $extension_requirement = $codebase->classlikes->get_un_aliased_name($trait_storage->extension_requirement);
                $extension_requirement_met = in_array($extension_requirement, $storage->parent_classes);
                if (!$extension_requirement_met) {
                    Issue_Buffer::maybe_add(new Extension_Requirement_Violation($fq_trait_name . ' requires using class to extend ' . $extension_requirement . ', but ' . $storage->name . ' does not', new Code_Location($previous_trait_analyzer ?? $this, $trait_name)), $storage->suppressed_issues + $this->get_suppressed_issues());
                }
            }
            foreach ($trait_storage->implementation_requirements as $implementation_requirement) {
                $implementation_requirement = $codebase->classlikes->get_un_aliased_name($implementation_requirement);
                $implementation_requirement_met = in_array($implementation_requirement, $storage->class_implements);
                if (!$implementation_requirement_met) {
                    Issue_Buffer::maybe_add(new Implementation_Requirement_Violation($fq_trait_name . ' requires using class to implement ' . $implementation_requirement . ', but ' . $storage->name . ' does not', new Code_Location($previous_trait_analyzer ?? $this, $trait_name)), $storage->suppressed_issues + $this->get_suppressed_issues());
                }
            }
            if ($storage->mutation_free && !$trait_storage->mutation_free) {
                Issue_Buffer::maybe_add(new Mutable_Dependency($storage->name . ' is marked @psalm-immutable but ' . $fq_trait_name . ' is not', new Code_Location($previous_trait_analyzer ?? $this, $trait_name)), $storage->suppressed_issues + $this->get_suppressed_issues());
            }
            $trait_file_analyzer = $project_analyzer->get_file_analyzer_for_class_like($fq_trait_name_resolved);
            $trait_node = $codebase->classlikes->get_trait_node($fq_trait_name_resolved);
            $trait_aliases = $trait_storage->aliases;
            if ($trait_aliases === null) {
                continue;
            }
            $trait_analyzer = new Trait_Analyzer($trait_node, $trait_file_analyzer, $fq_trait_name_resolved, $trait_aliases);
            foreach ($trait_node->stmts as $trait_stmt) {
                if ($trait_stmt instanceof Php_Parser\Node\Stmt\Class_Method) {
                    $trait_method_analyzer = $this->analyze_class_method($trait_stmt, $storage, $trait_analyzer, $class_context, $global_context);
                    if ($trait_stmt->name->name === '__construct') {
                        $constructor_analyzer = $trait_method_analyzer;
                    }
                } elseif ($trait_stmt instanceof Php_Parser\Node\Stmt\Trait_Use) {
                    if ($this->analyze_trait_use($trait_aliases, $trait_stmt, $project_analyzer, $storage, $class_context, $global_context, $constructor_analyzer, $trait_analyzer) === false) {
                        return false;
                    }
                }
            }
            $trait_file_analyzer->clear_source_before_destruction();
        }
        $class_context->include_location = $previous_context_include_location;
        return null;
    }
    private function analyze_property(Source_Analyzer $source, Php_Parser\Node\Stmt\Property $stmt, Context $context): void
    {
        $fq_class_name = $source->get_fqcln();
        $property_name = $stmt->props[0]->name->name;
        $codebase = $this->get_codebase();
        $property_id = $fq_class_name . '::$' . $property_name;
        $declaring_property_class = $codebase->properties->get_declaring_class_for_property($property_id, true);
        if (!$declaring_property_class) {
            return;
        }
        $fq_class_name = $declaring_property_class;
        // gets inherited property type
        $class_property_type = $codebase->properties->get_property_type($property_id, false, $source, $context);
        $class_storage = $codebase->classlike_storage_provider->get($fq_class_name);
        $property_storage = $class_storage->properties[$property_name];
        Attributes_Analyzer::analyze($source, $context, $property_storage, $stmt->attr_groups, Attribute::TARGET_PROPERTY, $property_storage->suppressed_issues + $this->get_suppressed_issues());
        if ($class_property_type && ($property_storage->type_location || !$codebase->alter_code)) {
            return;
        }
        $message = 'Property ' . $property_id . ' does not have a declared type';
        $suggested_type = $property_storage->suggested_type;
        if (isset($this->inferred_property_types[$property_name])) {
            $suggested_type = Type::combine_union_types($suggested_type, $this->inferred_property_types[$property_name] ?? null, $codebase);
        }
        if ($suggested_type && !$property_storage->has_default && $property_storage->is_static) {
            $suggested_type = $suggested_type->get_builder()->add_type(new T_Null())->freeze();
        }
        if ($suggested_type && !$suggested_type->is_null()) {
            $message .= ' - consider ' . str_replace(['<array-key, mixed>', '<never, never>'], '', $suggested_type->get_id(false));
        }
        $project_analyzer = Project_Analyzer::get_instance();
        if ($codebase->alter_code && $source === $this && isset($project_analyzer->get_issues_to_fix()['MissingPropertyType']) && !in_array('MissingPropertyType', $this->get_suppressed_issues()) && $suggested_type) {
            if ($suggested_type->has_mixed() || $suggested_type->is_null()) {
                return;
            }
            self::add_or_update_property_type($project_analyzer, $stmt, $suggested_type, $this, $suggested_type->from_docblock);
            return;
        }
        Issue_Buffer::maybe_add(new Missing_Property_Type($message, new Code_Location($source, $stmt->props[0]->name), $property_id), $this->source->get_suppressed_issues() + $property_storage->suppressed_issues);
    }
    private static function add_or_update_property_type(Project_Analyzer $project_analyzer, Php_Parser\Node\Stmt\Property $property, Union $inferred_type, Statements_Source $source, bool $docblock_only = false): void
    {
        $manipulator = Property_Docblock_Manipulator::get_for_property($project_analyzer, $source->get_file_path(), $property);
        $codebase = $project_analyzer->get_codebase();
        $allow_native_type = !$docblock_only && $codebase->analysis_php_version_id >= 70400 && $codebase->allow_backwards_incompatible_changes && $inferred_type->get_callable_types() === [];
        $manipulator->set_type($allow_native_type ? (string) $inferred_type->to_php_string($source->get_namespace(), $source->get_aliased_classes_flipped(), $source->get_fqcln(), $codebase->analysis_php_version_id) : null, $inferred_type->to_namespaced_string($source->get_namespace(), $source->get_aliased_classes_flipped(), $source->get_fqcln(), false), $inferred_type->to_namespaced_string($source->get_namespace(), $source->get_aliased_classes_flipped(), $source->get_fqcln(), true), $inferred_type->can_be_fully_expressed_in_php($codebase->analysis_php_version_id));
    }
    private function analyze_class_method(Php_Parser\Node\Stmt\Class_Method $stmt, Class_Like_Storage $class_storage, Source_Analyzer $source, Context $class_context, ?Context $global_context = null, bool $is_fake = false): ?Method_Analyzer
    {
        $config = Config::get_instance();
        if ($stmt->stmts === null && !$stmt->is_abstract()) {
            Issue_Buffer::maybe_add(new ParseError('Non-abstract class method must have statements', new Code_Location($this, $stmt)));
            return null;
        }
        try {
            $method_analyzer = new Method_Analyzer($stmt, $source);
        } catch (UnexpectedValueException $e) {
            Issue_Buffer::maybe_add(new ParseError('Problem loading method: ' . $e->get_message(), new Code_Location($this, $stmt)));
            return null;
        }
        $actual_method_id = $method_analyzer->get_method_id();
        $project_analyzer = $source->get_project_analyzer();
        $codebase = $source->get_codebase();
        $analyzed_method_id = $actual_method_id;
        $included_file_path = $source->get_file_path();
        if ($class_context->self && strtolower($class_context->self) !== strtolower((string) $source->get_fqcln())) {
            $analyzed_method_id = $method_analyzer->get_method_id($class_context->self);
            $declaring_method_id = $codebase->methods->get_declaring_method_id($analyzed_method_id);
            if ((string) $actual_method_id !== (string) $declaring_method_id) {
                // the method is an abstract trait method
                $declaring_method_storage = $method_analyzer->get_function_like_storage();
                if (!$declaring_method_storage instanceof Method_Storage) {
                    throw new LogicException('This should never happen');
                }
                if ($declaring_method_id && $declaring_method_storage->abstract) {
                    $implementer_method_storage = $codebase->methods->get_storage($declaring_method_id);
                    $declaring_storage = $codebase->classlike_storage_provider->get($actual_method_id->fq_class_name);
                    Method_Comparator::compare($codebase, null, $class_storage, $declaring_storage, $implementer_method_storage, $declaring_method_storage, $this->fq_class_name, $implementer_method_storage->visibility, $implementer_method_storage->stmt_location ?? new Code_Location($source, $stmt), $implementer_method_storage->suppressed_issues, false);
                }
                return null;
            }
        }
        $trait_safe_method_id = strtolower((string) $analyzed_method_id);
        $actual_method_id_str = strtolower((string) $actual_method_id);
        if ($actual_method_id_str !== $trait_safe_method_id) {
            $trait_safe_method_id .= '&' . $actual_method_id_str;
        }
        $method_already_analyzed = $codebase->analyzer->is_method_already_analyzed($included_file_path, $trait_safe_method_id);
        $start = (int) $stmt->get_attribute('startFilePos');
        $end = (int) $stmt->get_attribute('endFilePos');
        $comments = $stmt->get_comments();
        if ($comments) {
            $start = $comments[0]->get_start_file_pos();
        }
        if ($codebase->diff_methods && $method_already_analyzed && !$class_context->collect_initializations && !$class_context->collect_mutations && !$is_fake) {
            $project_analyzer->progress->debug('Skipping analysis of pre-analyzed method ' . $analyzed_method_id . "\n");
            $existing_issues = $codebase->analyzer->get_existing_issues_for_file($source->get_file_path(), $start, $end);
            Issue_Buffer::add_issues([$source->get_file_path() => $existing_issues]);
            return $method_analyzer;
        }
        $codebase->analyzer->remove_existing_data_for_file($source->get_file_path(), $start, $end);
        $method_context = clone $class_context;
        foreach ($method_context->vars_in_scope as $context_var_id => $context_type) {
            if ($context_type->from_property && $stmt->name->name !== '__construct') {
                $method_context->vars_in_scope[$context_var_id] = $method_context->vars_in_scope[$context_var_id]->set_properties(['initialized' => true]);
            }
        }
        $method_context->collect_exceptions = $config->check_for_throws_docblock;
        $type_provider = new Node_Data_Provider();
        $method_analyzer->analyze($method_context, $type_provider, $global_context ? clone $global_context : null);
        if ($stmt->name->name !== '__construct' && $config->report_issue_in_file('InvalidReturnType', $source->get_file_path()) && $class_context->self) {
            self::analyze_class_method_return_type($stmt, $method_analyzer, $source, $type_provider, $codebase, $class_storage, $class_context->self, $analyzed_method_id, $actual_method_id, $method_context->has_returned);
        }
        if (!$method_already_analyzed && !$class_context->collect_initializations && !$class_context->collect_mutations && !$is_fake) {
            $codebase->analyzer->set_analyzed_method($included_file_path, $trait_safe_method_id);
        }
        return $method_analyzer;
    }
    private static function get_this_object_type(Class_Like_Storage $class_storage, string $original_fq_classlike_name): T_Named_Object
    {
        if ($class_storage->template_types) {
            $template_params = [];
            foreach ($class_storage->template_types as $param_name => $template_map) {
                $key = array_keys($template_map)[0];
                $template_params[] = new Union([new T_Template_Param($param_name, reset($template_map), $key)]);
            }
            return new T_Generic_Object($original_fq_classlike_name, $template_params);
        }
        return new T_Named_Object($original_fq_classlike_name);
    }
    public static function analyze_class_method_return_type(Php_Parser\Node\Stmt\Class_Method $stmt, Method_Analyzer $method_analyzer, Source_Analyzer $source, Node_Data_Provider $type_provider, Codebase $codebase, Class_Like_Storage $class_storage, string $fq_classlike_name, Method_Identifier $analyzed_method_id, Method_Identifier $actual_method_id, bool $did_explicitly_return): void
    {
        $secondary_return_type_location = null;
        $actual_method_storage = $codebase->methods->get_storage($actual_method_id);
        $return_type_location = $codebase->methods->get_method_return_type_location($actual_method_id, $secondary_return_type_location);
        $original_fq_classlike_name = $fq_classlike_name;
        $return_type = $codebase->methods->get_method_return_type($analyzed_method_id, $fq_classlike_name, $method_analyzer);
        if ($return_type && $class_storage->template_extended_params) {
            $declaring_method_id = $codebase->methods->get_declaring_method_id($analyzed_method_id);
            if ($declaring_method_id) {
                $declaring_class_name = $declaring_method_id->fq_class_name;
                $class_storage = $codebase->classlike_storage_provider->get($declaring_class_name);
            }
            $this_object_type = self::get_this_object_type($class_storage, $original_fq_classlike_name);
            $class_template_params = Class_Template_Param_Collector::collect($codebase, $class_storage, $codebase->classlike_storage_provider->get($original_fq_classlike_name), strtolower($stmt->name->name), $this_object_type) ?: [];
            $template_result = new Template_Result($class_template_params ?: [], []);
            $return_type = Template_Standin_Type_Replacer::replace($return_type, $template_result, $codebase, null, null, null, $original_fq_classlike_name);
        }
        $overridden_method_ids = $class_storage->overridden_method_ids[strtolower($stmt->name->name)] ?? [];
        if (!$return_type && !$class_storage->is_interface && $overridden_method_ids) {
            foreach ($overridden_method_ids as $interface_method_id) {
                $interface_class = $interface_method_id->fq_class_name;
                if (!$codebase->classlikes->interface_exists($interface_class)) {
                    continue;
                }
                $interface_return_type = $codebase->methods->get_method_return_type($interface_method_id, $interface_class);
                $interface_return_type_location = $codebase->methods->get_method_return_type_location($interface_method_id);
                Return_Type_Analyzer::verify_return_type($stmt, $stmt->get_stmts() ?: [], $source, $type_provider, $method_analyzer, $interface_return_type, $interface_class, $original_fq_classlike_name, $interface_return_type_location, [$analyzed_method_id->__toString()], $did_explicitly_return);
            }
        }
        $overridden_method_ids = array_map(static fn(\Psalm\Internal\Method_Identifier $method_id): string => $method_id->__toString(), $overridden_method_ids);
        if ($actual_method_storage->overridden_downstream) {
            $overridden_method_ids['overridden::downstream'] = 'overridden::downstream';
        }
        Return_Type_Analyzer::verify_return_type($stmt, $stmt->get_stmts() ?: [], $source, $type_provider, $method_analyzer, $return_type, $fq_classlike_name, $original_fq_classlike_name, $return_type_location, $overridden_method_ids, $did_explicitly_return);
    }
    /**
     * @param PhpParser\Node\Stmt\Class_|PhpParser\Node\Stmt\Enum_ $class
     */
    private function check_implemented_interfaces(Context $class_context, Php_Parser\Node\Stmt $class, Codebase $codebase, string $fq_class_name, Class_Like_Storage $storage): bool
    {
        $classlike_storage_provider = $codebase->classlike_storage_provider;
        foreach ($class->implements as $interface_name) {
            $fq_interface_name = self::get_fqcln_from_name_object($interface_name, $this->source->get_aliases());
            $fq_interface_name_lc = strtolower($fq_interface_name);
            $codebase->analyzer->add_node_reference($this->get_file_path(), $interface_name, $codebase->classlikes->interface_exists($fq_interface_name) ? $fq_interface_name : '*' . ($interface_name instanceof Php_Parser\Node\Name\Fully_Qualified ? '\\' : $this->get_namespace() . '-') . $interface_name->to_string());
            $interface_location = new Code_Location($this, $interface_name);
            if (self::check_fully_qualified_class_like_name($this, $fq_interface_name, $interface_location, null, null, $this->get_suppressed_issues()) === false) {
                return false;
            }
            if ($codebase->store_node_types && $fq_class_name) {
                $bounds = $interface_location->get_selection_bounds();
                $codebase->analyzer->add_offset_reference($this->get_file_path(), $bounds[0], $bounds[1], $fq_interface_name);
            }
            $codebase->classlikes->handle_class_like_reference_in_migration($codebase, $this, $interface_name, $fq_interface_name, null);
            try {
                $interface_storage = $classlike_storage_provider->get($fq_interface_name);
            } catch (InvalidArgumentException) {
                return false;
            }
            $code_location = new Code_Location($this, $interface_name, $class_context->include_location, true);
            if (!$interface_storage->is_interface) {
                Issue_Buffer::maybe_add(new Undefined_Interface($fq_interface_name . ' is not an interface', $code_location, $fq_interface_name), $storage->suppressed_issues + $this->get_suppressed_issues());
            }
            $this->check_template_params($codebase, $storage, $interface_storage, $code_location, $storage->template_type_implements_count[$fq_interface_name_lc] ?? 0);
        }
        foreach ($storage->class_implements as $fq_interface_name_lc => $fq_interface_name) {
            try {
                $interface_storage = $classlike_storage_provider->get($fq_interface_name_lc);
            } catch (InvalidArgumentException) {
                return false;
            }
            $code_location = new Code_Location($this, $class->name ?? $class, $class_context->include_location, true);
            if ($fq_interface_name_lc === 'traversable' && !$storage->abstract && !isset($storage->class_implements['iteratoraggregate']) && !isset($storage->class_implements['iterator']) && !isset($storage->parent_classes['pdostatement']) && !isset($storage->parent_classes['ds\collection']) && !isset($storage->parent_classes['domnodelist']) && !isset($storage->parent_classes['dateperiod'])) {
                Issue_Buffer::maybe_add(new Invalid_Traversable_Implementation('Traversable should be implemented by implementing IteratorAggregate or Iterator', $code_location, $fq_class_name));
            }
            if ($fq_interface_name_lc === 'throwable' && $codebase->analysis_php_version_id >= 70000 && !$storage->abstract && !isset($storage->parent_classes['exception']) && !isset($storage->parent_classes['error'])) {
                Issue_Buffer::maybe_add(new Invalid_Interface_Implementation('Classes implementing Throwable should extend Exception or Error', $code_location, $fq_class_name));
            }
            if (($fq_interface_name_lc === 'unitenum' || $fq_interface_name_lc === 'backedenum') && !$storage->is_enum && $codebase->analysis_php_version_id >= 80100) {
                Issue_Buffer::maybe_add(new Invalid_Interface_Implementation($fq_interface_name . ' cannot be implemented by classes', $code_location, $fq_class_name));
            }
            if ($interface_storage->deprecated) {
                Issue_Buffer::maybe_add(new Deprecated_Interface($fq_interface_name . ' is marked deprecated', $code_location, $fq_interface_name), $storage->suppressed_issues + $this->get_suppressed_issues());
            }
            if ($interface_storage->external_mutation_free && !$storage->external_mutation_free) {
                Issue_Buffer::maybe_add(new Missing_Immutable_Annotation($fq_interface_name . ' is marked @psalm-immutable, but ' . $fq_class_name . ' is not marked @psalm-immutable', $code_location), $storage->suppressed_issues + $this->get_suppressed_issues());
            }
            foreach ($interface_storage->methods as $interface_method_name_lc => $interface_method_storage) {
                if ($interface_method_storage->visibility === self::VISIBILITY_PUBLIC) {
                    $implementer_declaring_method_id = $codebase->methods->get_declaring_method_id(new Method_Identifier($this->fq_class_name, $interface_method_name_lc));
                    $implementer_method_storage = null;
                    $implementer_classlike_storage = null;
                    if ($implementer_declaring_method_id) {
                        $implementer_fq_class_name = $implementer_declaring_method_id->fq_class_name;
                        $implementer_method_storage = $codebase->methods->get_storage($implementer_declaring_method_id);
                        $implementer_classlike_storage = $classlike_storage_provider->get($implementer_fq_class_name);
                    }
                    if ($storage->is_enum) {
                        if ($interface_method_name_lc === 'cases') {
                            continue;
                        }
                        if ($storage->enum_type && in_array($interface_method_name_lc, ['from', 'tryfrom'], true)) {
                            continue;
                        }
                    }
                    if (!$implementer_method_storage) {
                        Issue_Buffer::maybe_add(new Unimplemented_Interface_Method('Method ' . $interface_method_name_lc . ' is not defined on class ' . $storage->name, $code_location), $storage->suppressed_issues + $this->get_suppressed_issues());
                        return true;
                    }
                    $implementer_appearing_method_id = $codebase->methods->get_appearing_method_id(new Method_Identifier($this->fq_class_name, $interface_method_name_lc));
                    $implementer_visibility = $implementer_method_storage->visibility;
                    if ($implementer_appearing_method_id && $implementer_appearing_method_id !== $implementer_declaring_method_id) {
                        $appearing_fq_class_name = $implementer_appearing_method_id->fq_class_name;
                        $appearing_method_name = $implementer_appearing_method_id->method_name;
                        $appearing_class_storage = $classlike_storage_provider->get($appearing_fq_class_name);
                        if (isset($appearing_class_storage->trait_visibility_map[$appearing_method_name])) {
                            $implementer_visibility = $appearing_class_storage->trait_visibility_map[$appearing_method_name];
                        }
                    }
                    if ($implementer_visibility !== self::VISIBILITY_PUBLIC) {
                        Issue_Buffer::maybe_add(new Inaccessible_Method('Interface-defined method ' . $implementer_method_storage->cased_name . ' must be public in ' . $storage->name, $code_location), $storage->suppressed_issues + $this->get_suppressed_issues());
                        return true;
                    }
                    if ($interface_method_storage->is_static && !$implementer_method_storage->is_static) {
                        Issue_Buffer::maybe_add(new Method_Signature_Mismatch('Method ' . $implementer_method_storage->cased_name . ' should be static like ' . $storage->name . '::' . $interface_method_storage->cased_name, $code_location), $implementer_method_storage->suppressed_issues);
                        return true;
                    }
                    if ($storage->abstract && $implementer_method_storage === $interface_method_storage) {
                        continue;
                    }
                    Method_Comparator::compare($codebase, null, $implementer_classlike_storage ?? $storage, $interface_storage, $implementer_method_storage, $interface_method_storage, $this->fq_class_name, $implementer_visibility, $code_location, $implementer_method_storage->suppressed_issues, false);
                }
            }
        }
        return true;
    }
    private function check_parent_class(Class_ $class, Php_Parser\Node\Name $extended_class, string $fq_class_name, string $parent_fq_class_name, Class_Like_Storage $storage, Codebase $codebase, ?Context $class_context): void
    {
        $classlike_storage_provider = $codebase->classlike_storage_provider;
        if (!$parent_fq_class_name) {
            throw new UnexpectedValueException('Parent class should be filled in for ' . $fq_class_name);
        }
        $parent_reference_location = new Code_Location($this, $extended_class);
        if (self::check_fully_qualified_class_like_name($this->get_source(), $parent_fq_class_name, $parent_reference_location, null, null, $storage->suppressed_issues + $this->get_suppressed_issues()) === false) {
            return;
        }
        if ($codebase->alter_code && $codebase->classes_to_move) {
            $codebase->classlikes->handle_class_like_reference_in_migration($codebase, $this, $extended_class, $parent_fq_class_name, null);
        }
        try {
            $parent_class_storage = $classlike_storage_provider->get($parent_fq_class_name);
            $code_location = new Code_Location($this, $extended_class, $class_context->include_location ?? null, true);
            if ($parent_class_storage->is_trait || $parent_class_storage->is_interface) {
                Issue_Buffer::maybe_add(new Undefined_Class($parent_fq_class_name . ' is not a class', $code_location, $parent_fq_class_name . ' as class'), $storage->suppressed_issues + $this->get_suppressed_issues());
            }
            if ($parent_class_storage->final) {
                Issue_Buffer::maybe_add(new Invalid_Extend_Class('Class ' . $fq_class_name . ' may not inherit from final class ' . $parent_fq_class_name, $code_location, $fq_class_name), $storage->suppressed_issues + $this->get_suppressed_issues());
            }
            if ($parent_class_storage->readonly && !$storage->readonly) {
                Issue_Buffer::maybe_add(new Invalid_Extend_Class('Non-readonly class ' . $fq_class_name . ' may not inherit from ' . 'readonly class ' . $parent_fq_class_name, $code_location, $fq_class_name), $storage->suppressed_issues + $this->get_suppressed_issues());
            }
            if ($parent_class_storage->deprecated) {
                Issue_Buffer::maybe_add(new Deprecated_Class($parent_fq_class_name . ' is marked deprecated', $code_location, $parent_fq_class_name), $storage->suppressed_issues + $this->get_suppressed_issues());
            }
            if (!Namespace_Analyzer::is_within_any($fq_class_name, $parent_class_storage->internal)) {
                Issue_Buffer::maybe_add(new Internal_Class($parent_fq_class_name . ' is internal to ' . Internal_Class::list_to_phrase($parent_class_storage->internal) . ' but called from ' . $fq_class_name, $code_location, $parent_fq_class_name), $storage->suppressed_issues + $this->get_suppressed_issues());
            }
            if ($parent_class_storage->external_mutation_free && !$storage->external_mutation_free) {
                Issue_Buffer::maybe_add(new Missing_Immutable_Annotation($parent_fq_class_name . ' is marked @psalm-immutable, but ' . $fq_class_name . ' is not marked @psalm-immutable', $code_location), $storage->suppressed_issues + $this->get_suppressed_issues());
            }
            if ($storage->mutation_free && !$parent_class_storage->mutation_free) {
                Issue_Buffer::maybe_add(new Mutable_Dependency($fq_class_name . ' is marked @psalm-immutable but ' . $parent_fq_class_name . ' is not', $code_location), $storage->suppressed_issues + $this->get_suppressed_issues());
            }
            if ($codebase->store_node_types) {
                $codebase->analyzer->add_node_reference($this->get_file_path(), $extended_class, $codebase->classlikes->class_exists($parent_fq_class_name) ? $parent_fq_class_name : '*' . ($extended_class instanceof Php_Parser\Node\Name\Fully_Qualified ? '\\' : $this->get_namespace() . '-') . $extended_class->to_string());
            }
            $code_location = new Code_Location($this, $class->name ?: $class, $class_context->include_location ?? null, true);
            $this->check_template_params($codebase, $storage, $parent_class_storage, $code_location, $storage->template_type_extends_count[$parent_fq_class_name] ?? 0);
        } catch (InvalidArgumentException) {
            // do nothing
        }
    }
    private function check_enum(): void
    {
        $storage = $this->storage;
        $seen_values = [];
        foreach ($storage->enum_cases as $case_storage) {
            $case_value = $case_storage->get_value($this->get_codebase()->classlikes);
            if ($case_value !== null && $storage->enum_type === null) {
                Issue_Buffer::maybe_add(new Invalid_Enum_Case_Value('Case of a non-backed enum should not have a value', $case_storage->stmt_location, $storage->name));
            } elseif ($case_value === null && $storage->enum_type !== null) {
                Issue_Buffer::maybe_add(new Invalid_Enum_Case_Value('Case of a backed enum should have a value', $case_storage->stmt_location, $storage->name));
            } elseif ($case_value !== null) {
                if ($case_value instanceof T_Literal_Int && $storage->enum_type === 'string' || $case_value instanceof T_Literal_String && $storage->enum_type === 'int') {
                    Issue_Buffer::maybe_add(new Invalid_Enum_Case_Value('Enum case value type should be ' . $storage->enum_type, $case_storage->stmt_location, $storage->name));
                }
            }
            if ($case_value !== null) {
                if (in_array($case_value->value, $seen_values, true)) {
                    Issue_Buffer::maybe_add(new Duplicate_Enum_Case_Value('Enum case values should be unique', $case_storage->stmt_location, $storage->name));
                } else {
                    $seen_values[] = $case_value->value;
                }
            }
        }
    }
}