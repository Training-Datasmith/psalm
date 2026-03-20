<?php

declare (strict_types=1);
namespace Psalm\Internal\Php_Visitor\Reflector;

use AssertionError;
use Php_Parser;
use Psalm\Code_Location;
use Psalm\Doc_Comment;
use Psalm\Exception\Docblock_Parse_Exception;
use Psalm\Exception\Incorrect_Docblock_Exception;
use Psalm\Internal\Analyzer\Comment_Analyzer;
use Psalm\Internal\Scanner\Docblock_Parser;
use Psalm\Internal\Scanner\Function_Docblock_Comment;
use Psalm\Internal\Scanner\Parsed_Docblock;
use Psalm\Issue\Invalid_Docblock;
use Psalm\Issue_Buffer;
use Psalm\Type\Taint_Kind_Group;
use function array_keys;
use function array_shift;
use function array_unique;
use function count;
use function explode;
use function implode;
use function in_array;
use function pathinfo;
use function preg_last_error_msg;
use function preg_match;
use function preg_replace;
use function preg_split;
use function reset;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function stripos;
use function strlen;
use function strtolower;
use function substr;
use function substr_count;
use function trim;
use const PATHINFO_EXTENSION;
/**
 * @internal
 */
final class Function_Like_Docblock_Parser
{
    /**
     * @throws DocblockParseException if there was a problem parsing the docblock
     */
    public static function parse(Php_Parser\Comment\Doc $comment, Code_Location $code_location, string $cased_function_id): Function_Docblock_Comment
    {
        // invalid @psalm annotations are already reported by the StatementsAnalyzer
        $parsed_docblock = Doc_Comment::parse_preserving_length($comment, true);
        $comment_text = $comment->get_text();
        $info = new Function_Docblock_Comment();
        self::check_duplicated_tags($parsed_docblock);
        self::check_unexpected_tags($parsed_docblock, $info, $comment);
        if (isset($parsed_docblock->combined_tags['return'])) {
            self::extract_return_type($comment, $parsed_docblock->combined_tags['return'], $info, $code_location, $cased_function_id);
        }
        if (isset($parsed_docblock->combined_tags['param'])) {
            foreach ($parsed_docblock->combined_tags['param'] as $offset => $param) {
                $line_parts = Comment_Analyzer::split_doc_line($param);
                if (count($line_parts) === 1 && isset($line_parts[0][0]) && $line_parts[0][0] === '$') {
                    continue;
                }
                if (count($line_parts) > 1) {
                    if (preg_match('/^&?(\.\.\.)?&?\$[A-Za-z0-9_]+,?$/', $line_parts[1]) && ($line_parts[0] === '' || $line_parts[0][0] !== '{')) {
                        $line_parts[1] = str_replace('&', '', $line_parts[1]);
                        $line_parts[1] = (string) preg_replace('/,$/', '', $line_parts[1], 1);
                        $end = $offset + strlen($line_parts[0]);
                        $line_parts[0] = Comment_Analyzer::sanitize_docblock_type($line_parts[0]);
                        if ($line_parts[0] === '' || $line_parts[0][0] === '$' && !preg_match('/^\$this(\||$)/', $line_parts[0])) {
                            throw new Incorrect_Docblock_Exception('Misplaced variable');
                        }
                        $info_param = ['name' => trim($line_parts[1]), 'type' => $line_parts[0], 'line_number' => $comment->get_start_line() + substr_count($comment_text, "\n", 0, $offset - $comment->get_start_file_pos()), 'start' => $offset, 'end' => $end];
                        if (isset($line_parts[1]) && isset($line_parts[2])) {
                            $description = substr($param, strlen($line_parts[0]) + strlen($line_parts[1]) + 2);
                            $info_param['description'] = trim($description);
                            // Handle multiline description.
                            $info_param['description'] = (string) preg_replace('/\n \*\s+/um', ' ', $info_param['description']);
                        }
                        $info->params[] = $info_param;
                    }
                } else {
                    Issue_Buffer::maybe_add(new Invalid_Docblock('Badly-formatted @param in docblock for ' . $cased_function_id, $code_location));
                }
            }
        }
        if (isset($parsed_docblock->combined_tags['param-out'])) {
            foreach ($parsed_docblock->combined_tags['param-out'] as $offset => $param) {
                $line_parts = Comment_Analyzer::split_doc_line($param);
                if (count($line_parts) === 1 && isset($line_parts[0][0]) && $line_parts[0][0] === '$') {
                    continue;
                }
                if (count($line_parts) > 1) {
                    if (!preg_match('/\[[^\]]+\]/', $line_parts[0]) && preg_match('/^(\.\.\.)?&?\$[A-Za-z0-9_]+,?$/', $line_parts[1]) && $line_parts[0][0] !== '{') {
                        if ($line_parts[1][0] === '&') {
                            $line_parts[1] = substr($line_parts[1], 1);
                        }
                        $line_parts[0] = Comment_Analyzer::sanitize_docblock_type($line_parts[0]);
                        if ($line_parts[0] === '' || $line_parts[0][0] === '$' && !preg_match('/^\$this(\||$)/', $line_parts[0])) {
                            throw new Incorrect_Docblock_Exception('Misplaced variable');
                        }
                        $line_parts[1] = (string) preg_replace('/,$/', '', $line_parts[1], 1);
                        $info->params_out[] = ['name' => trim($line_parts[1]), 'type' => str_replace("\n", '', $line_parts[0]), 'line_number' => $comment->get_start_line() + substr_count($comment_text, "\n", 0, $offset - $comment->get_start_file_pos())];
                    }
                } else {
                    Issue_Buffer::maybe_add(new Invalid_Docblock('Badly-formatted @param in docblock for ' . $cased_function_id, $code_location));
                }
            }
        }
        foreach (['psalm-self-out', 'psalm-this-out', 'phpstan-self-out', 'phpstan-this-out'] as $alias) {
            if (isset($parsed_docblock->tags[$alias])) {
                foreach ($parsed_docblock->tags[$alias] as $offset => $param) {
                    $line_parts = Comment_Analyzer::split_doc_line($param);
                    if (count($line_parts) > 0) {
                        $line_parts[0] = Comment_Analyzer::sanitize_docblock_type($line_parts[0]);
                        $info->self_out = ['type' => $line_parts[0], 'line_number' => $comment->get_start_line() + substr_count($comment_text, "\n", 0, $offset - $comment->get_start_file_pos())];
                    }
                }
                break;
            }
        }
        if (isset($parsed_docblock->tags['psalm-flow'])) {
            foreach ($parsed_docblock->tags['psalm-flow'] as $param) {
                $info->flows[] = trim($param);
            }
        }
        if (isset($parsed_docblock->tags['psalm-if-this-is'])) {
            foreach ($parsed_docblock->tags['psalm-if-this-is'] as $offset => $param) {
                $line_parts = Comment_Analyzer::split_doc_line($param);
                $line_parts[0] = Comment_Analyzer::sanitize_docblock_type($line_parts[0]);
                $info->if_this_is = ['type' => $line_parts[0], 'line_number' => $comment->get_start_line() + substr_count($comment->get_text(), "\n", 0, $offset - $comment->get_start_file_pos())];
            }
        }
        if (isset($parsed_docblock->tags['psalm-taint-sink'])) {
            foreach ($parsed_docblock->tags['psalm-taint-sink'] as $param) {
                $param_parts = preg_split('/\s+/', trim($param));
                if ($param_parts === false) {
                    throw new AssertionError(preg_last_error_msg());
                }
                if (count($param_parts) >= 2) {
                    $info->taint_sink_params[] = ['name' => $param_parts[1], 'taint' => $param_parts[0]];
                } else {
                    Issue_Buffer::maybe_add(new Invalid_Docblock('@psalm-taint-sink expects 2 arguments', $code_location));
                }
            }
        }
        // support for MediaWiki taint plugin
        if (isset($parsed_docblock->tags['param-taint'])) {
            foreach ($parsed_docblock->tags['param-taint'] as $param) {
                $param_parts = preg_split('/\s+/', trim($param));
                if ($param_parts === false) {
                    throw new AssertionError(preg_last_error_msg());
                }
                if (count($param_parts) === 2) {
                    $taint_type = $param_parts[1];
                    if (str_starts_with($taint_type, 'exec_')) {
                        $taint_type = substr($taint_type, 5);
                        if ($taint_type === 'tainted') {
                            $taint_type = Taint_Kind_Group::GROUP_INPUT;
                        }
                        if ($taint_type === 'misc') {
                            // @todo `text` is semantically not defined in `TaintKind`, maybe drop it
                            $taint_type = 'text';
                        }
                        $info->taint_sink_params[] = ['name' => $param_parts[0], 'taint' => $taint_type];
                    }
                }
            }
        }
        if (isset($parsed_docblock->tags['psalm-taint-source'])) {
            foreach ($parsed_docblock->tags['psalm-taint-source'] as $param) {
                $param_parts = preg_split('/\s+/', trim($param));
                if ($param_parts === false) {
                    throw new AssertionError(preg_last_error_msg());
                }
                if ($param_parts[0]) {
                    $info->taint_source_types[] = $param_parts[0];
                } else {
                    Issue_Buffer::maybe_add(new Invalid_Docblock('@psalm-taint-source expects 1 argument', $code_location));
                }
            }
        } elseif (isset($parsed_docblock->tags['return-taint'])) {
            // support for MediaWiki taint plugin
            foreach ($parsed_docblock->tags['return-taint'] as $param) {
                $param_parts = preg_split('/\s+/', trim($param));
                if ($param_parts === false) {
                    throw new AssertionError(preg_last_error_msg());
                }
                if ($param_parts[0]) {
                    if ($param_parts[0] === 'tainted') {
                        $param_parts[0] = Taint_Kind_Group::GROUP_INPUT;
                    }
                    if ($param_parts[0] === 'misc') {
                        // @todo `text` is semantically not defined in `TaintKind`, maybe drop it
                        $param_parts[0] = 'text';
                    }
                    if ($param_parts[0] !== 'none') {
                        $info->taint_source_types[] = $param_parts[0];
                    }
                }
            }
        }
        if (isset($parsed_docblock->tags['psalm-taint-unescape'])) {
            foreach ($parsed_docblock->tags['psalm-taint-unescape'] as $param) {
                $param = trim($param);
                if ($param === '') {
                    Issue_Buffer::maybe_add(new Invalid_Docblock('@psalm-taint-unescape expects 1 argument', $code_location));
                } else {
                    $info->added_taints[] = $param;
                }
            }
        }
        if (isset($parsed_docblock->tags['psalm-taint-escape'])) {
            foreach ($parsed_docblock->tags['psalm-taint-escape'] as $param) {
                $param = trim($param);
                if ($param === '') {
                    Issue_Buffer::maybe_add(new Invalid_Docblock('@psalm-taint-escape expects 1 argument', $code_location));
                } elseif ($param[0] === '(') {
                    $line_parts = Comment_Analyzer::split_doc_line($param);
                    $info->removed_taints[] = Comment_Analyzer::sanitize_docblock_type($line_parts[0]);
                } else {
                    $info->removed_taints[] = explode(' ', $param)[0];
                }
            }
        }
        if (isset($parsed_docblock->tags['psalm-assert-untainted'])) {
            foreach ($parsed_docblock->tags['psalm-assert-untainted'] as $param) {
                $param = trim($param);
                $info->assert_untainted_params[] = ['name' => $param];
            }
        }
        if (isset($parsed_docblock->tags['psalm-taint-specialize'])) {
            $info->specialize_call = true;
        }
        if (isset($parsed_docblock->tags['global'])) {
            foreach ($parsed_docblock->tags['global'] as $offset => $global) {
                $line_parts = Comment_Analyzer::split_doc_line($global);
                if (count($line_parts) === 1 && isset($line_parts[0][0]) && $line_parts[0][0] === '$') {
                    continue;
                }
                if (count($line_parts) > 1) {
                    if (!preg_match('/\[[^\]]+\]/', $line_parts[0]) && preg_match('/^(\.\.\.)?&?\$[A-Za-z0-9_]+,?$/', $line_parts[1]) && $line_parts[0][0] !== '{') {
                        if ($line_parts[1][0] === '&') {
                            $line_parts[1] = substr($line_parts[1], 1);
                        }
                        if ($line_parts[0][0] === '$' && !preg_match('/^\$this(\||$)/', $line_parts[0])) {
                            throw new Incorrect_Docblock_Exception('Misplaced variable');
                        }
                        $line_parts[1] = (string) preg_replace('/,$/', '', $line_parts[1], 1);
                        $info->globals[] = ['name' => $line_parts[1], 'type' => $line_parts[0], 'line_number' => $comment->get_start_line() + substr_count($comment_text, "\n", 0, $offset - $comment->get_start_file_pos())];
                    }
                } else {
                    Issue_Buffer::maybe_add(new Invalid_Docblock('Badly-formatted @param in docblock for ' . $cased_function_id, $code_location));
                }
            }
        }
        if (isset($parsed_docblock->tags['since'])) {
            $since = trim((string) reset($parsed_docblock->tags['since']));
            // only for phpstub files or @since 8.0.0 PHP
            // since @since is commonly used with the project version, not the PHP version
            // https://docs.phpdoc.org/3.0/guide/references/phpdoc/tags/since.html
            // https://github.com/vimeo/psalm/issues/10761
            if (preg_match('/^([4578])\.(\d)(\.\d+)?(\s+PHP)?$/i', $since, $since_match) && isset($since_match[1]) && isset($since_match[2]) && (!empty($since_match[4]) || pathinfo($code_location->file_name, PATHINFO_EXTENSION) === 'phpstub')) {
                $info->since_php_major_version = (int) $since_match[1];
                $info->since_php_minor_version = (int) $since_match[2];
            }
        }
        if (isset($parsed_docblock->tags['deprecated'])) {
            $info->deprecated = true;
        }
        if (isset($parsed_docblock->tags['internal'])) {
            $info->internal = true;
        }
        if (count($info->psalm_internal = Docblock_Parser::handle_psalm_internal($parsed_docblock)) !== 0) {
            $info->internal = true;
        }
        if (isset($parsed_docblock->tags['psalm-suppress'])) {
            foreach ($parsed_docblock->tags['psalm-suppress'] as $offset => $suppress_entry) {
                foreach (Doc_Comment::parse_suppress_list($suppress_entry) as $issue_offset => $suppressed_issue) {
                    $info->suppressed_issues[$issue_offset + $offset] = $suppressed_issue;
                }
            }
        }
        if (isset($parsed_docblock->tags['throws'])) {
            foreach ($parsed_docblock->tags['throws'] as $offset => $throws_entry) {
                /** @psalm-suppress PossiblyInvalidArrayAccess */
                $throws_class = preg_split('/[\s]+/', $throws_entry)[0];
                if (!$throws_class) {
                    throw new Incorrect_Docblock_Exception('Unexpectedly empty @throws');
                }
                $info->throws[] = [$throws_class, $offset, $comment->get_start_line() + substr_count($comment->get_text(), "\n", 0, $offset - $comment->get_start_file_pos())];
            }
        }
        if (stripos($parsed_docblock->description, '@inheritdoc') !== false || isset($parsed_docblock->tags['inheritdoc']) || isset($parsed_docblock->tags['inheritDoc'])) {
            $info->inheritdoc = true;
        }
        $templates = [];
        if (isset($parsed_docblock->combined_tags['template'])) {
            foreach ($parsed_docblock->combined_tags['template'] as $offset => $template_line) {
                $template_type = preg_split('/[\s]+/', Comment_Analyzer::sanitize_docblock_type($template_line));
                if ($template_type === false) {
                    throw new AssertionError(preg_last_error_msg());
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
                    $templates[$template_name][$source_prefix] = [$template_name, $template_modifier, implode(' ', $template_type), false];
                } else {
                    $templates[$template_name][$source_prefix] = [$template_name, null, null, false];
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
        foreach (['psalm-assert', 'phpstan-assert'] as $assert) {
            if (isset($parsed_docblock->tags[$assert])) {
                foreach ($parsed_docblock->tags[$assert] as $assertion) {
                    $line_parts = self::sanitize_assertion_line_parts(Comment_Analyzer::split_doc_line($assertion));
                    $info->assertions[] = ['type' => $line_parts[0], 'param_name' => $line_parts[1][0] === '$' ? substr($line_parts[1], 1) : $line_parts[1]];
                }
                break;
            }
        }
        foreach (['psalm-assert-if-true', 'phpstan-assert-if-true'] as $assert) {
            if (isset($parsed_docblock->tags[$assert])) {
                foreach ($parsed_docblock->tags[$assert] as $assertion) {
                    $line_parts = self::sanitize_assertion_line_parts(Comment_Analyzer::split_doc_line($assertion));
                    $info->if_true_assertions[] = ['type' => $line_parts[0], 'param_name' => $line_parts[1][0] === '$' ? substr($line_parts[1], 1) : $line_parts[1]];
                }
                break;
            }
        }
        foreach (['psalm-assert-if-false', 'phpstan-assert-if-false'] as $assert) {
            if (isset($parsed_docblock->tags[$assert])) {
                foreach ($parsed_docblock->tags[$assert] as $assertion) {
                    $line_parts = self::sanitize_assertion_line_parts(Comment_Analyzer::split_doc_line($assertion));
                    $info->if_false_assertions[] = ['type' => $line_parts[0], 'param_name' => $line_parts[1][0] === '$' ? substr($line_parts[1], 1) : $line_parts[1]];
                }
                break;
            }
        }
        $info->variadic = isset($parsed_docblock->tags['psalm-variadic']);
        $info->pure = isset($parsed_docblock->tags['psalm-pure']) || isset($parsed_docblock->tags['phpstan-pure']) || isset($parsed_docblock->tags['pure']);
        if (isset($parsed_docblock->tags['psalm-mutation-free'])) {
            $info->mutation_free = true;
        }
        if (isset($parsed_docblock->tags['psalm-external-mutation-free'])) {
            $info->external_mutation_free = true;
        }
        if (isset($parsed_docblock->tags['no-named-arguments'])) {
            $info->no_named_args = true;
        }
        $info->ignore_nullable_return = isset($parsed_docblock->tags['psalm-ignore-nullable-return']);
        $info->ignore_falsable_return = isset($parsed_docblock->tags['psalm-ignore-falsable-return']);
        $info->stub_override = isset($parsed_docblock->tags['psalm-stub-override']);
        if (!empty($parsed_docblock->description)) {
            $info->description = $parsed_docblock->description;
        }
        $info->public_api = isset($parsed_docblock->tags['psalm-api']) || isset($parsed_docblock->tags['api']);
        return $info;
    }
    /**
     * @psalm-pure
     * @param list<string> $line_parts
     * @return array{string, string}&array<int<0, max>, string> $line_parts
     */
    private static function sanitize_assertion_line_parts(array $line_parts): array
    {
        if (count($line_parts) < 2 || !str_contains($line_parts[1], '$')) {
            throw new Incorrect_Docblock_Exception('Misplaced variable');
        }
        $line_parts[0] = Comment_Analyzer::sanitize_docblock_type($line_parts[0]);
        if ($line_parts[1][0] === '$') {
            $param_name_parts = explode('->', $line_parts[1]);
            foreach ($param_name_parts as $i => $param_name_part) {
                if (str_ends_with($param_name_part, '()')) {
                    $param_name_parts[$i] = strtolower($param_name_part);
                }
            }
            $line_parts[1] = implode('->', $param_name_parts);
        }
        return $line_parts;
    }
    /**
     * @param array<int, string> $return_specials
     */
    private static function extract_return_type(Php_Parser\Comment\Doc $comment, array $return_specials, Function_Docblock_Comment $info, Code_Location $code_location, string $cased_function_id): void
    {
        foreach ($return_specials as $offset => $return_block) {
            $return_lines = explode("\n", $return_block);
            if (trim($return_lines[0]) === '') {
                return;
            }
            $return_block = trim($return_block);
            if ($return_block === '') {
                return;
            }
            $line_parts = Comment_Analyzer::split_doc_line($return_block);
            if ($line_parts[0][0] !== '{') {
                if ($line_parts[0][0] === '$' && !preg_match('/^\$this(\||$)/', $line_parts[0])) {
                    throw new Incorrect_Docblock_Exception('Misplaced variable');
                }
                $end = $offset + strlen($line_parts[0]);
                $line_parts[0] = Comment_Analyzer::sanitize_docblock_type($line_parts[0]);
                $info->return_type = array_shift($line_parts);
                $info->return_type_description = $line_parts ? implode(' ', $line_parts) : null;
                $info->return_type_line_number = $comment->get_start_line() + substr_count($comment->get_text(), "\n", 0, $offset - $comment->get_start_file_pos());
                $info->return_type_start = $offset;
                $info->return_type_end = $end;
            } else {
                Issue_Buffer::maybe_add(new Invalid_Docblock('Badly-formatted @param in docblock for ' . $cased_function_id, $code_location));
            }
            break;
        }
    }
    /**
     * @throws DocblockParseException if a duplicate is found
     */
    private static function check_duplicated_tags(Parsed_Docblock $parsed_docblock): void
    {
        if (count($parsed_docblock->tags['return'] ?? []) > 1 || count($parsed_docblock->tags['psalm-return'] ?? []) > 1 || count($parsed_docblock->tags['phpstan-return'] ?? []) > 1) {
            throw new Docblock_Parse_Exception('Found duplicated @return or prefixed @return tag');
        }
        self::check_duplicated_params($parsed_docblock->tags['param'] ?? []);
        self::check_duplicated_params($parsed_docblock->tags['psalm-param'] ?? []);
        self::check_duplicated_params($parsed_docblock->tags['phpstan-param'] ?? []);
    }
    /**
     * @param array<int, string> $param
     * @throws DocblockParseException  if a duplicate is found
     */
    private static function check_duplicated_params(array $param): void
    {
        $list_names = self::extract_all_param_names($param);
        if (count($list_names) !== count(array_unique($list_names))) {
            throw new Docblock_Parse_Exception('Found duplicated @param or prefixed @param tag');
        }
    }
    /**
     * @param array<int, string> $lines
     * @return list<string>
     * @psalm-pure
     */
    private static function extract_all_param_names(array $lines): array
    {
        $names = [];
        foreach ($lines as $line) {
            $split_by_dollar = explode('$', $line, 2);
            if (count($split_by_dollar) > 1) {
                $split_by_space = explode(' ', $split_by_dollar[1], 2);
                $names[] = $split_by_space[0];
            }
        }
        return $names;
    }
    private static function check_unexpected_tags(Parsed_Docblock $parsed_docblock, Function_Docblock_Comment $info, Php_Parser\Comment\Doc $comment): void
    {
        if (isset($parsed_docblock->tags['psalm-import-type'])) {
            $info->unexpected_tags['psalm-import-type']['lines'] = self::tag_offsets_to_lines(array_keys($parsed_docblock->tags['psalm-import-type']), $comment);
        }
        if (isset($parsed_docblock->combined_tags['var'])) {
            $info->unexpected_tags['var'] = ['lines' => self::tag_offsets_to_lines(array_keys($parsed_docblock->combined_tags['var']), $comment), 'suggested_replacement' => 'param'];
        }
        if (isset($parsed_docblock->tags['psalm-consistent-constructor'])) {
            $info->unexpected_tags['psalm-consistent-constructor'] = ['lines' => self::tag_offsets_to_lines(array_keys($parsed_docblock->tags['psalm-consistent-constructor']), $comment), 'suggested_replacement' => 'psalm-consistent-constructor on a class level'];
        }
    }
    /**
     * @param list<int> $offsets
     * @return list<int>
     */
    private static function tag_offsets_to_lines(array $offsets, Php_Parser\Comment\Doc $comment): array
    {
        $ret = [];
        foreach ($offsets as $offset) {
            $ret[] = self::docblock_line_number($comment, $offset);
        }
        return $ret;
    }
    private static function docblock_line_number(Php_Parser\Comment\Doc $comment, int $offset): int
    {
        return $comment->get_start_line() + substr_count($comment->get_text(), "\n", 0, $offset - $comment->get_start_file_pos());
    }
}