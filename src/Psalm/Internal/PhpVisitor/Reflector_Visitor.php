<?php

declare (strict_types=1);
namespace Psalm\Internal\Php_Visitor;

use LogicException;
use Override;
use Php_Parser;
use Php_Parser\Node\Expr;
use Php_Parser\Node\Name;
use Psalm\Aliases;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Exception\Code_Exception;
use Psalm\Exception\Docblock_Parse_Exception;
use Psalm\Exception\Type_Parse_Tree_Exception;
use Psalm\File_Source;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Comment_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Simple_Type_Inferer;
use Psalm\Internal\Event_Dispatcher;
use Psalm\Internal\Php_Visitor\Reflector\Class_Like_Node_Scanner;
use Psalm\Internal\Php_Visitor\Reflector\Expression_Resolver;
use Psalm\Internal\Php_Visitor\Reflector\Expression_Scanner;
use Psalm\Internal\Php_Visitor\Reflector\Function_Like_Node_Scanner;
use Psalm\Internal\Provider\Node_Data_Provider;
use Psalm\Internal\Scanner\File_Scanner;
use Psalm\Internal\Scanner\Php_Storm_Meta_Scanner;
use Psalm\Internal\Type\Type_Alias;
use Psalm\Internal\Type\Type_Parser;
use Psalm\Issue\Invalid_Docblock;
use Psalm\Issue\Tainted_Input;
use Psalm\Plugin\Event_Handler\Event\After_Class_Like_Visit_Event;
use Psalm\Storage\File_Storage;
use Psalm\Storage\Method_Storage;
use Psalm\Type;
use Spl_Object_Storage;
use UnexpectedValueException;
use function array_pop;
use function defined;
use function end;
use function explode;
use function in_array;
use function is_string;
use function reset;
use function spl_object_id;
use function strpos;
use function strtolower;
/**
 * @internal
 */
