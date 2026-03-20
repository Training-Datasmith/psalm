<?php

declare (strict_types=1);
namespace Psalm\Internal\Php_Visitor\Reflector;

use Exception;
use Php_Parser\Comment\Doc;
use Php_Parser\Node;
use Php_Parser\Node\Stmt\Class_Method;
use Php_Parser\Node\Stmt\Class_;
use Psalm\Aliases;
use Psalm\Doc_Comment;
use Psalm\Exception\Docblock_Parse_Exception;
use Psalm\Exception\Incorrect_Docblock_Exception;
use Psalm\Exception\Type_Parse_Tree_Exception;
use Psalm\Internal\Analyzer\Comment_Analyzer;
use Psalm\Internal\Analyzer\Project_Analyzer;
use Psalm\Internal\Provider\Statements_Provider;
use Psalm\Internal\Scanner\Class_Like_Docblock_Comment;
use Psalm\Internal\Scanner\Docblock_Parser;
use Psalm\Internal\Type\Parse_Tree\Method_Param_Tree;
use Psalm\Internal\Type\Parse_Tree\Method_Tree;
use Psalm\Internal\Type\Parse_Tree\Method_With_Return_Type_Tree;
use Psalm\Internal\Type\Parse_Tree_Creator;
use Psalm\Internal\Type\Type_Parser;
use Psalm\Internal\Type\Type_Tokenizer;
use function array_key_first;
use function array_shift;
use function count;
use function explode;
use function implode;
use function in_array;
use function preg_last_error_msg;
use function preg_match;
use function preg_replace;
use function preg_split;
use function reset;
use function str_contains;
use function str_replace;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function substr_count;
use function trim;
use const PREG_OFFSET_CAPTURE;
/**
 * @internal
 */
