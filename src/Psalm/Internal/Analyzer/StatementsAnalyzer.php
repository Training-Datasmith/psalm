<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer;

use InvalidArgumentException;
use Override;
use Php_Parser;
use Php_Parser\Comment\Doc;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Doc_Comment;
use Psalm\Exception\Docblock_Parse_Exception;
use Psalm\Exception\Incorrect_Docblock_Exception;
use Psalm\Exception\Type_Parse_Tree_Exception;
use Psalm\File_Manipulation;
use Psalm\Internal\Analyzer\Statements\Block\Do_Analyzer;
use Psalm\Internal\Analyzer\Statements\Block\For_Analyzer;
use Psalm\Internal\Analyzer\Statements\Block\Foreach_Analyzer;
use Psalm\Internal\Analyzer\Statements\Block\If_Else_Analyzer;
use Psalm\Internal\Analyzer\Statements\Block\Switch_Analyzer;
use Psalm\Internal\Analyzer\Statements\Block\Try_Analyzer;
use Psalm\Internal\Analyzer\Statements\Block\While_Analyzer;
use Psalm\Internal\Analyzer\Statements\Break_Analyzer;
use Psalm\Internal\Analyzer\Statements\Continue_Analyzer;
use Psalm\Internal\Analyzer\Statements\Declare_Analyzer;
use Psalm\Internal\Analyzer\Statements\Echo_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Assignment\Instance_Property_Assignment_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Assignment_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Class_Const_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Const_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Variable_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Simple_Type_Inferer;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements\Global_Analyzer;
use Psalm\Internal\Analyzer\Statements\Return_Analyzer;
use Psalm\Internal\Analyzer\Statements\Static_Analyzer;
use Psalm\Internal\Analyzer\Statements\Unset_Analyzer;
use Psalm\Internal\Analyzer\Statements\Unused_Assignment_Remover;
use Psalm\Internal\Codebase\Data_Flow_Graph;
use Psalm\Internal\Codebase\Taint_Flow_Graph;
use Psalm\Internal\Codebase\Variable_Use_Graph;
use Psalm\Internal\Data_Flow\Data_Flow_Node;
use Psalm\Internal\File_Manipulation\File_Manipulation_Buffer;
use Psalm\Internal\Provider\Node_Data_Provider;
use Psalm\Internal\Reference_Constraint;
use Psalm\Internal\Scanner\Parsed_Docblock;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Internal\Type\Type_Parser;
use Psalm\Internal\Type\Type_Tokenizer;
use Psalm\Issue\Check_Type;
use Psalm\Issue\Complex_Function;
use Psalm\Issue\Complex_Method;
use Psalm\Issue\Invalid_Docblock;
use Psalm\Issue\Missing_Docblock_Type;
use Psalm\Issue\Trace;
use Psalm\Issue\Undefined_Docblock_Class;
use Psalm\Issue\Undefined_Trace;
use Psalm\Issue\Unevaluated_Code;
use Psalm\Issue\Unrecognized_Statement;
use Psalm\Issue\Unused_Foreach_Value;
use Psalm\Issue\Unused_Variable;
use Psalm\Issue_Buffer;
use Psalm\Node_Type_Provider;
use Psalm\Plugin\Event_Handler\Event\After_Statement_Analysis_Event;
use Psalm\Plugin\Event_Handler\Event\Before_Statement_Analysis_Event;
use Psalm\Type;
use UnexpectedValueException;
use function array_change_key_case;
use function array_column;
use function array_combine;
use function array_keys;
use function array_map;
use function array_search;
use function assert;
use function count;
use function explode;
use function fwrite;
use function in_array;
use function is_string;
use function preg_split;
use function reset;
use function round;
use function str_starts_with;
use function strlen;
use function strrpos;
use function strtolower;
use function substr;
use function trim;
use const PREG_SPLIT_NO_EMPTY;
use const STDERR;
/**
 * @internal
 */