final class Reflector_Visitor extends Php_Parser\Node_Visitor_Abstract implements File_Source
{
    private Aliases $aliases;
    private readonly string $file_path;
    private readonly bool $scan_deep;
    /**
     * @var array<FunctionLikeNodeScanner>
     */
    private array $functionlike_node_scanners = [];
    /**
     * @var array<ClassLikeNodeScanner>
     */
    private array $classlike_node_scanners = [];
    private ?Name $namespace_name = null;
    private ?Expr $exists_cond_expr = null;
    private ?int $skip_if_descendants = null;
    /**
     * @var array<string, TypeAlias>
     */
    private array $type_aliases = [];
    /**
     * @var array<int, bool>
     */
    private array $bad_classes = [];
    private readonly Event_Dispatcher $event_dispatcher;
    /**
     * @var SplObjectStorage<PhpParser\Node\FunctionLike, null>
     */
    private readonly Spl_Object_Storage $closure_statements;
    public function __construct(private readonly Codebase $codebase, private readonly File_Scanner $file_scanner, private readonly File_Storage $file_storage)
    {
        $this->file_path = $file_scanner->file_path;
        $this->scan_deep = $file_scanner->will_analyze;
        $this->aliases = $this->file_storage->aliases = new Aliases();
        $this->event_dispatcher = $this->codebase->config->event_dispatcher;
        $this->closure_statements = new Spl_Object_Storage();
    }
    #[Override]
    public function enter_node(Php_Parser\Node $node): ?int
    {
        foreach ($node->get_comments() as $comment) {
            if ($comment instanceof Php_Parser\Comment\Doc && !$node instanceof Php_Parser\Node\Stmt\Class_Like) {
                try {
                    $type_aliases = Class_Like_Node_Scanner::get_type_aliases_from_comment($comment, $this->aliases, $this->type_aliases, null);
                    foreach ($type_aliases as $type_alias) {
                        // finds issues, if there are any
                        Type_Parser::parse_tokens($type_alias->replacement_tokens);
                    }
                    $this->type_aliases += $type_aliases;
                } catch (Docblock_Parse_Exception|Type_Parse_Tree_Exception $e) {
                    $this->file_storage->docblock_issues[] = new Invalid_Docblock($e->get_message(), new Code_Location($this->file_scanner, $node, null, true));
                }
            }
        }
        if ($node instanceof Php_Parser\Node\Stmt\Namespace_) {
            $this->handle_namespace($node);
        } elseif ($node instanceof Php_Parser\Node\Stmt\Use_) {
            $this->handle_use($node);
        } elseif ($node instanceof Php_Parser\Node\Stmt\Group_Use) {
            $this->handle_group_use($node);
        } elseif ($node instanceof Php_Parser\Node\Stmt\Class_Like) {
            if ($this->skip_if_descendants) {
                return null;
            }
            $classlike_node_scanner = new Class_Like_Node_Scanner($this->codebase, $this->file_storage, $this->file_scanner, $this->aliases, $this->namespace_name);
            if ($classlike_node_scanner->start($node) === false) {
                $this->bad_classes[spl_object_id($node)] = true;
                return self::DONT_TRAVERSE_CURRENT_AND_CHILDREN;
            }
            $this->classlike_node_scanners[] = $classlike_node_scanner;
            $this->type_aliases = [...$this->type_aliases, ...$classlike_node_scanner->type_aliases];
        } elseif ($node instanceof Php_Parser\Node\Stmt\Try_Catch) {
            foreach ($node->catches as $catch) {
                foreach ($catch->types as $catch_type) {
                    $catch_fqcln = Class_Like_Analyzer::get_fqcln_from_name_object($catch_type, $this->aliases);
                    if (!in_array(strtolower($catch_fqcln), ['self', 'static', 'parent'], true)) {
                        $this->codebase->scanner->queue_class_like_for_scanning($catch_fqcln);
                        $this->file_storage->referenced_classlikes[strtolower($catch_fqcln)] = $catch_fqcln;
                    }
                }
            }
        } elseif ($node instanceof Php_Parser\Node\Function_Like || $node instanceof Php_Parser\Node\Stmt\Expression && ($node->expr instanceof Php_Parser\Node\Expr\Arrow_Function || $node->expr instanceof Php_Parser\Node\Expr\Closure) || $node instanceof Php_Parser\Node\Arg && ($node->value instanceof Php_Parser\Node\Expr\Arrow_Function || $node->value instanceof Php_Parser\Node\Expr\Closure) || $node instanceof Php_Parser\Node\Array_Item && ($node->value instanceof Php_Parser\Node\Expr\Arrow_Function || $node->value instanceof Php_Parser\Node\Expr\Closure)) {
            $doc_comment = null;
            if ($node instanceof Php_Parser\Node\Stmt\Function_ || $node instanceof Php_Parser\Node\Stmt\Class_Method) {
                if ($this->skip_if_descendants) {
                    return null;
                }
            } elseif ($node instanceof Php_Parser\Node\Stmt\Expression) {
                $doc_comment = $node->get_doc_comment();
                /** @var PhpParser\Node\FunctionLike */
                $node = $node->expr;
                $this->closure_statements->offsetSet($node);
            } elseif ($node instanceof Php_Parser\Node\Arg || $node instanceof Php_Parser\Node\Array_Item) {
                $doc_comment = $node->get_doc_comment();
                /** @var PhpParser\Node\FunctionLike */
                $node = $node->value;
                $this->closure_statements->offsetSet($node);
            } elseif ($this->closure_statements->offsetExists($node)) {
                // This is a closure that was already processed at the statement level.
                return null;
            }
            $classlike_storage = null;
            if ($this->classlike_node_scanners) {
                $classlike_node_scanner = end($this->classlike_node_scanners);
                $classlike_storage = $classlike_node_scanner->storage;
            }
            $functionlike_types = [];
            foreach ($this->functionlike_node_scanners as $functionlike_node_scanner) {
                $functionlike_storage = $functionlike_node_scanner->storage;
                $functionlike_types += $functionlike_storage->template_types ?? [];
            }
            $functionlike_node_scanner = new Function_Like_Node_Scanner($this->codebase, $this->file_scanner, $this->file_storage, $this->aliases, $this->type_aliases, $classlike_storage, $functionlike_types);
            $functionlike_node_scanner->start($node, false, $doc_comment);
            $this->functionlike_node_scanners[] = $functionlike_node_scanner;
            if ($classlike_storage && $this->codebase->analysis_php_version_id >= 80000 && $node instanceof Php_Parser\Node\Stmt\Class_Method && strtolower($node->name->name) === '__tostring') {
                if ($classlike_storage->is_interface) {
                    $classlike_storage->parent_interfaces['stringable'] = 'Stringable';
                } else {
                    $classlike_storage->class_implements['stringable'] = 'Stringable';
                }
                $this->codebase->scanner->queue_class_like_for_scanning('Stringable');
            }
            if (!$this->scan_deep) {
                return self::DONT_TRAVERSE_CHILDREN;
            }
        } elseif ($node instanceof Php_Parser\Node\Stmt\Global_) {
            $functionlike_node_scanner = end($this->functionlike_node_scanners);
            if ($functionlike_node_scanner && $functionlike_node_scanner->storage) {
                foreach ($node->vars as $var) {
                    if (!$var instanceof Php_Parser\Node\Expr\Variable) {
                        continue;
                    }
                    if (!(is_string($var->name) && $var->name !== 'argv')) {
                        continue;
                    }
                    if (!($var->name !== 'argc')) {
                        continue;
                    }
                    $var_id = '$' . $var->name;
                    $functionlike_node_scanner->storage->global_variables[$var_id] = true;
                    if (isset($this->codebase->config->globals[$var_id])) {
                        $var_type = Type::parse_string($this->codebase->config->globals[$var_id]);
                        /** @psalm-suppress UnusedMethodCall */
                        $var_type->queue_class_likes_for_scanning($this->codebase, $this->file_storage);
                    }
                }
            }
        } elseif ($node instanceof Php_Parser\Node\Stmt\Trait_Use) {
            if ($this->skip_if_descendants) {
                return null;
            }
            if (!$this->classlike_node_scanners) {
                throw new LogicException('$this->classlike_node_scanners should not be empty');
            }
            $classlike_node_scanner = end($this->classlike_node_scanners);
            $classlike_node_scanner->handle_trait_use($node);
        } elseif ($node instanceof Php_Parser\Node\Stmt\Const_) {
            foreach ($node->consts as $const) {
                $const_type = Simple_Type_Inferer::infer($this->codebase, new Node_Data_Provider(), $const->value, $this->aliases) ?? Type::get_mixed();
                $fq_const_name = Type::get_fqcln_from_string($const->name->name, $this->aliases);
                if (($this->codebase->register_stub_files || $this->codebase->register_autoload_files || $this->codebase->all_constants_global) && (!defined($fq_const_name) || !$const_type->is_mixed())) {
                    $this->codebase->add_global_constant_type($fq_const_name, $const_type);
                }
                $this->file_storage->constants[$fq_const_name] = $const_type;
                $this->file_storage->declaring_constants[$fq_const_name] = $this->file_path;
            }
        } elseif ($node instanceof Php_Parser\Node\Stmt\If_ && !$this->skip_if_descendants) {
            if (!$this->functionlike_node_scanners) {
                $this->exists_cond_expr = $node->cond;
                if (Expression_Resolver::enter_conditional($this->codebase, $this->file_path, $this->exists_cond_expr) === false) {
                    // the else node should terminate the agreement
                    $this->skip_if_descendants = $node->else ? $node->else->get_line() : $node->get_line();
                }
            }
        } elseif ($node instanceof Php_Parser\Node\Stmt\Else_) {
            if ($this->skip_if_descendants === $node->get_line()) {
                $this->skip_if_descendants = null;
                $this->exists_cond_expr = null;
            } elseif (!$this->skip_if_descendants) {
                if ($this->exists_cond_expr && Expression_Resolver::enter_conditional($this->codebase, $this->file_path, $this->exists_cond_expr) === true) {
                    $this->skip_if_descendants = $node->get_line();
                }
            }
        } elseif ($node instanceof Expr) {
            $functionlike_storage = null;
            if ($this->functionlike_node_scanners) {
                $functionlike_node_scanner = end($this->functionlike_node_scanners);
                $functionlike_storage = $functionlike_node_scanner->storage;
            }
            Expression_Scanner::scan($this->codebase, $this->file_scanner, $this->file_storage, $this->aliases, $node, $functionlike_storage, $this->skip_if_descendants);
        }
        if ($doc_comment = $node->get_doc_comment()) {
            $var_comments = [];
            $template_types = [];
            if ($this->classlike_node_scanners) {
                $classlike_node_scanner = end($this->classlike_node_scanners);
                $classlike_storage = $classlike_node_scanner->storage;
                $template_types = $classlike_storage->template_types ?? [];
            }
            foreach ($this->functionlike_node_scanners as $functionlike_node_scanner) {
                $functionlike_storage = $functionlike_node_scanner->storage;
                $template_types += $functionlike_storage->template_types ?? [];
            }
            try {
                $var_comments = Comment_Analyzer::get_type_from_comment($doc_comment, $this->file_scanner, $this->aliases, $template_types, $this->type_aliases);
            } catch (Docblock_Parse_Exception) {
                // do nothing
            }
            foreach ($var_comments as $var_comment) {
                if (!$var_comment->type) {
                    continue;
                }
                $var_type = $var_comment->type;
                /** @psalm-suppress UnusedMethodCall */
                $var_type->queue_class_likes_for_scanning($this->codebase, $this->file_storage);
            }
        }
        if ($node instanceof Php_Parser\Node\Expr\Assign || $node instanceof Php_Parser\Node\Expr\Assign_Op || $node instanceof Php_Parser\Node\Expr\Assign_Ref) {
            if ($node->var instanceof Php_Parser\Node\Expr\Property_Fetch && $node->var->var instanceof Php_Parser\Node\Expr\Variable && $node->var->var->name === 'this' && $node->var->name instanceof Php_Parser\Node\Identifier) {
                if ($this->functionlike_node_scanners) {
                    $functionlike_node_scanner = end($this->functionlike_node_scanners);
                    $functionlike_storage = $functionlike_node_scanner->storage;
                    if ($functionlike_storage instanceof Method_Storage) {
                        $functionlike_storage->this_property_mutations[$node->var->name->name] = true;
                    }
                }
            }
        }
        return null;
    }
    private function handle_namespace(Php_Parser\Node\Stmt\Namespace_ $node): void
    {
        $this->file_storage->aliases = $this->aliases;
        $this->namespace_name = $node->name;
        $this->aliases = new Aliases($node->name ? $node->name->to_string() : '', $this->aliases->uses, $this->aliases->functions, $this->aliases->constants, $this->aliases->uses_flipped, $this->aliases->functions_flipped, $this->aliases->constants_flipped);
        $this->file_storage->namespace_aliases[(int) $node->get_attribute('startFilePos')] = $this->aliases;
        if ($node->stmts) {
            $this->aliases->namespace_first_stmt_start = (int) $node->stmts[0]->get_attribute('startFilePos');
        }
    }
    private function handle_use(Php_Parser\Node\Stmt\Use_ $node): void
    {
        foreach ($node->uses as $use) {
            $use_path = $use->name->to_string();
            $use_alias = $use->alias->name ?? $use->name->get_last();
            switch ($use->type !== Php_Parser\Node\Stmt\Use_::TYPE_UNKNOWN ? $use->type : $node->type) {
                case Php_Parser\Node\Stmt\Use_::TYPE_FUNCTION:
                    $this->aliases->functions[strtolower($use_alias)] = $use_path;
                    $this->aliases->functions_flipped[strtolower($use_path)] = $use_alias;
                    break;
                case Php_Parser\Node\Stmt\Use_::TYPE_CONSTANT:
                    $this->aliases->constants[$use_alias] = $use_path;
                    $this->aliases->constants_flipped[$use_path] = $use_alias;
                    break;
                case Php_Parser\Node\Stmt\Use_::TYPE_NORMAL:
                    $this->aliases->uses[strtolower($use_alias)] = $use_path;
                    $this->aliases->uses_flipped[strtolower($use_path)] = $use_alias;
                    break;
            }
        }
        if (!$this->aliases->uses_start) {
            $this->aliases->uses_start = (int) $node->get_attribute('startFilePos');
        }
        $this->aliases->uses_end = (int) $node->get_attribute('endFilePos') + 1;
    }
    private function handle_group_use(Php_Parser\Node\Stmt\Group_Use $node): void
    {
        $use_prefix = $node->prefix->to_string();
        foreach ($node->uses as $use) {
            $use_path = $use_prefix . '\\' . $use->name->to_string();
            $use_alias = $use->alias->name ?? $use->name->get_last();
            switch ($use->type !== Php_Parser\Node\Stmt\Use_::TYPE_UNKNOWN ? $use->type : $node->type) {
                case Php_Parser\Node\Stmt\Use_::TYPE_FUNCTION:
                    $this->aliases->functions[strtolower($use_alias)] = $use_path;
                    $this->aliases->functions_flipped[strtolower($use_path)] = $use_alias;
                    break;
                case Php_Parser\Node\Stmt\Use_::TYPE_CONSTANT:
                    $this->aliases->constants[$use_alias] = $use_path;
                    $this->aliases->constants_flipped[$use_path] = $use_alias;
                    break;
                case Php_Parser\Node\Stmt\Use_::TYPE_NORMAL:
                    $this->aliases->uses[strtolower($use_alias)] = $use_path;
                    $this->aliases->uses_flipped[strtolower($use_path)] = $use_alias;
                    break;
            }
        }
        if (!$this->aliases->uses_start) {
            $this->aliases->uses_start = (int) $node->get_attribute('startFilePos');
        }
        $this->aliases->uses_end = (int) $node->get_attribute('endFilePos') + 1;
    }
    #[Override]
    public function leave_node(Php_Parser\Node $node)
    {
        if ($node instanceof Php_Parser\Node\Stmt\Namespace_) {
            if (!$this->file_storage->aliases) {
                throw new UnexpectedValueException('File storage liases should not be null');
            }
            $this->aliases = $this->file_storage->aliases;
            if ($this->codebase->register_stub_files && $node->name && $node->name->get_parts() === ['PHPSTORM_META']) {
                foreach ($node->stmts as $meta_stmt) {
                    if ($meta_stmt instanceof Php_Parser\Node\Stmt\Expression && $meta_stmt->expr instanceof Php_Parser\Node\Expr\Func_Call && $meta_stmt->expr->name instanceof Name && $meta_stmt->expr->name->get_parts() === ['override']) {
                        Php_Storm_Meta_Scanner::handle_override($meta_stmt->expr->get_args(), $this->codebase);
                    }
                }
            }
        } elseif ($node instanceof Php_Parser\Node\Stmt\Class_Like) {
            if ($this->skip_if_descendants) {
                return null;
            }
            if (isset($this->bad_classes[spl_object_id($node)])) {
                return null;
            }
            if (!$this->classlike_node_scanners) {
                throw new UnexpectedValueException('$this->classlike_node_scanners cannot be empty');
            }
            $classlike_node_scanner = array_pop($this->classlike_node_scanners);
            $classlike_storage = $classlike_node_scanner->finish($node);
            if ($classlike_storage->has_visitor_issues) {
                $this->file_storage->has_visitor_issues = true;
            }
            $event = new After_Class_Like_Visit_Event($node, $classlike_storage, $this, $this->codebase, []);
            $this->event_dispatcher->dispatch_after_class_like_visit($event);
            if (!$this->file_storage->has_visitor_issues) {
                $this->codebase->cache_class_like_storage($classlike_storage, $this->file_path);
            }
        } elseif ($node instanceof Php_Parser\Node\Function_Like) {
            if ($this->skip_if_descendants) {
                return null;
            }
            if (!$this->functionlike_node_scanners) {
                if ($this->file_storage->has_visitor_issues) {
                    return null;
                }
                throw new UnexpectedValueException('There should be function storages for line ' . $this->file_path . ':' . $node->get_start_line());
            }
            $functionlike_node_scanner = array_pop($this->functionlike_node_scanners);
            if ($functionlike_node_scanner->storage) {
                foreach ($functionlike_node_scanner->storage->docblock_issues as $docblock_issue) {
                    if (strpos($docblock_issue->code_location->file_path, 'CoreGenericFunctions.phpstub') || strpos($docblock_issue->code_location->file_path, 'CoreGenericClasses.phpstub') || strpos($this->file_path, 'CoreGenericIterators.phpstub')) {
                        $e = reset($functionlike_node_scanner->storage->docblock_issues);
                        $fqcn_parts = explode('\\', $e::class);
                        $issue_type = array_pop($fqcn_parts);
                        $message = $e instanceof Tainted_Input ? $e->get_journey_message() : $e->message;
                        throw new Code_Exception('Error with core stub file docblocks: ' . $issue_type . ' - ' . $e->get_short_location_with_previous() . ':' . $e->code_location->get_column() . ' - ' . $message);
                    }
                }
                if ($functionlike_node_scanner->storage->has_visitor_issues) {
                    $this->file_storage->has_visitor_issues = true;
                }
            }
        } elseif ($node instanceof Php_Parser\Node\Stmt\If_ && $node->get_line() === $this->skip_if_descendants) {
            $this->exists_cond_expr = null;
            $this->skip_if_descendants = null;
        } elseif ($node instanceof Php_Parser\Node\Stmt\Else_ && $node->get_line() === $this->skip_if_descendants) {
            $this->exists_cond_expr = null;
            $this->skip_if_descendants = null;
        }
        return null;
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_file_path(): string
    {
        return $this->file_path;
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_file_name(): string
    {
        return $this->file_scanner->get_file_name();
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_root_file_path(): string
    {
        return $this->file_scanner->get_root_file_path();
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_root_file_name(): string
    {
        return $this->file_scanner->get_root_file_name();
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_aliases(): Aliases
    {
        return $this->aliases;
    }
    /**
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.ReturnTypeHint.MissingAnyTypeHint
     */
    #[Override]
    public function after_traverse(array $nodes)
    {
        $this->file_storage->type_aliases = $this->type_aliases;
        return null;
    }
}