final class Class_Like_Docblock_Parser
{
    /**
     * @throws DocblockParseException if there was a problem parsing the docblock
     */
    public static function parse(Node $node, Doc $comment, Aliases $aliases): Class_Like_Docblock_Comment
    {
        $parsed_docblock = Doc_Comment::parse_preserving_length($comment);
        $codebase = Project_Analyzer::get_instance()->get_codebase();
        $info = new Class_Like_Docblock_Comment();
        $templates = [];
        if (isset($parsed_docblock->combined_tags['template'])) {
            foreach ($parsed_docblock->combined_tags['template'] as $offset => $template_line) {
                $template_type = preg_split('/[\s]+/', Comment_Analyzer::sanitize_docblock_type($template_line));
                if ($template_type === false) {
                    throw new Incorrect_Docblock_Exception('Invalid @template tag: ' . preg_last_error_msg());
                }
                $template_name = array_shift($template_type);
                if (!$template_name) {
                    throw new Incorrect_Docblock_Exception('Empty @template tag');
                }
                $source_prefix = 'none';
                if (isset($parsed_docblock->tags['psalm-template'][$offset])) {
                    $source_prefix = 'psalm';
                } elseif (isset($parsed_docblock->tags['phpstan-template'][$offset])) {
                    $source_prefix = 'phpstan';
                }
                if (count($template_type) > 1 && in_array(strtolower($template_type[0]), ['as', 'super', 'of'], true)) {
                    $template_modifier = strtolower(array_shift($template_type));
                    $templates[$template_name][$source_prefix] = [$template_name, $template_modifier, implode(' ', $template_type), false, $offset - $comment->get_start_file_pos()];
                } else {
                    $templates[$template_name][$source_prefix] = [$template_name, null, null, false, $offset - $comment->get_start_file_pos()];
                }
            }
        }
        if (isset($parsed_docblock->combined_tags['template-covariant'])) {
            foreach ($parsed_docblock->combined_tags['template-covariant'] as $offset => $template_line) {
                $template_type = preg_split('/[\s]+/', Comment_Analyzer::sanitize_docblock_type($template_line));
                if ($template_type === false) {
                    throw new Incorrect_Docblock_Exception('Invalid @template-covariant tag: ' . preg_last_error_msg());
                }
                $template_name = array_shift($template_type);
                if (!$template_name) {
                    throw new Incorrect_Docblock_Exception('Empty @template-covariant tag');
                }
                $source_prefix = 'none';
                if (isset($parsed_docblock->tags['psalm-template-covariant'][$offset])) {
                    $source_prefix = 'psalm';
                } elseif (isset($parsed_docblock->tags['phpstan-template-covariant'][$offset])) {
                    $source_prefix = 'phpstan';
                }
                if (count($template_type) > 1 && in_array(strtolower($template_type[0]), ['as', 'super', 'of'], true)) {
                    $template_modifier = strtolower(array_shift($template_type));
                    $templates[$template_name][$source_prefix] = [$template_name, $template_modifier, implode(' ', $template_type), true, $offset - $comment->get_start_file_pos()];
                } else {
                    $templates[$template_name][$source_prefix] = [$template_name, null, null, true, $offset - $comment->get_start_file_pos()];
                }
            }
        }
        foreach ($templates as $template_entries) {
            foreach (['psalm', 'phpstan', 'none'] as $source_prefix) {
                if (isset($template_entries[$source_prefix])) {
                    $info->templates[] = $template_entries[$source_prefix];
                    break;
                }
            }
        }
        if (isset($parsed_docblock->combined_tags['extends'])) {
            foreach ($parsed_docblock->combined_tags['extends'] as $template_line) {
                $doc_line_parts = Comment_Analyzer::split_doc_line($template_line);
                $doc_line_parts[0] = Comment_Analyzer::sanitize_docblock_type($doc_line_parts[0]);
                $info->template_extends[] = $doc_line_parts[0];
            }
        }
        if (isset($parsed_docblock->tags['psalm-require-extends']) && count($extension_requirements = $parsed_docblock->tags['psalm-require-extends']) > 0) {
            $info->extension_requirement = Comment_Analyzer::sanitize_docblock_type($extension_requirements[array_key_first($extension_requirements)]);
        }
        if (isset($parsed_docblock->tags['psalm-require-implements'])) {
            foreach ($parsed_docblock->tags['psalm-require-implements'] as $implementation_requirement) {
                $info->implementation_requirements[] = Comment_Analyzer::sanitize_docblock_type($implementation_requirement);
            }
        }
        if (isset($parsed_docblock->combined_tags['implements'])) {
            foreach ($parsed_docblock->combined_tags['implements'] as $template_line) {
                $doc_line_parts = Comment_Analyzer::split_doc_line($template_line);
                $doc_line_parts[0] = Comment_Analyzer::sanitize_docblock_type($doc_line_parts[0]);
                $info->template_implements[] = $doc_line_parts[0];
            }
        }
        if (isset($parsed_docblock->tags['psalm-yield'])) {
            $yield = (string) reset($parsed_docblock->tags['psalm-yield']);
            $info->yield = Comment_Analyzer::sanitize_docblock_type($yield);
        }
        if (isset($parsed_docblock->tags['deprecated'])) {
            $info->deprecated = true;
        }
        if (isset($parsed_docblock->tags['internal'])) {
            $info->internal = true;
        }
        if (isset($parsed_docblock->tags['final'])) {
            $info->final = true;
        }
        if (isset($parsed_docblock->tags['psalm-consistent-constructor'])) {
            $info->consistent_constructor = true;
        }
        if (isset($parsed_docblock->tags['psalm-consistent-templates'])) {
            $info->consistent_templates = true;
        }
        if (count($info->psalm_internal = Docblock_Parser::handle_psalm_internal($parsed_docblock)) !== 0) {
            $info->internal = true;
        }
        if (isset($parsed_docblock->tags['mixin'])) {
            foreach ($parsed_docblock->tags['mixin'] as $raw_mixin) {
                $mixin = trim($raw_mixin);
                $doc_line_parts = Comment_Analyzer::split_doc_line($mixin);
                $mixin = $doc_line_parts[0];
                if ($mixin) {
                    $info->mixins[] = $mixin;
                } else {
                    throw new Docblock_Parse_Exception('@mixin annotation used without specifying class');
                }
            }
        }
        foreach (['', 'psalm-'] as $prefix) {
            if (isset($parsed_docblock->tags[$prefix . 'seal-properties'])) {
                $info->sealed_properties = true;
            }
            if (isset($parsed_docblock->tags[$prefix . 'no-seal-properties'])) {
                $info->sealed_properties = false;
            }
            if (isset($parsed_docblock->tags[$prefix . 'seal-methods'])) {
                $info->sealed_methods = true;
            }
            if (isset($parsed_docblock->tags[$prefix . 'no-seal-methods'])) {
                $info->sealed_methods = false;
            }
        }
        if (isset($parsed_docblock->tags['psalm-inheritors'])) {
            foreach ($parsed_docblock->tags['psalm-inheritors'] as $template_line) {
                $doc_line_parts = Comment_Analyzer::split_doc_line($template_line);
                $doc_line_parts[0] = Comment_Analyzer::sanitize_docblock_type($doc_line_parts[0]);
                $info->inheritors = $doc_line_parts[0];
            }
        }
        if (isset($parsed_docblock->tags['psalm-immutable']) || isset($parsed_docblock->tags['psalm-mutation-free'])) {
            $info->mutation_free = true;
            $info->external_mutation_free = true;
            $info->taint_specialize = true;
        }
        if (isset($parsed_docblock->tags['psalm-external-mutation-free'])) {
            $info->external_mutation_free = true;
        }
        if (isset($parsed_docblock->tags['psalm-taint-specialize'])) {
            $info->taint_specialize = true;
        }
        if (isset($parsed_docblock->tags['psalm-override-property-visibility'])) {
            $info->override_property_visibility = true;
        }
        if (isset($parsed_docblock->tags['psalm-override-method-visibility'])) {
            $info->override_method_visibility = true;
        }
        if (isset($parsed_docblock->tags['psalm-suppress'])) {
            foreach ($parsed_docblock->tags['psalm-suppress'] as $offset => $suppress_entry) {
                foreach (Doc_Comment::parse_suppress_list($suppress_entry) as $issue_offset => $suppressed_issue) {
                    $info->suppressed_issues[$issue_offset + $offset] = $suppressed_issue;
                }
            }
        }
        $imported_types = ($parsed_docblock->tags['phpstan-import-type'] ?? []) + ($parsed_docblock->tags['psalm-import-type'] ?? []);
        foreach ($imported_types as $offset => $imported_type_entry) {
            $info->imported_types[] = ['line_number' => $comment->get_start_line() + substr_count($comment->get_text(), "\n", 0, $offset - $comment->get_start_file_pos()), 'start_offset' => $offset, 'end_offset' => $offset + strlen($imported_type_entry), 'parts' => Comment_Analyzer::split_doc_line($imported_type_entry)];
        }
        if (isset($parsed_docblock->combined_tags['method'])) {
            if ($info->sealed_methods === null) {
                $info->sealed_methods = true;
            }
            foreach ($parsed_docblock->combined_tags['method'] as $offset => $method_entry) {
                $method_entry = (string) preg_replace('/[ \t]+/', ' ', trim($method_entry));
                $docblock_lines = [];
                $is_static = false;
                $has_return = false;
                $doc_line_parts = Comment_Analyzer::split_doc_line($method_entry);
                if (count($doc_line_parts) > 2 && $doc_line_parts[0] === 'static' && !strpos($doc_line_parts[1], '(')) {
                    $is_static = true;
                    array_shift($doc_line_parts);
                    $method_entry = implode(' ', $doc_line_parts);
                    $doc_line_parts = Comment_Analyzer::split_doc_line($method_entry);
                }
                if (!preg_match('/^([a-z_A-Z][a-z_0-9A-Z]+) *\(/', $method_entry, $matches)) {
                    if (count($doc_line_parts) > 1) {
                        $docblock_lines[] = '@return ' . array_shift($doc_line_parts);
                        $has_return = true;
                        $method_entry = implode(' ', $doc_line_parts);
                    }
                }
                $method_entry = trim((string) preg_replace('/\/\/.*/', '', $method_entry));
                $method_entry = (string) preg_replace('/array\(([0-9a-zA-Z_\'\" ]+,)*([0-9a-zA-Z_\'\" ]+)\)/', '[]', $method_entry);
                $end_of_method_regex = '/(?<!array\()\) ?(\: ?(\??[\\\\a-zA-Z0-9_]+))?/';
                if (preg_match($end_of_method_regex, $method_entry, $matches, PREG_OFFSET_CAPTURE)) {
                    $method_entry = substr($method_entry, 0, $matches[0][1] + strlen($matches[0][0]));
                }
                $method_entry = str_replace([', ', '( '], [',', '('], $method_entry);
                $method_entry = (string) preg_replace('/ (?!(\$|\.\.\.|&))/', '', trim($method_entry));
                // replace array bracket contents
                $method_entry = (string) preg_replace('/\[([0-9a-zA-Z_\'\" ]+,)*([0-9a-zA-Z_\'\" ]+)\]/', '[]', $method_entry);
                if (!$method_entry) {
                    throw new Docblock_Parse_Exception('No @method entry specified');
                }
                try {
                    $parse_tree_creator = new Parse_Tree_Creator(Type_Tokenizer::get_fully_qualified_tokens($method_entry, $aliases));
                    $method_tree = $parse_tree_creator->create();
                } catch (Type_Parse_Tree_Exception $e) {
                    throw new Docblock_Parse_Exception($method_entry . ' is not a valid method: ' . $e->get_message(), 0, $e);
                }
                if (!$method_tree instanceof Method_With_Return_Type_Tree && !$method_tree instanceof Method_Tree) {
                    throw new Docblock_Parse_Exception($method_entry . ' is not a valid method');
                }
                if ($method_tree instanceof Method_With_Return_Type_Tree) {
                    if (!$has_return) {
                        $docblock_lines[] = '@return ' . Type_Parser::get_type_from_tree($method_tree->children[1], $codebase)->to_namespaced_string($aliases->namespace, $aliases->uses, null, false);
                    }
                    $method_tree = $method_tree->children[0];
                }
                if (!$method_tree instanceof Method_Tree) {
                    throw new Docblock_Parse_Exception($method_entry . ' is not a valid method');
                }
                $args = [];
                foreach ($method_tree->children as $method_tree_child) {
                    if (!$method_tree_child instanceof Method_Param_Tree) {
                        throw new Docblock_Parse_Exception($method_entry . ' is not a valid method');
                    }
                    $args[] = ($method_tree_child->byref ? '&' : '') . ($method_tree_child->variadic ? '...' : '') . $method_tree_child->name . ($method_tree_child->default != '' ? ' = ' . $method_tree_child->default : '');
                    if ($method_tree_child->children) {
                        try {
                            $param_type = Type_Parser::get_type_from_tree($method_tree_child->children[0], $codebase);
                        } catch (Exception $e) {
                            throw new Docblock_Parse_Exception('Badly-formatted @method string ' . $method_entry . ' - ' . $e);
                        }
                        $param_type_string = $param_type->to_namespaced_string('\\', [], null, false);
                        $docblock_lines[] = '@param ' . $param_type_string . ' ' . ($method_tree_child->variadic ? '...' : '') . $method_tree_child->name;
                    }
                }
                $function_string = 'function ' . $method_tree->value . '(' . implode(', ', $args) . ')';
                if ($is_static) {
                    $function_string = 'static ' . $function_string;
                }
                $function_docblock = $docblock_lines ? "/**\n * " . implode("\n * ", $docblock_lines) . "\n*/\n" : "";
                $php_string = '<?php class A { ' . $function_docblock . ' public ' . $function_string . '{} }';
                try {
                    $has_errors = false;
                    $statements = Statements_Provider::parse_statements($php_string, $codebase->analysis_php_version_id, $has_errors);
                } catch (Exception) {
                    throw new Docblock_Parse_Exception('Badly-formatted @method string ' . $method_entry);
                }
                if (!$statements || !$statements[0] instanceof Class_ || !isset($statements[0]->stmts[0]) || !$statements[0]->stmts[0] instanceof Class_Method) {
                    throw new Docblock_Parse_Exception('Badly-formatted @method string ' . $method_entry);
                }
                /** @var Doc */
                $node_doc_comment = $node->get_doc_comment();
                $method_offset = self::get_method_offset($comment, $method_entry);
                $statements[0]->stmts[0]->set_attribute('startLine', $node_doc_comment->get_start_line() + $method_offset);
                $statements[0]->stmts[0]->set_attribute('startFilePos', $node_doc_comment->get_start_file_pos());
                $statements[0]->stmts[0]->set_attribute('endFilePos', $node->get_attribute('startFilePos'));
                if ($doc_comment = $statements[0]->stmts[0]->get_doc_comment()) {
                    $statements[0]->stmts[0]->set_doc_comment(new Doc($doc_comment->get_text(), $comment->get_start_line() + substr_count($comment->get_text(), "\n", 0, $offset - $comment->get_start_file_pos()), $node_doc_comment->get_start_file_pos()));
                }
                $info->methods[] = $statements[0]->stmts[0];
            }
        }
        if (isset($parsed_docblock->tags['psalm-stub-override'])) {
            $info->stub_override = true;
        }
        if ($parsed_docblock->description) {
            $info->description = $parsed_docblock->description;
        }
        $info->public_api = isset($parsed_docblock->tags['psalm-api']) || isset($parsed_docblock->tags['api']);
        if (isset($parsed_docblock->tags['property']) && $codebase->config->docblock_property_types_seal_properties && $info->sealed_properties === null) {
            $info->sealed_properties = true;
        }
        self::add_magic_property_to_info($comment, $info, $parsed_docblock->tags, 'property');
        self::add_magic_property_to_info($comment, $info, $parsed_docblock->tags, 'psalm-property');
        self::add_magic_property_to_info($comment, $info, $parsed_docblock->tags, 'property-read');
        self::add_magic_property_to_info($comment, $info, $parsed_docblock->tags, 'psalm-property-read');
        self::add_magic_property_to_info($comment, $info, $parsed_docblock->tags, 'property-write');
        self::add_magic_property_to_info($comment, $info, $parsed_docblock->tags, 'psalm-property-write');
        return $info;
    }
    /**
     * @param array<string, array<int, string>> $specials
     * @param 'property'|'psalm-property'|'property-read'|
     *     'psalm-property-read'|'property-write'|'psalm-property-write' $property_tag
     * @throws DocblockParseException
     */
    private static function add_magic_property_to_info(Doc $comment, Class_Like_Docblock_Comment $info, array $specials, string $property_tag): void
    {
        $magic_property_comments = $specials[$property_tag] ?? [];
        foreach ($magic_property_comments as $offset => $property) {
            $line_parts = Comment_Analyzer::split_doc_line($property);
            if (count($line_parts) === 1 && isset($line_parts[0][0]) && $line_parts[0][0] === '$') {
                continue;
            }
            if (count($line_parts) > 1) {
                if (preg_match('/^&?\$[A-Za-z0-9_]+,?$/', $line_parts[1]) && $line_parts[0][0] !== '{') {
                    $line_parts[1] = str_replace('&', '', $line_parts[1]);
                    $line_parts[1] = (string) preg_replace('/,$/', '', $line_parts[1], 1);
                    $end = $offset + strlen($line_parts[0]);
                    $line_parts[0] = Comment_Analyzer::sanitize_docblock_type($line_parts[0]);
                    if ($line_parts[0] === '' || $line_parts[0][0] === '$' && !preg_match('/^\$this(\||$)/', $line_parts[0])) {
                        throw new Incorrect_Docblock_Exception('Misplaced variable');
                    }
                    $name = trim($line_parts[1]);
                    if (!preg_match('/^\$([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)$/', $name)) {
                        throw new Docblock_Parse_Exception('Badly-formatted @property name');
                    }
                    $info->properties[] = ['name' => $name, 'type' => $line_parts[0], 'line_number' => $comment->get_start_line() + substr_count($comment->get_text(), "\n", 0, $offset - $comment->get_start_file_pos()), 'tag' => $property_tag, 'start' => $offset, 'end' => $end];
                }
            } else {
                throw new Docblock_Parse_Exception('Badly-formatted @property');
            }
        }
    }
    private static function get_method_offset(Doc $comment, string $method_entry): int
    {
        $lines = explode("\n", $comment->get_text());
        $method_offset = 0;
        foreach ($lines as $i => $line) {
            if (str_contains($line, $method_entry)) {
                $method_offset = $i;
                break;
            }
        }
        return $method_offset;
    }
}