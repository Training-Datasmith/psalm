<?php

declare (strict_types=1);
namespace Psalm\Internal\File_Manipulation;

use Php_Parser;
use Php_Parser\Node\Expr\Arrow_Function;
use Php_Parser\Node\Expr\Closure;
use Php_Parser\Node\Function_Like;
use Php_Parser\Node\Stmt\Class_Method;
use Php_Parser\Node\Stmt\Function_;
use Psalm\Doc_Comment;
use Psalm\File_Manipulation;
use Psalm\Internal\Analyzer\Comment_Analyzer;
use Psalm\Internal\Analyzer\Project_Analyzer;
use Psalm\Internal\Scanner\Parsed_Docblock;
use function array_key_exists;
use function array_reduce;
use function array_slice;
use function count;
use function implode;
use function is_string;
use function ltrim;
use function preg_match;
use function reset;
use function str_replace;
use function str_split;
use function strlen;
use function strpos;
use function strrpos;
use function substr;
/**
 * @internal
 */
final class Function_Docblock_Manipulator
{
    /**
     * Manipulators ordered by line number
     *
     * @var array<string, array<int, FunctionDocblockManipulator>>
     */
    private static array $manipulators = [];
    private readonly int $docblock_start;
    private readonly int $docblock_end;
    private readonly int $return_typehint_area_start;
    private ?int $return_typehint_colon_start = null;
    private ?int $return_typehint_start = null;
    private ?int $return_typehint_end = null;
    private ?string $new_php_return_type = null;
    private bool $return_type_is_php_compatible = false;
    private ?string $new_phpdoc_return_type = null;
    private ?string $new_psalm_return_type = null;
    /** @var array<string, string> */
    private array $new_php_param_types = [];
    /** @var array<string, string> */
    private array $new_phpdoc_param_types = [];
    /** @var array<string, string> */
    private array $new_psalm_param_types = [];
    private string $indentation;
    private ?string $return_type_description = null;
    /** @var array<string, int> */
    private array $param_offsets = [];
    /** @var array<string, array{int, int}> */
    private array $param_typehint_offsets = [];
    private bool $is_pure = false;
    /** @var list<string> */
    private array $throws_exceptions = [];
    /**
     * @param  Closure|Function_|ClassMethod|ArrowFunction $stmt
     */
    public static function get_for_function(Project_Analyzer $project_analyzer, string $file_path, Function_Like $stmt): Function_Docblock_Manipulator
    {
        return self::$manipulators[$file_path][$stmt->get_line()] ?? self::$manipulators[$file_path][$stmt->get_line()] = new self($file_path, $stmt, $project_analyzer);
    }
    private function __construct(string $file_path, private readonly Closure|Function_|Class_Method|Arrow_Function $stmt, Project_Analyzer $project_analyzer)
    {
        $docblock = $stmt->get_doc_comment();
        $this->docblock_start = $docblock ? $docblock->get_start_file_pos() : (int) $stmt->get_attribute('startFilePos');
        $this->docblock_end = $function_start = (int) $stmt->get_attribute('startFilePos');
        $function_end = (int) $stmt->get_attribute('endFilePos');
        $attributes = $stmt->get_attr_groups();
        foreach ($attributes as $attribute) {
            // if we have attribute groups, we need to consider that the function starts after them
            if ((int) $attribute->get_attribute('endFilePos') > $function_start) {
                $function_start = (int) $attribute->get_attribute('endFilePos');
            }
        }
        foreach ($stmt->params as $param) {
            if ($param->var instanceof Php_Parser\Node\Expr\Variable && is_string($param->var->name)) {
                $this->param_offsets[$param->var->name] = (int) $param->get_attribute('startFilePos');
                if ($param->type) {
                    $this->param_typehint_offsets[$param->var->name] = [(int) $param->type->get_attribute('startFilePos'), (int) $param->type->get_attribute('endFilePos') + 1];
                }
            }
        }
        $codebase = $project_analyzer->get_codebase();
        $file_contents = $codebase->get_file_contents($file_path);
        $last_arg_position = $stmt->params ? (int) $stmt->params[count($stmt->params) - 1]->get_attribute('endFilePos') + 1 : null;
        if ($stmt instanceof Closure && $stmt->uses) {
            $last_arg_position = (int) $stmt->uses[count($stmt->uses) - 1]->get_attribute('endFilePos') + 1;
        }
        $end_bracket_position = (int) strpos($file_contents, ')', $last_arg_position ?: $function_start);
        $this->return_typehint_area_start = $end_bracket_position + 1;
        $function_code = substr($file_contents, $function_start, $function_end);
        $function_code_after_bracket = substr($function_code, $end_bracket_position + 1 - $function_start);
        // do a little parsing here
        $chars = str_split($function_code_after_bracket);
        $in_single_line_comment = $in_multi_line_comment = false;
        for ($i = 0, $i_max = count($chars); $i < $i_max; ++$i) {
            $char = $chars[$i];
            switch ($char) {
                case "\n":
                    $in_single_line_comment = false;
                    continue 2;
                case ':':
                    if ($in_multi_line_comment) {
                        continue 2;
                    }
                    if ($in_single_line_comment) {
                        continue 2;
                    }
                    $this->return_typehint_colon_start = $i + $end_bracket_position + 1;
                    continue 2;
                case '/':
                    if ($in_multi_line_comment) {
                        continue 2;
                    }
                    if ($in_single_line_comment) {
                        continue 2;
                    }
                    if ($chars[$i + 1] === '*') {
                        $in_multi_line_comment = true;
                        ++$i;
                    }
                    if ($chars[$i + 1] === '/') {
                        $in_single_line_comment = true;
                        ++$i;
                    }
                    continue 2;
                case '*':
                    if ($in_single_line_comment) {
                        continue 2;
                    }
                    if ($chars[$i + 1] === '/') {
                        $in_multi_line_comment = false;
                        ++$i;
                    }
                    continue 2;
                case '{':
                case '=':
                    if ($in_multi_line_comment) {
                        continue 2;
                    }
                    if ($in_single_line_comment) {
                        continue 2;
                    }
                    break 2;
                case '?':
                    if ($in_multi_line_comment) {
                        continue 2;
                    }
                    if ($in_single_line_comment) {
                        continue 2;
                    }
                    $this->return_typehint_start = $i + $end_bracket_position + 1;
                    break;
            }
            if ($in_multi_line_comment) {
                continue;
            }
            if ($in_single_line_comment) {
                continue;
            }
            if ($chars[$i] === '\\' || preg_match('/\w/', $char)) {
                if ($this->return_typehint_start === null) {
                    $this->return_typehint_start = $i + $end_bracket_position + 1;
                }
                if ($chars[$i + 1] !== '\\' && !preg_match('/[\w]/', $chars[$i + 1])) {
                    $this->return_typehint_end = $i + $end_bracket_position + 2;
                    break;
                }
            }
        }
        $preceding_newline_pos = strrpos($file_contents, "\n", $this->docblock_end - strlen($file_contents));
        if ($preceding_newline_pos === false) {
            $this->indentation = '';
            return;
        }
        $first_line = substr($file_contents, $preceding_newline_pos + 1, $this->docblock_end - $preceding_newline_pos);
        $this->indentation = str_replace(ltrim($first_line), '', $first_line);
    }
    /**
     * Sets the new return type
     */
    public function set_return_type(?string $php_type, string $new_type, string $phpdoc_type, bool $is_php_compatible, ?string $description): void
    {
        $new_type = str_replace(['<mixed, mixed>', '<array-key, mixed>'], '', $new_type);
        $this->new_php_return_type = $php_type;
        $this->new_phpdoc_return_type = $phpdoc_type;
        $this->new_psalm_return_type = $new_type;
        $this->return_type_is_php_compatible = $is_php_compatible;
        $this->return_type_description = $description;
    }
    /**
     * Sets a new param type
     */
    public function set_param_type(string $param_name, ?string $php_type, string $new_type, string $phpdoc_type): void
    {
        $new_type = str_replace(['<mixed, mixed>', '<array-key, mixed>', '<never, never>'], '', $new_type);
        if ($php_type === 'static') {
            $php_type = '';
        }
        if ($php_type) {
            $this->new_php_param_types[$param_name] = $php_type;
        }
        if ($php_type !== $phpdoc_type) {
            $this->new_phpdoc_param_types[$param_name] = $phpdoc_type;
        }
        if ($php_type !== $new_type && $phpdoc_type !== $new_type) {
            $this->new_psalm_param_types[$param_name] = $new_type;
        }
    }
    /**
     * Gets a new docblock given the existing docblock, if one exists, and the updated return types
     * and/or parameters
     */
    private function get_docblock(): string
    {
        $docblock = $this->stmt->get_doc_comment();
        if ($docblock) {
            $parsed_docblock = Doc_Comment::parse_preserving_length($docblock);
        } else {
            $parsed_docblock = new Parsed_Docblock('', []);
        }
        $modified_docblock = false;
        foreach ($this->new_phpdoc_param_types as $param_name => $phpdoc_type) {
            $found_in_params = false;
            $new_param_block = $phpdoc_type . ' ' . '$' . $param_name;
            if (isset($parsed_docblock->tags['param'])) {
                foreach ($parsed_docblock->tags['param'] as &$param_block) {
                    $doc_parts = Comment_Analyzer::split_doc_line($param_block);
                    // If there's no type
                    if (($doc_parts[0] ?? null) === '$' . $param_name) {
                        // If the parameter has a description add that back
                        if (count($doc_parts) > 1) {
                            $new_param_block .= " " . implode(" ", array_slice($doc_parts, 1));
                        }
                        if ($param_block !== $new_param_block) {
                            $modified_docblock = true;
                        }
                        $param_block = $new_param_block;
                        $found_in_params = true;
                        break;
                    }
                    // If there is a type
                    if (($doc_parts[1] ?? null) === '$' . $param_name) {
                        // If the parameter has a description add that back
                        if (count($doc_parts) > 2) {
                            $new_param_block .= " " . implode(" ", array_slice($doc_parts, 2));
                        }
                        if ($param_block !== $new_param_block) {
                            $modified_docblock = true;
                        }
                        $param_block = $new_param_block;
                        $found_in_params = true;
                        break;
                    }
                }
                unset($param_block);
            }
            if (!$found_in_params) {
                $modified_docblock = true;
                $parsed_docblock->tags['param'][] = $new_param_block;
            }
        }
        foreach ($this->new_psalm_param_types as $param_name => $psalm_type) {
            $found_in_params = false;
            $new_param_block = $psalm_type . ' ' . '$' . $param_name;
            if (isset($parsed_docblock->tags['psalm-param'])) {
                foreach ($parsed_docblock->tags['psalm-param'] as &$param_block) {
                    $doc_parts = Comment_Analyzer::split_doc_line($param_block);
                    if (($doc_parts[1] ?? null) === '$' . $param_name) {
                        if ($param_block !== $new_param_block) {
                            $modified_docblock = true;
                        }
                        $param_block = $new_param_block;
                        $found_in_params = true;
                        break;
                    }
                }
                unset($param_block);
            }
            if (!$found_in_params) {
                $modified_docblock = true;
                $parsed_docblock->tags['psalm-param'][] = $new_param_block;
            }
        }
        $old_phpdoc_return_type = null;
        if (isset($parsed_docblock->tags['return'])) {
            $old_phpdoc_return_type = reset($parsed_docblock->tags['return']);
        }
        if ($this->is_pure) {
            $modified_docblock = true;
            $parsed_docblock->tags['psalm-pure'] = [''];
        }
        if (count($this->throws_exceptions) > 0) {
            $modified_docblock = true;
            $inferred_throws_clause = array_reduce($this->throws_exceptions, static fn(string $throws_clause, string $exception): string => $throws_clause === '' ? $exception : $throws_clause . '|' . $exception, '');
            if (array_key_exists('throws', $parsed_docblock->tags)) {
                $parsed_docblock->tags['throws'][] = $inferred_throws_clause;
            } else {
                $parsed_docblock->tags['throws'] = [$inferred_throws_clause];
            }
        }
        if ($this->new_phpdoc_return_type && $this->new_phpdoc_return_type !== $old_phpdoc_return_type) {
            $modified_docblock = true;
            if ($this->new_phpdoc_return_type !== $this->new_php_return_type || $this->return_type_description) {
                //only add the type if it's different than signature or if there's a description
                $parsed_docblock->tags['return'] = [$this->new_phpdoc_return_type . ($this->return_type_description ? ' ' . $this->return_type_description : '')];
            } else {
                unset($parsed_docblock->tags['return']);
            }
        }
        $old_psalm_return_type = null;
        if (isset($parsed_docblock->tags['psalm-return'])) {
            $old_psalm_return_type = reset($parsed_docblock->tags['psalm-return']);
        }
        if ($this->new_psalm_return_type && $this->new_phpdoc_return_type !== $this->new_psalm_return_type && $this->new_psalm_return_type !== $old_psalm_return_type) {
            $modified_docblock = true;
            $parsed_docblock->tags['psalm-return'] = [$this->new_psalm_return_type];
        }
        if (!$parsed_docblock->tags && !$parsed_docblock->description) {
            return '';
        }
        if (!$modified_docblock) {
            return $docblock . "\n" . $this->indentation;
        }
        return $parsed_docblock->render($this->indentation);
    }
    /**
     * @return array<int, FileManipulation>
     */
    public static function get_manipulations_for_file(string $file_path): array
    {
        if (!isset(self::$manipulators[$file_path])) {
            return [];
        }
        $file_manipulations = [];
        foreach (self::$manipulators[$file_path] as $manipulator) {
            if ($manipulator->new_php_return_type) {
                if ($manipulator->return_typehint_start && $manipulator->return_typehint_end) {
                    $file_manipulations[$manipulator->return_typehint_start] = new File_Manipulation($manipulator->return_typehint_start, $manipulator->return_typehint_end, $manipulator->new_php_return_type);
                } else {
                    $file_manipulations[$manipulator->return_typehint_area_start] = new File_Manipulation($manipulator->return_typehint_area_start, $manipulator->return_typehint_area_start, ': ' . $manipulator->new_php_return_type);
                }
            } elseif ($manipulator->new_php_return_type === '' && $manipulator->return_typehint_colon_start && $manipulator->new_phpdoc_return_type && $manipulator->return_typehint_start && $manipulator->return_typehint_end) {
                $file_manipulations[$manipulator->return_typehint_start] = new File_Manipulation($manipulator->return_typehint_colon_start, $manipulator->return_typehint_end, '');
            }
            if (!$manipulator->new_php_return_type || !$manipulator->return_type_is_php_compatible || $manipulator->docblock_start !== $manipulator->docblock_end || $manipulator->is_pure) {
                $file_manipulations[$manipulator->docblock_start] = new File_Manipulation($manipulator->docblock_start, $manipulator->docblock_end, $manipulator->get_docblock());
            }
            foreach ($manipulator->new_php_param_types as $param_name => $new_php_param_type) {
                if (!isset($manipulator->param_offsets[$param_name])) {
                    continue;
                }
                $param_offset = $manipulator->param_offsets[$param_name];
                $typehint_offsets = $manipulator->param_typehint_offsets[$param_name] ?? null;
                if ($new_php_param_type) {
                    if ($typehint_offsets) {
                        $file_manipulations[$typehint_offsets[0]] = new File_Manipulation($typehint_offsets[0], $typehint_offsets[1], $new_php_param_type);
                    } else {
                        $file_manipulations[$param_offset] = new File_Manipulation($param_offset, $param_offset, $new_php_param_type . ' ');
                    }
                } elseif ($new_php_param_type === '' && $typehint_offsets) {
                    $file_manipulations[$typehint_offsets[0]] = new File_Manipulation($typehint_offsets[0], $param_offset, '');
                }
            }
        }
        return $file_manipulations;
    }
    public function make_pure(): void
    {
        $this->is_pure = true;
    }
    /**
     * @param list<string> $exceptions
     */
    public function add_throws_docblock(array $exceptions): void
    {
        $this->throws_exceptions = $exceptions;
    }
    public static function clear_cache(): void
    {
        self::$manipulators = [];
    }
    /**
     * @param array<string, array<int, FunctionDocblockManipulator>> $manipulators
     */
    public static function add_manipulators(array $manipulators): void
    {
        self::$manipulators = [...$manipulators, ...self::$manipulators];
    }
    /**
     * @return array<string, array<int, FunctionDocblockManipulator>>
     */
    public static function get_manipulators(): array
    {
        return self::$manipulators;
    }
}