final class Statements_Analyzer extends Source_Analyzer
{
    private readonly File_Analyzer $file_analyzer;
    private readonly Codebase $codebase;
    /**
     * @var array<string, CodeLocation>
     */
    private array $all_vars = [];
    /**
     * @var array<string, int>
     */
    private array $var_branch_points = [];
    /**
     * Possibly undefined variables should be initialised if we're altering code
     *
     * @var array<string, int>|null
     */
    private ?array $vars_to_initialize = null;
    /**
     * @var array<string, FunctionAnalyzer>
     */
    private array $function_analyzers = [];
    /**
     * @var array<string, array{0: string, 1: CodeLocation}>
     */
    private array $unused_var_locations = [];
    /**
     * @var array<string, true>
     */
    public array $byref_uses = [];
    private ?Parsed_Docblock $parsed_docblock = null;
    private ?string $fake_this_class = null;
    public ?Data_Flow_Graph $data_flow_graph = null;
    /**
     * Locations of foreach values
     *
     * Used to discern ordinary UnusedVariables from UnusedForeachValues
     *
     * @var array<string, list<CodeLocation>>
     * @psalm-internal Psalm\Internal\Analyzer
     */
    public array $foreach_var_locations = [];
    public function __construct(protected Source_Analyzer $source, public Node_Data_Provider $node_data)
    {
        $this->file_analyzer = $source->get_file_analyzer();
        $this->codebase = $source->get_codebase();
        if ($this->codebase->taint_flow_graph) {
            $this->data_flow_graph = new Taint_Flow_Graph();
        } elseif ($this->codebase->find_unused_variables) {
            $this->data_flow_graph = new Variable_Use_Graph();
        }
    }
    /**
     * Checks an array of statements for validity
     *
     * @param  array<PhpParser\Node\Stmt>   $stmts
     * @return null|false
     */
    public function analyze(array $stmts, Context $context, ?Context $global_context = null, bool $root_scope = false): ?bool
    {
        if (!$stmts) {
            return null;
        }
        // hoist functions to the top
        $this->hoist_functions($stmts, $context);
        $project_analyzer = $this->get_file_analyzer()->project_analyzer;
        $codebase = $project_analyzer->get_codebase();
        if ($codebase->config->hoist_constants) {
            self::hoist_constants($this, $stmts, $context);
        }
        foreach ($stmts as $stmt) {
            if (self::analyze_statement($this, $stmt, $context, $global_context) === false) {
                return false;
            }
        }
        if ($root_scope && !$context->collect_initializations && !$context->collect_mutations && $codebase->find_unused_variables && $context->check_variables) {
            $this->check_unreferenced_vars($stmts, $context);
        }
        if ($codebase->alter_code && $root_scope && $this->vars_to_initialize) {
            $file_contents = $codebase->get_file_contents($this->get_file_path());
            foreach ($this->vars_to_initialize as $var_id => $branch_point) {
                $newline_pos = (int) strrpos($file_contents, "\n", $branch_point - strlen($file_contents)) + 1;
                $indentation = substr($file_contents, $newline_pos, $branch_point - $newline_pos);
                File_Manipulation_Buffer::add($this->get_file_path(), [new File_Manipulation($branch_point, $branch_point, $var_id . ' = null;' . "\n" . $indentation)]);
            }
        }
        if ($root_scope && $this->data_flow_graph instanceof Taint_Flow_Graph && $this->codebase->taint_flow_graph && $codebase->config->track_taints_in_path($this->get_file_path())) {
            $this->codebase->taint_flow_graph->add_graph($this->data_flow_graph);
        }
        return null;
    }
    /**
     * @param  array<PhpParser\Node\Stmt>   $stmts
     */
    private function hoist_functions(array $stmts, Context $context): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Php_Parser\Node\Stmt\Function_) {
                $function_name = strtolower($stmt->name->name);
                if ($ns = $this->get_namespace()) {
                    $fq_function_name = strtolower($ns) . '\\' . $function_name;
                } else {
                    $fq_function_name = $function_name;
                }
                if ($this->data_flow_graph && $this->codebase->find_unused_variables) {
                    foreach ($stmt->stmts as $function_stmt) {
                        if ($function_stmt instanceof Php_Parser\Node\Stmt\Global_) {
                            foreach ($function_stmt->vars as $var) {
                                if (!$var instanceof Php_Parser\Node\Expr\Variable) {
                                    continue;
                                }
                                if (!is_string($var->name)) {
                                    continue;
                                }
                                $var_id = '$' . $var->name;
                                if ($var_id !== '$argv' && $var_id !== '$argc') {
                                    $context->byref_constraints[$var_id] = new Reference_Constraint();
                                }
                            }
                        }
                    }
                }
                try {
                    $function_analyzer = new Function_Analyzer($stmt, $this->source);
                    $this->function_analyzers[$fq_function_name] = $function_analyzer;
                } catch (UnexpectedValueException) {
                    // do nothing
                }
            }
        }
    }
    /**
     * @param  array<PhpParser\Node\Stmt>   $stmts
     */
    private static function hoist_constants(Statements_Analyzer $statements_analyzer, array $stmts, Context $context): void
    {
        $codebase = $statements_analyzer->get_codebase();
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Php_Parser\Node\Stmt\Const_) {
                foreach ($stmt->consts as $const) {
                    Const_Fetch_Analyzer::set_const_type($statements_analyzer, $const->name->name, Simple_Type_Inferer::infer($codebase, $statements_analyzer->node_data, $const->value, $statements_analyzer->get_aliases(), $statements_analyzer) ?? Type::get_mixed(), $context);
                }
            } elseif ($stmt instanceof Php_Parser\Node\Stmt\Expression && $stmt->expr instanceof Php_Parser\Node\Expr\Func_Call && $stmt->expr->name instanceof Php_Parser\Node\Name && $stmt->expr->name->get_parts() === ['define'] && isset($stmt->expr->get_args()[1])) {
                $const_name = Const_Fetch_Analyzer::get_const_name($stmt->expr->get_args()[0]->value, $statements_analyzer->node_data, $codebase, $statements_analyzer->get_aliases());
                if ($const_name !== null) {
                    Const_Fetch_Analyzer::set_const_type($statements_analyzer, $const_name, Simple_Type_Inferer::infer($codebase, $statements_analyzer->node_data, $stmt->expr->get_args()[1]->value, $statements_analyzer->get_aliases(), $statements_analyzer) ?? Type::get_mixed(), $context);
                }
            }
        }
    }
    /**
     * @return false|null
     */
    private static function analyze_statement(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Stmt $stmt, Context $context, ?Context $global_context): ?bool
    {
        if (self::dispatch_before_statement_analysis($stmt, $context, $statements_analyzer) === false) {
            return false;
        }
        $ignore_variable_property = false;
        $ignore_variable_method = false;
        $codebase = $statements_analyzer->get_codebase();
        if ($statements_analyzer->get_project_analyzer()->debug_lines) {
            fwrite(STDERR, $statements_analyzer->get_file_path() . ':' . $stmt->get_line() . "\n");
        }
        $new_issues = null;
        $traced_variables = [];
        $checked_types = [];
        $has_parsed = false;
        foreach ($stmt->get_comments() as $docblock) {
            if (!$docblock instanceof Doc) {
                continue;
            }
            $has_parsed = true;
            $statements_analyzer->parse_statement_docblock($docblock, $stmt, $context);
            if (isset($statements_analyzer->parsed_docblock->tags['psalm-trace'])) {
                foreach ($statements_analyzer->parsed_docblock->tags['psalm-trace'] as $traced_variable_line) {
                    $possible_traced_variable_names = preg_split('/(?:\s*,\s*|\s+)/', $traced_variable_line, -1, PREG_SPLIT_NO_EMPTY);
                    if ($possible_traced_variable_names) {
                        $traced_variables = [...$traced_variables, ...$possible_traced_variable_names];
                    }
                }
            }
            foreach ($statements_analyzer->parsed_docblock->tags['psalm-check-type'] ?? [] as $inexact_check) {
                $checked_types[] = [$inexact_check, false];
            }
            foreach ($statements_analyzer->parsed_docblock->tags['psalm-check-type-exact'] ?? [] as $exact_check) {
                $checked_types[] = [$exact_check, true];
            }
            if (isset($statements_analyzer->parsed_docblock->tags['psalm-ignore-variable-method'])) {
                $context->ignore_variable_method = $ignore_variable_method = true;
            }
            if (isset($statements_analyzer->parsed_docblock->tags['psalm-ignore-variable-property'])) {
                $context->ignore_variable_property = $ignore_variable_property = true;
            }
            if (isset($statements_analyzer->parsed_docblock->tags['psalm-suppress'])) {
                $suppressed = $statements_analyzer->parsed_docblock->tags['psalm-suppress'];
                if ($suppressed) {
                    $new_issues = [];
                    foreach ($suppressed as $offset => $suppress_entry) {
                        foreach (Doc_Comment::parse_suppress_list($suppress_entry) as $issue_offset => $issue_type) {
                            $new_issues[$issue_offset + $offset] = $issue_type;
                        }
                    }
                    if ($codebase->track_unused_suppressions && (count($new_issues) === 1 || !in_array("UnusedPsalmSuppress", $new_issues))) {
                        foreach ($new_issues as $offset => $issue_type) {
                            if ($issue_type === 'InaccessibleMethod') {
                                continue;
                            }
                            Issue_Buffer::add_unused_suppression($statements_analyzer->get_file_path(), $offset, $issue_type);
                        }
                    }
                    $statements_analyzer->add_suppressed_issues($new_issues);
                }
            }
            if (isset($statements_analyzer->parsed_docblock->combined_tags['var']) && !($stmt instanceof Php_Parser\Node\Stmt\Expression && $stmt->expr instanceof Php_Parser\Node\Expr\Assign) && !$stmt instanceof Php_Parser\Node\Stmt\Foreach_ && !$stmt instanceof Php_Parser\Node\Stmt\Return_) {
                $file_path = $statements_analyzer->get_root_file_path();
                $file_storage_provider = $codebase->file_storage_provider;
                $file_storage = $file_storage_provider->get($file_path);
                $template_type_map = $statements_analyzer->get_template_type_map();
                $var_comments = [];
                try {
                    $var_comments = $codebase->config->disable_var_parsing ? [] : Comment_Analyzer::array_to_docblocks($docblock, $statements_analyzer->parsed_docblock, $statements_analyzer->get_source(), $statements_analyzer->get_aliases(), $template_type_map, $file_storage->type_aliases);
                } catch (Incorrect_Docblock_Exception $e) {
                    Issue_Buffer::maybe_add(new Missing_Docblock_Type($e->get_message(), new Code_Location($statements_analyzer->get_source(), $stmt)));
                } catch (Docblock_Parse_Exception $e) {
                    Issue_Buffer::maybe_add(new Invalid_Docblock($e->get_message(), new Code_Location($statements_analyzer->get_source(), $stmt)));
                }
                foreach ($var_comments as $var_comment) {
                    Assignment_Analyzer::assign_type_from_var_docblock($statements_analyzer, $stmt, $var_comment, $context);
                    if ($var_comment->var_id === '$this' && $var_comment->type && $codebase->class_exists((string) $var_comment->type)) {
                        $statements_analyzer->set_fqcln((string) $var_comment->type);
                    }
                }
            }
        }
        if (!$has_parsed) {
            $statements_analyzer->parsed_docblock = null;
        }
        if ($context->has_returned && !$context->collect_initializations && !$context->collect_mutations && !$stmt instanceof Php_Parser\Node\Stmt\Nop && !$stmt instanceof Php_Parser\Node\Stmt\Function_ && !$stmt instanceof Php_Parser\Node\Stmt\Class_ && !$stmt instanceof Php_Parser\Node\Stmt\Interface_ && !$stmt instanceof Php_Parser\Node\Stmt\Trait_ && !$stmt instanceof Php_Parser\Node\Stmt\Halt_Compiler && !$stmt instanceof Php_Parser\Node\Stmt\Declare_) {
            if ($codebase->find_unused_variables) {
                Issue_Buffer::maybe_add(new Unevaluated_Code('Expressions after return/throw/continue', new Code_Location($statements_analyzer->source, $stmt)), $statements_analyzer->source->get_suppressed_issues());
            }
            return null;
        }
        if ($stmt instanceof Php_Parser\Node\Stmt\If_) {
            if (If_Else_Analyzer::analyze($statements_analyzer, $stmt, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Try_Catch) {
            if (Try_Analyzer::analyze($statements_analyzer, $stmt, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\For_) {
            if (For_Analyzer::analyze($statements_analyzer, $stmt, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Foreach_) {
            if (Foreach_Analyzer::analyze($statements_analyzer, $stmt, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\While_) {
            if (While_Analyzer::analyze($statements_analyzer, $stmt, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Do_) {
            if (Do_Analyzer::analyze($statements_analyzer, $stmt, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Const_) {
            Const_Fetch_Analyzer::analyze_const_assignment($statements_analyzer, $stmt, $context);
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Unset_) {
            Unset_Analyzer::analyze($statements_analyzer, $stmt, $context);
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Return_) {
            Return_Analyzer::analyze($statements_analyzer, $stmt, $context);
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Switch_) {
            Switch_Analyzer::analyze($statements_analyzer, $stmt, $context);
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Break_) {
            Break_Analyzer::analyze($statements_analyzer, $stmt, $context);
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Continue_) {
            Continue_Analyzer::analyze($statements_analyzer, $stmt, $context);
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Static_) {
            Static_Analyzer::analyze($statements_analyzer, $stmt, $context);
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Echo_) {
            if (Echo_Analyzer::analyze($statements_analyzer, $stmt, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Function_) {
            Function_Analyzer::analyze_statement($statements_analyzer, $stmt, $context);
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Expression) {
            if (Expression_Analyzer::analyze($statements_analyzer, $stmt->expr, $context, false, $global_context, $stmt) === false) {
                return false;
            }
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Inline_Html) {
            // do nothing
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Global_) {
            Global_Analyzer::analyze($statements_analyzer, $stmt, $context, $global_context);
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Property) {
            Instance_Property_Assignment_Analyzer::analyze_statement($statements_analyzer, $stmt, $context);
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Class_Const) {
            Class_Const_Analyzer::analyze_assignment($statements_analyzer, $stmt, $context);
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Class_) {
            try {
                $class_analyzer = new Class_Analyzer($stmt, $statements_analyzer->source, $stmt->name->name ?? null);
                $class_analyzer->analyze(null, $global_context);
            } catch (InvalidArgumentException) {
                // disregard this exception, we'll likely see it elsewhere in the form
                // of an issue
            }
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Trait_) {
            Trait_Analyzer::analyze($statements_analyzer, $stmt, $context);
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Nop) {
            // do nothing
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Goto_) {
            // do nothing
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Label) {
            // do nothing
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Declare_) {
            Declare_Analyzer::analyze($statements_analyzer, $stmt, $context);
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Halt_Compiler) {
            $context->has_returned = true;
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Block) {
            foreach ($stmt->stmts as $sub) {
                self::analyze_statement($statements_analyzer, $sub, $context, $global_context);
            }
        } else if (Issue_Buffer::accepts(new Unrecognized_Statement('Psalm does not understand ' . $stmt::class, new Code_Location($statements_analyzer->source, $stmt)), $statements_analyzer->get_suppressed_issues())) {
            return false;
        }
        if (self::dispatch_after_statement_analysis($stmt, $context, $statements_analyzer) === false) {
            return false;
        }
        if ($new_issues) {
            $statements_analyzer->remove_suppressed_issues($new_issues);
        }
        if ($ignore_variable_property) {
            $context->ignore_variable_property = false;
        }
        if ($ignore_variable_method) {
            $context->ignore_variable_method = false;
        }
        foreach ($traced_variables as $traced_variable) {
            if (isset($context->vars_in_scope[$traced_variable])) {
                Issue_Buffer::maybe_add(new Trace($traced_variable . ': ' . $context->vars_in_scope[$traced_variable]->get_id(), new Code_Location($statements_analyzer->source, $stmt)), $statements_analyzer->get_suppressed_issues());
            } else {
                Issue_Buffer::maybe_add(new Undefined_Trace('Attempt to trace undefined variable ' . $traced_variable, new Code_Location($statements_analyzer->source, $stmt)), $statements_analyzer->get_suppressed_issues());
            }
        }
        foreach ($checked_types as [$check_type_line, $is_exact]) {
            [$checked_var, $check_type_string] = array_map(trim(...), explode('=', $check_type_line, 2)) + ['', ''];
            if ($check_type_string === '' || $checked_var === '') {
                Issue_Buffer::maybe_add(new Invalid_Docblock("Invalid format for @psalm-check-type" . ($is_exact ? "-exact" : ""), new Code_Location($statements_analyzer->source, $stmt)), $statements_analyzer->get_suppressed_issues());
            } else {
                $checked_var_id = $checked_var;
                $possibly_undefined = strrpos($checked_var_id, "?") === strlen($checked_var_id) - 1;
                if ($possibly_undefined) {
                    $checked_var_id = substr($checked_var_id, 0, strlen($checked_var_id) - 1);
                }
                if (!isset($context->vars_in_scope[$checked_var_id])) {
                    Issue_Buffer::maybe_add(new Invalid_Docblock("Attempt to check undefined variable {$checked_var_id}", new Code_Location($statements_analyzer->source, $stmt)), $statements_analyzer->get_suppressed_issues());
                } else {
                    try {
                        $checked_type = $context->vars_in_scope[$checked_var_id];
                        $path = $statements_analyzer->get_root_file_path();
                        $file_storage = $codebase->file_storage_provider->get($path);
                        $check_tokens = Type_Tokenizer::get_fully_qualified_tokens($check_type_string, $statements_analyzer->get_aliases(), $statements_analyzer->get_template_type_map(), $file_storage->type_aliases);
                        $check_type = Type_Parser::parse_tokens($check_tokens, null, $statements_analyzer->get_template_type_map() ?? [], $file_storage->type_aliases, true);
                        /** @psalm-suppress InaccessibleProperty We just created this type */
                        $check_type->possibly_undefined = $possibly_undefined;
                        if ($check_type->possibly_undefined !== $checked_type->possibly_undefined || !Union_Type_Comparator::is_contained_by($codebase, $checked_type, $check_type) || $is_exact && !Union_Type_Comparator::is_contained_by($codebase, $check_type, $checked_type)) {
                            $check_var = $checked_var_id . ($checked_type->possibly_undefined ? "?" : "");
                            Issue_Buffer::maybe_add(new Check_Type("Checked variable {$checked_var} = {$check_type->get_id()} does not match " . "{$check_var} = {$checked_type->get_id()}", new Code_Location($statements_analyzer->source, $stmt)), $statements_analyzer->get_suppressed_issues());
                        }
                    } catch (Type_Parse_Tree_Exception $e) {
                        Issue_Buffer::maybe_add(new Invalid_Docblock($e->get_message(), new Code_Location($statements_analyzer->source, $stmt)), $statements_analyzer->get_suppressed_issues());
                    }
                }
            }
        }
        return null;
    }
    private static function dispatch_after_statement_analysis(Php_Parser\Node\Stmt $stmt, Context $context, Statements_Analyzer $statements_analyzer): ?bool
    {
        $codebase = $statements_analyzer->get_codebase();
        $event = new After_Statement_Analysis_Event($stmt, $context, $statements_analyzer, $codebase, []);
        if ($codebase->config->event_dispatcher->dispatch_after_statement_analysis($event) === false) {
            return false;
        }
        $file_manipulations = $event->get_file_replacements();
        if ($file_manipulations) {
            File_Manipulation_Buffer::add($statements_analyzer->get_file_path(), $file_manipulations);
        }
        return null;
    }
    private static function dispatch_before_statement_analysis(Php_Parser\Node\Stmt $stmt, Context $context, Statements_Analyzer $statements_analyzer): ?bool
    {
        $codebase = $statements_analyzer->get_codebase();
        $event = new Before_Statement_Analysis_Event($stmt, $context, $statements_analyzer, $codebase, []);
        if ($codebase->config->event_dispatcher->dispatch_before_statement_analysis($event) === false) {
            return false;
        }
        $file_manipulations = $event->get_file_replacements();
        if ($file_manipulations) {
            File_Manipulation_Buffer::add($statements_analyzer->get_file_path(), $file_manipulations);
        }
        return null;
    }
    private function parse_statement_docblock(Php_Parser\Comment\Doc $docblock, Php_Parser\Node\Stmt $stmt, Context $context): void
    {
        $codebase = $this->get_codebase();
        try {
            $this->parsed_docblock = Doc_Comment::parse_preserving_length($docblock);
        } catch (Docblock_Parse_Exception $e) {
            Issue_Buffer::maybe_add(new Invalid_Docblock($e->get_message(), new Code_Location($this->get_source(), $stmt, null, true)));
            $this->parsed_docblock = null;
        }
        if ($this->parsed_docblock === null) {
            try {
                $this->parsed_docblock = Doc_Comment::parse_preserving_length($docblock, true);
            } catch (Docblock_Parse_Exception) {
                // already reported above
            }
        }
        $comments = $this->parsed_docblock;
        if (isset($comments->tags['psalm-scope-this'])) {
            assert(count($comments->tags['psalm-scope-this']));
            $trimmed = trim(reset($comments->tags['psalm-scope-this']));
            $scope_fqcn = Type::get_fqcln_from_string($trimmed, $this->get_aliases());
            if (!$codebase->class_exists($scope_fqcn)) {
                Issue_Buffer::maybe_add(new Undefined_Docblock_Class('Scope class ' . $scope_fqcn . ' does not exist', new Code_Location($this->get_source(), $stmt, null, true), $scope_fqcn));
            } else {
                $this_type = Type::parse_string($scope_fqcn);
                $context->self = $scope_fqcn;
                $context->vars_in_scope['$this'] = $this_type;
                $this->set_fqcln($scope_fqcn);
            }
        }
    }
    /**
     * @param  array<PhpParser\Node\Stmt>   $stmts
     */
    public function check_unreferenced_vars(array $stmts, Context $context): void
    {
        $source = $this->get_source();
        $codebase = $source->get_codebase();
        $function_storage = $source instanceof Function_Like_Analyzer ? $source->get_function_like_storage($this) : null;
        $var_list = array_column($this->unused_var_locations, 0);
        $loc_list = array_column($this->unused_var_locations, 1);
        $project_analyzer = $this->get_project_analyzer();
        $unused_var_remover = new Unused_Assignment_Remover();
        if ($this->data_flow_graph instanceof Variable_Use_Graph && $codebase->config->limit_method_complexity && $source instanceof Function_Like_Analyzer && !$source instanceof Closure_Analyzer && $function_storage && $function_storage->location) {
            [$count, , $unique_destinations, $mean] = $this->data_flow_graph->get_edge_stats();
            $average_destination_branches_converging = $unique_destinations > 0 ? $count / $unique_destinations : 0;
            if ($count > $codebase->config->max_graph_size && $mean > $codebase->config->max_avg_path_length && $average_destination_branches_converging > 1.1) {
                if ($source instanceof Function_Analyzer) {
                    Issue_Buffer::maybe_add(new Complex_Function('This function’s complexity is greater than the project limit' . ' (method graph size = ' . $count . ', average path length = ' . round($mean) . ')', $function_storage->location), $this->get_suppressed_issues());
                } elseif ($source instanceof Method_Analyzer) {
                    Issue_Buffer::maybe_add(new Complex_Method('This method’s complexity is greater than the project limit' . ' (method graph size = ' . $count . ', average path length = ' . round($mean) . ')', $function_storage->location), $this->get_suppressed_issues());
                }
            }
        }
        foreach ($this->unused_var_locations as [$var_id, $original_location]) {
            if (str_starts_with($var_id, '$_')) {
                continue;
            }
            if ($function_storage) {
                $param_index = array_search(substr($var_id, 1), array_keys($function_storage->param_lookup), true);
                if ($param_index !== false) {
                    $param = $function_storage->params[$param_index];
                    if ($param->location && ($original_location->raw_file_end === $param->location->raw_file_end || $param->by_ref)) {
                        continue;
                    }
                }
            }
            $assignment_node = Data_Flow_Node::get_for_assignment($var_id, $original_location);
            if (!isset($this->byref_uses[$var_id]) && !isset($context->referenced_globals[$var_id]) && !Variable_Fetch_Analyzer::is_super_global($var_id) && $this->data_flow_graph instanceof Variable_Use_Graph && !$this->data_flow_graph->is_variable_used($assignment_node)) {
                $is_foreach_var = false;
                if (isset($this->foreach_var_locations[$var_id])) {
                    foreach ($this->foreach_var_locations[$var_id] as $location) {
                        if ($location->raw_file_start === $original_location->raw_file_start) {
                            $is_foreach_var = true;
                            break;
                        }
                    }
                }
                if ($is_foreach_var) {
                    $issue = new Unused_Foreach_Value($var_id . ' is never referenced or the value is not used', $original_location);
                } else {
                    $issue = new Unused_Variable($var_id . ' is never referenced or the value is not used', $original_location);
                }
                if ($codebase->alter_code && $issue instanceof Unused_Variable && !$unused_var_remover->check_if_var_removed($var_id, $original_location) && isset($project_analyzer->get_issues_to_fix()['UnusedVariable']) && !Issue_Buffer::is_suppressed($issue, $this->get_suppressed_issues())) {
                    $unused_var_remover->find_unused_assignment($this->get_codebase(), $stmts, array_combine($var_list, $loc_list), $var_id, $original_location);
                }
                Issue_Buffer::maybe_add($issue, $this->get_suppressed_issues(), $issue instanceof Unused_Variable);
            }
        }
    }
    public function has_variable(string $var_name): bool
    {
        return isset($this->all_vars[$var_name]);
    }
    public function register_variable(string $var_id, Code_Location $location, ?int $branch_point): void
    {
        $this->all_vars[$var_id] = $location;
        if ($branch_point) {
            $this->var_branch_points[$var_id] = $branch_point;
        }
        $this->register_variable_assignment($var_id, $location);
    }
    public function register_variable_assignment(string $var_id, Code_Location $location): void
    {
        $this->unused_var_locations[$location->get_hash()] = [$var_id, $location];
    }
    /**
     * @return array<string, array{0: string, 1: CodeLocation}>
     */
    public function get_unused_var_locations(): array
    {
        return $this->unused_var_locations;
    }
    public function register_possibly_undefined_variable(string $undefined_var_id, Php_Parser\Node\Expr\Variable $stmt): void
    {
        if (!$this->data_flow_graph) {
            return;
        }
        $use_location = new Code_Location($this->get_source(), $stmt);
        $use_node = Data_Flow_Node::get_for_assignment($undefined_var_id, $use_location);
        $stmt_type = $this->node_data->get_type($stmt);
        if ($stmt_type) {
            $stmt_type = $stmt_type->add_parent_nodes([$use_node->id => $use_node]);
            $this->node_data->set_type($stmt, $stmt_type);
        }
        foreach ($this->unused_var_locations as [$var_id, $original_location]) {
            if ($var_id === $undefined_var_id) {
                $parent_node = Data_Flow_Node::get_for_assignment($var_id, $original_location);
                $this->data_flow_graph->add_path($parent_node, $use_node, '=');
            }
        }
    }
    /**
     * @return array<string, DataFlowNode>
     */
    public function get_parent_nodes_for_possibly_undefined_variable(string $undefined_var_id): array
    {
        if (!$this->data_flow_graph) {
            return [];
        }
        $parent_nodes = [];
        foreach ($this->unused_var_locations as [$var_id, $original_location]) {
            if ($var_id === $undefined_var_id) {
                $assignment_node = Data_Flow_Node::get_for_assignment($var_id, $original_location);
                $parent_nodes[$assignment_node->id] = $assignment_node;
            }
        }
        return $parent_nodes;
    }
    /**
     * The first appearance of the variable in this set of statements being evaluated
     */
    public function get_first_appearance(string $var_id): ?Code_Location
    {
        return $this->all_vars[$var_id] ?? null;
    }
    public function get_branch_point(string $var_id): ?int
    {
        return $this->var_branch_points[$var_id] ?? null;
    }
    public function add_variable_initialization(string $var_id, int $branch_point): void
    {
        $this->vars_to_initialize[$var_id] = $branch_point;
    }
    #[Override]
    public function get_file_analyzer(): File_Analyzer
    {
        return $this->file_analyzer;
    }
    #[Override]
    public function get_codebase(): Codebase
    {
        return $this->codebase;
    }
    /**
     * @return array<string, FunctionAnalyzer>
     */
    public function get_function_analyzers(): array
    {
        return $this->function_analyzers;
    }
    /**
     * @param array<string, true> $byref_uses
     */
    public function set_by_ref_uses(array $byref_uses): void
    {
        $this->byref_uses = $byref_uses;
    }
    /**
     * @return array<string, array<array-key, CodeLocation>>
     */
    public function get_uncaught_throws(Context $context): array
    {
        $uncaught_throws = [];
        if ($context->collect_exceptions) {
            if ($context->possibly_thrown_exceptions) {
                $config = $this->codebase->config;
                $ignored_exceptions = array_change_key_case($context->is_global ? $config->ignored_exceptions_in_global_scope : $config->ignored_exceptions);
                $ignored_exceptions_and_descendants = array_change_key_case($context->is_global ? $config->ignored_exceptions_and_descendants_in_global_scope : $config->ignored_exceptions_and_descendants);
                foreach ($context->possibly_thrown_exceptions as $possibly_thrown_exception => $codelocations) {
                    if (isset($ignored_exceptions[strtolower($possibly_thrown_exception)])) {
                        continue;
                    }
                    $is_expected = false;
                    foreach ($ignored_exceptions_and_descendants as $expected_exception => $_) {
                        try {
                            if ($expected_exception === strtolower($possibly_thrown_exception) || $this->codebase->class_extends($possibly_thrown_exception, $expected_exception) || $this->codebase->interface_extends($possibly_thrown_exception, $expected_exception)) {
                                $is_expected = true;
                                break;
                            }
                        } catch (InvalidArgumentException) {
                            $is_expected = true;
                            break;
                        }
                    }
                    if (!$is_expected) {
                        $uncaught_throws[$possibly_thrown_exception] = $codelocations;
                    }
                }
            }
        }
        return $uncaught_throws;
    }
    public function get_function_analyzer(string $function_id): ?Function_Analyzer
    {
        return $this->function_analyzers[$function_id] ?? null;
    }
    public function get_parsed_docblock(): ?Parsed_Docblock
    {
        return $this->parsed_docblock;
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_fqcln(): ?string
    {
        if ($this->fake_this_class) {
            return $this->fake_this_class;
        }
        return parent::get_fqcln();
    }
    public function set_fqcln(string $fake_this_class): void
    {
        $this->fake_this_class = $fake_this_class;
    }
    /**
     * @return NodeDataProvider
     */
    #[Override]
    public function get_node_type_provider(): Node_Type_Provider
    {
        return $this->node_data;
    }
    public function get_fully_qualified_function_method_or_namespace_name(): ?string
    {
        if ($this->source instanceof Method_Analyzer) {
            $fqcn = $this->get_fqcln();
            $method_name = $this->source->get_function_like_storage($this)->cased_name;
            assert($fqcn !== null && $method_name !== null);
            return "{$fqcn}::{$method_name}";
        }
        if ($this->source instanceof Function_Analyzer) {
            $namespace = $this->get_namespace();
            $namespace = $namespace === "" ? "" : "{$namespace}\\";
            $function_name = $this->source->get_function_like_storage($this)->cased_name;
            assert($function_name !== null);
            return "{$namespace}{$function_name}";
        }
        return $this->get_namespace();
    }
}