<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer;

use Attribute;
use InvalidArgumentException;
use LogicException;
use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\File_Manipulation;
use Psalm\Internal\Analyzer\Statements\Expression\Class_Const_Analyzer;
use Psalm\Internal\File_Manipulation\File_Manipulation_Buffer;
use Psalm\Internal\Provider\Node_Data_Provider;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Issue\Inheritor_Violation;
use Psalm\Issue\ParseError;
use Psalm\Issue\Undefined_Interface;
use Psalm\Issue_Buffer;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Union;
use UnexpectedValueException;
use function strtolower;
/**
 * @internal
 */
final class Interface_Analyzer extends Class_Like_Analyzer
{
    public function __construct(Php_Parser\Node\Stmt\Interface_ $interface, Source_Analyzer $source, string $fq_interface_name)
    {
        parent::__construct($interface, $source, $fq_interface_name);
    }
    public function analyze(): void
    {
        if (!$this->class instanceof Php_Parser\Node\Stmt\Interface_) {
            throw new LogicException('Something went badly wrong');
        }
        $project_analyzer = $this->file_analyzer->project_analyzer;
        $codebase = $project_analyzer->get_codebase();
        $config = $project_analyzer->get_config();
        $fq_interface_name = $this->get_fqcln();
        if (!$fq_interface_name) {
            throw new UnexpectedValueException('bad');
        }
        $class_storage = $codebase->classlike_storage_provider->get($fq_interface_name);
        foreach ($this->class->extends as $extended_interface) {
            $extended_interface_name = self::get_fqcln_from_name_object($extended_interface, $this->get_aliases());
            $parent_reference_location = new Code_Location($this, $extended_interface);
            if (!$codebase->class_or_interface_exists($extended_interface_name, $parent_reference_location)) {
                // we should not normally get here
                return;
            }
            try {
                $extended_interface_storage = $codebase->classlike_storage_provider->get($extended_interface_name);
            } catch (InvalidArgumentException) {
                continue;
            }
            $code_location = new Code_Location($this, $extended_interface);
            if (!$extended_interface_storage->is_interface) {
                Issue_Buffer::maybe_add(new Undefined_Interface($extended_interface_name . ' is not an interface', $code_location, $extended_interface_name), $this->get_suppressed_issues());
            }
            if ($codebase->store_node_types && $extended_interface_name) {
                $bounds = $parent_reference_location->get_selection_bounds();
                $codebase->analyzer->add_offset_reference($this->get_file_path(), $bounds[0], $bounds[1], $extended_interface_name);
            }
            $this->check_template_params($codebase, $class_storage, $extended_interface_storage, $code_location, $class_storage->template_type_extends_count[$extended_interface_name] ?? 0);
        }
        $class_union = new Union([new T_Named_Object($fq_interface_name)]);
        foreach ($class_storage->direct_interface_parents as $parent_interface) {
            $parent_storage = $codebase->classlikes->get_storage_for($parent_interface);
            if ($parent_storage && $parent_storage->inheritors) {
                if (!Union_Type_Comparator::is_contained_by($codebase, $class_union, $parent_storage->inheritors)) {
                    Issue_Buffer::maybe_add(new Inheritor_Violation('Interface ' . $fq_interface_name . '
                             is not an allowed inheritor of parent interface ' . $parent_interface, new Code_Location($this, $this->class)), $this->get_suppressed_issues());
                }
            }
        }
        $fq_interface_name = $this->get_fqcln();
        if (!$fq_interface_name) {
            throw new UnexpectedValueException('bad');
        }
        $class_storage = $codebase->classlike_storage_provider->get($fq_interface_name);
        $interface_context = new Context($this->get_fqcln());
        Attributes_Analyzer::analyze($this, $interface_context, $class_storage, $this->class->attr_groups, Attribute::TARGET_CLASS, $class_storage->suppressed_issues + $this->get_suppressed_issues());
        foreach ($class_storage->docblock_issues as $docblock_issue) {
            Issue_Buffer::maybe_add($docblock_issue);
        }
        $member_stmts = [];
        foreach ($this->class->stmts as $stmt) {
            if ($stmt instanceof Php_Parser\Node\Stmt\Class_Method) {
                $method_analyzer = new Method_Analyzer($stmt, $this);
                $type_provider = new Node_Data_Provider();
                $method_analyzer->analyze($interface_context, $type_provider);
                $actual_method_id = $method_analyzer->get_method_id();
                if ($stmt->name->name !== '__construct' && $stmt->name->name !== '__destruct' && $config->report_issue_in_file('InvalidReturnType', $this->get_file_path())) {
                    Class_Analyzer::analyze_class_method_return_type($stmt, $method_analyzer, $this, $type_provider, $codebase, $class_storage, $fq_interface_name, $actual_method_id, $actual_method_id, false);
                }
            } elseif ($stmt instanceof Php_Parser\Node\Stmt\Property) {
                // PHP 8.4+ allows interface properties with hooks
                if ($codebase->analysis_php_version_id >= 80400 && !empty($stmt->hooks)) {
                    continue;
                }
                Issue_Buffer::maybe_add(new ParseError('Interfaces cannot have properties', new Code_Location($this, $stmt)));
                return;
            } elseif ($stmt instanceof Php_Parser\Node\Stmt\Class_Const) {
                $member_stmts[] = $stmt;
                foreach ($stmt->consts as $const) {
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
        $pseudo_methods = $class_storage->pseudo_methods + $class_storage->pseudo_static_methods;
        Method_Comparator::compare_pseudo_methods($pseudo_methods, $this->fq_class_name, $codebase, $class_storage);
        $statements_analyzer = new Statements_Analyzer($this, new Node_Data_Provider());
        $statements_analyzer->analyze($member_stmts, $interface_context, null, true);
        Class_Const_Analyzer::analyze($this->storage, $this->get_codebase());
    }
}