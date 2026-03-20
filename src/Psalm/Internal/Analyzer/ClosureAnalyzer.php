<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer;

use Override;
use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Codebase\Variable_Use_Graph;
use Psalm\Internal\Data_Flow\Data_Flow_Node;
use Psalm\Internal\Php_Visitor\Short_Closure_Visitor;
use Psalm\Issue\Duplicate_Param;
use Psalm\Issue\Possibly_Undefined_Variable;
use Psalm\Issue\Undefined_Variable;
use Psalm\Issue_Buffer;
use Psalm\Storage\Unserialize_Memory_Usage_Suppression_Trait;
use Psalm\Type;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Union;
use function in_array;
use function is_string;
use function preg_match;
use function str_starts_with;
use function strtolower;
/**
 * @internal
 * @extends FunctionLikeAnalyzer<PhpParser\Node\Expr\Closure|PhpParser\Node\Expr\ArrowFunction>
 */
final class Closure_Analyzer extends Function_Like_Analyzer
{
    use Unserialize_Memory_Usage_Suppression_Trait;
    /**
     * @param PhpParser\Node\Expr\Closure|PhpParser\Node\Expr\ArrowFunction $function
     */
    public function __construct(Php_Parser\Node\Function_Like $function, Source_Analyzer $source)
    {
        $codebase = $source->get_codebase();
        $function_id = strtolower($source->get_file_path()) . ':' . $function->get_line() . ':' . (int) $function->get_attribute('startFilePos') . ':-:closure';
        $storage = $codebase->get_closure_storage($source->get_file_path(), $function_id);
        parent::__construct($function, $source, $storage);
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_template_type_map(): ?array
    {
        return $this->source->get_template_type_map();
    }
    /**
     * @return non-empty-lowercase-string
     */
    public function get_closure_id(): string
    {
        return strtolower($this->get_file_path()) . ':' . $this->function->get_line() . ':' . (int) $this->function->get_attribute('startFilePos') . ':-:closure';
    }
    /**
     * @param PhpParser\Node\Expr\Closure|PhpParser\Node\Expr\ArrowFunction $stmt
     */
    public static function analyze_expression(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Function_Like $stmt, Context $context): bool
    {
        $closure_analyzer = new Closure_Analyzer($stmt, $statements_analyzer);
        if ($stmt instanceof Php_Parser\Node\Expr\Closure && self::analyze_closure_uses($statements_analyzer, $stmt, $context) === false) {
            return false;
        }
        $use_context = new Context($context->self);
        $codebase = $statements_analyzer->get_codebase();
        if (!$statements_analyzer->is_static() && !$closure_analyzer->is_static()) {
            if ($context->collect_mutations && $context->self && $codebase->class_extends($context->self, (string) $statements_analyzer->get_fqcln())) {
                /** @psalm-suppress PossiblyUndefinedStringArrayOffset */
                $use_context->vars_in_scope['$this'] = $context->vars_in_scope['$this'];
            } elseif ($context->self) {
                $this_atomic = new T_Named_Object($context->self, true);
                $use_context->vars_in_scope['$this'] = new Union([$this_atomic]);
            }
        }
        foreach ($context->vars_in_scope as $var => $type) {
            if (str_starts_with($var, '$this->')) {
                $use_context->vars_in_scope[$var] = $type;
            }
        }
        if ($context->self) {
            $self_class_storage = $codebase->classlike_storage_provider->get($context->self);
            Class_Analyzer::add_context_properties($statements_analyzer, $self_class_storage, $use_context, $context->self, $statements_analyzer->get_parent_fqcln());
        }
        foreach ($context->vars_possibly_in_scope as $var => $_) {
            if (str_starts_with($var, '$this->')) {
                $use_context->vars_possibly_in_scope[$var] = true;
            }
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Closure) {
            foreach ($stmt->uses as $use) {
                if (!is_string($use->var->name)) {
                    continue;
                }
                $use_var_id = '$' . $use->var->name;
                if ($statements_analyzer->data_flow_graph instanceof Variable_Use_Graph && $context->has_variable($use_var_id)) {
                    $parent_nodes = $context->vars_in_scope[$use_var_id]->parent_nodes;
                    foreach ($parent_nodes as $parent_node) {
                        $statements_analyzer->data_flow_graph->add_path($parent_node, new Data_Flow_Node('closure-use', 'closure use', null), 'closure-use');
                    }
                }
                $use_context->vars_in_scope[$use_var_id] = $context->has_variable($use_var_id) ? $context->vars_in_scope[$use_var_id] : Type::get_mixed();
                if ($use->by_ref) {
                    $use_context->vars_in_scope[$use_var_id] = $use_context->vars_in_scope[$use_var_id]->set_properties(['by_ref' => true]);
                    $use_context->references_to_external_scope[$use_var_id] = true;
                }
                $use_context->vars_possibly_in_scope[$use_var_id] = true;
                foreach ($context->vars_in_scope as $var_id => $type) {
                    if (preg_match('/^\$' . $use->var->name . '[\[\-]/', $var_id)) {
                        $use_context->vars_in_scope[$var_id] = $type;
                        $use_context->vars_possibly_in_scope[$var_id] = true;
                    }
                }
            }
        } else {
            $traverser = new Php_Parser\Node_Traverser();
            $short_closure_visitor = new Short_Closure_Visitor();
            $traverser->add_visitor($short_closure_visitor);
            $traverser->traverse($stmt->get_stmts());
            foreach ($short_closure_visitor->get_used_variables() as $use_var_id => $_) {
                if ($context->has_variable($use_var_id)) {
                    $use_context->vars_in_scope[$use_var_id] = $context->vars_in_scope[$use_var_id];
                    if ($statements_analyzer->data_flow_graph instanceof Variable_Use_Graph) {
                        $parent_nodes = $context->vars_in_scope[$use_var_id]->parent_nodes;
                        foreach ($parent_nodes as $parent_node) {
                            $statements_analyzer->data_flow_graph->add_path($parent_node, new Data_Flow_Node('closure-use', 'closure use', null), 'closure-use');
                        }
                    }
                }
                $use_context->vars_possibly_in_scope[$use_var_id] = true;
            }
        }
        $use_context->calling_method_id = $context->calling_method_id;
        $use_context->phantom_classes = $context->phantom_classes;
        $byref_vars = [];
        $closure_analyzer->analyze($use_context, $statements_analyzer->node_data, $context, false, $byref_vars);
        foreach ($byref_vars as $key => $value) {
            $context->vars_in_scope[$key] = $value;
        }
        if ($closure_analyzer->inferred_impure && $statements_analyzer->get_source() instanceof Function_Like_Analyzer) {
            $statements_analyzer->get_source()->inferred_impure = true;
        }
        if ($closure_analyzer->inferred_has_mutation && $statements_analyzer->get_source() instanceof Function_Like_Analyzer) {
            $statements_analyzer->get_source()->inferred_has_mutation = true;
        }
        if (!$statements_analyzer->node_data->get_type($stmt)) {
            $statements_analyzer->node_data->set_type($stmt, Type::get_closure());
        }
        return true;
    }
    /**
     * @return  false|null
     */
    private static function analyze_closure_uses(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Closure $stmt, Context $context): ?bool
    {
        $param_names = [];
        foreach ($stmt->params as $i => $param) {
            if ($param->var instanceof Php_Parser\Node\Expr\Variable && is_string($param->var->name)) {
                $param_names[$i] = $param->var->name;
            } else {
                $param_names[$i] = '';
            }
        }
        foreach ($stmt->uses as $use) {
            if (!is_string($use->var->name)) {
                continue;
            }
            $use_var_id = '$' . $use->var->name;
            if (in_array($use->var->name, $param_names)) {
                if (Issue_Buffer::accepts(new Duplicate_Param('Closure use duplicates param name ' . $use_var_id, new Code_Location($statements_analyzer->get_source(), $use->var)), $statements_analyzer->get_suppressed_issues())) {
                    return false;
                }
            }
            if (!$context->has_variable($use_var_id)) {
                if ($use_var_id === '$argv') {
                    continue;
                }
                if ($use_var_id === '$argc') {
                    continue;
                }
                if (!isset($context->vars_possibly_in_scope[$use_var_id])) {
                    if ($context->check_variables) {
                        if (Issue_Buffer::accepts(new Undefined_Variable('Cannot find referenced variable ' . $use_var_id, new Code_Location($statements_analyzer->get_source(), $use->var)), $statements_analyzer->get_suppressed_issues())) {
                            return false;
                        }
                        return null;
                    }
                }
                $first_appearance = $statements_analyzer->get_first_appearance($use_var_id);
                if ($first_appearance) {
                    if (Issue_Buffer::accepts(new Possibly_Undefined_Variable('Possibly undefined variable ' . $use_var_id . ', first seen on line ' . $first_appearance->get_line_number(), new Code_Location($statements_analyzer->get_source(), $use->var)), $statements_analyzer->get_suppressed_issues())) {
                        return false;
                    }
                    continue;
                }
                if ($context->check_variables) {
                    if (Issue_Buffer::accepts(new Undefined_Variable('Cannot find referenced variable ' . $use_var_id, new Code_Location($statements_analyzer->get_source(), $use->var)), $statements_analyzer->get_suppressed_issues())) {
                        return false;
                    }
                    continue;
                }
            }
        }
        return null;
    }
}