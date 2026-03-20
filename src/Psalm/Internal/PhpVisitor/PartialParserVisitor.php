<?php

declare (strict_types=1);
namespace Psalm\Internal\Php_Visitor;

use Override;
use Php_Parser;
use Php_Parser\Error_Handler\Collecting;
use Php_Parser\Parser;
use function assert;
use function count;
use function preg_match_all;
use function preg_replace;
use function reset;
use function str_repeat;
use function strlen;
use function strpos;
use function strrpos;
use function substr;
use function substr_count;
use function substr_replace;
use function token_get_all;
use const PREG_OFFSET_CAPTURE;
use const PREG_SET_ORDER;
/**
 * Given a list of file diffs, this scans an AST to find the sections it can replace, and parses
 * just those methods.
 *
 * @internal
 */
final class Partial_Parser_Visitor extends Php_Parser\Node_Visitor_Abstract
{
    private bool $must_rescan = false;
    private int $non_method_changes;
    private readonly int $a_file_contents_length;
    /** @param array<int, array{0: int, 1: int, 2: int, 3: int, 4: int, 5: string}> $offset_map */
    public function __construct(private readonly Parser $parser, private readonly Collecting $error_handler, private readonly array $offset_map, private readonly string $a_file_contents, private readonly string $b_file_contents)
    {
        $this->a_file_contents_length = strlen($a_file_contents);
        $this->non_method_changes = count($offset_map);
    }
    #[Override]
    public function enter_node(Php_Parser\Node $node, bool &$traverse_children = true): int|Php_Parser\Node|null
    {
        /** @var array{startFilePos: int, endFilePos: int, startLine: int} */
        $attrs = $node->get_attributes();
        if ($cs = $node->get_comments()) {
            $stmt_start_pos = $cs[0]->get_start_file_pos();
        } else {
            $stmt_start_pos = $attrs['startFilePos'];
        }
        $stmt_end_pos = $attrs['endFilePos'];
        $start_offset = 0;
        $end_offset = 0;
        $line_offset = 0;
        foreach ($this->offset_map as [$a_s, $a_e, $b_s, $b_e, $line_diff]) {
            if ($a_s > $stmt_end_pos) {
                break;
            }
            $end_offset = $b_e - $a_e;
            if ($a_s < $stmt_start_pos) {
                $start_offset = $b_s - $a_s;
            }
            if ($a_e < $stmt_start_pos) {
                $start_offset = $end_offset;
                $line_offset = $line_diff;
                continue;
            }
            if ($node instanceof Php_Parser\Node\Stmt\Class_Method || $node instanceof Php_Parser\Node\Stmt\Namespace_ || $node instanceof Php_Parser\Node\Stmt\Class_Like) {
                if ($node instanceof Php_Parser\Node\Stmt\Class_Method) {
                    if ($a_s >= $stmt_start_pos && $a_e <= $stmt_end_pos) {
                        foreach ($this->offset_map as [$a_s2, $a_e2, $b_s2, $b_e2]) {
                            if ($a_s2 > $stmt_end_pos) {
                                break;
                            }
                            // we have a diff that goes outside the bounds that we care about
                            if ($a_e2 > $stmt_end_pos) {
                                $this->must_rescan = true;
                                return self::STOP_TRAVERSAL;
                            }
                            $end_offset = $b_e2 - $a_e2;
                            if ($a_s2 < $stmt_start_pos) {
                                $start_offset = $b_s2 - $a_s2;
                            }
                            if ($a_e2 < $stmt_start_pos) {
                                $start_offset = $end_offset;
                                $line_offset = $line_diff;
                                continue;
                            }
                            if ($a_s2 >= $stmt_start_pos && $a_e2 <= $stmt_end_pos) {
                                --$this->non_method_changes;
                            }
                        }
                        $stmt_start_pos += $start_offset;
                        $stmt_end_pos += $end_offset;
                        $current_line = substr_count(substr($this->b_file_contents, 0, $stmt_start_pos), "\n");
                        $method_contents = substr($this->b_file_contents, $stmt_start_pos, $stmt_end_pos - $stmt_start_pos + 1);
                        if (!$method_contents) {
                            $this->must_rescan = true;
                            return self::STOP_TRAVERSAL;
                        }
                        $error_handler = new Collecting();
                        $fake_class = '<?php class _ {' . $method_contents . '}';
                        $extra_characters = [];
                        // To avoid a parser error during completion we replace
                        //
                        // Foo::
                        // if (...) {}
                        //
                        // with
                        //
                        // Foo::;
                        // if (...) {}
                        //
                        // When we insert the extra semicolon we have to keep track of the places
                        // we inserted it, and then shift the AST node offsets accordingly after parsing
                        // is complete.
                        //
                        // If anyone's unlucky enough to have a static method named "if" with a newline
                        // before the method name e.g.
                        //
                        // Foo::
                        // if(...);
                        //
                        // This transformation will break that.
                        preg_match_all('/(->|::)(\n\s*(if|list)\s*\()/', $fake_class, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
                        foreach ($matches as $match) {
                            $fake_class = substr_replace($fake_class, $match[1][0] . ';' . $match[2][0], $match[0][1], strlen($match[0][0]));
                            $extra_characters[] = $match[2][1];
                        }
                        $replacement_stmts = $this->parser->parse($fake_class, $error_handler) ?: [];
                        if (!$replacement_stmts || !$replacement_stmts[0] instanceof Php_Parser\Node\Stmt\Class_Like || count($replacement_stmts[0]->stmts) !== 1) {
                            $hacky_class_fix = self::balance_brackets($fake_class);
                            if ($replacement_stmts && $replacement_stmts[0] instanceof Php_Parser\Node\Stmt\Class_Like && count($replacement_stmts[0]->stmts) !== 1) {
                                $this->must_rescan = true;
                                return self::STOP_TRAVERSAL;
                            }
                            // changes "): {" to ") {"
                            $hacky_class_fix = (string) preg_replace('/(\)[\s]*):([\s]*\{)/', '$1 $2', $hacky_class_fix);
                            if ($hacky_class_fix !== $fake_class) {
                                $replacement_stmts = $this->parser->parse($hacky_class_fix, $error_handler) ?: [];
                            }
                            if (!$replacement_stmts || !$replacement_stmts[0] instanceof Php_Parser\Node\Stmt\Class_Like || count($replacement_stmts[0]->stmts) > 1) {
                                $this->must_rescan = true;
                                return self::STOP_TRAVERSAL;
                            }
                        }
                        $replacement_stmts = $replacement_stmts[0]->stmts;
                        $extra_offsets = [];
                        foreach ($extra_characters as $extra_offset) {
                            $l = strlen($fake_class);
                            for ($i = $extra_offset; $i < $l; $i++) {
                                if (isset($extra_offsets[$i])) {
                                    $extra_offsets[$i]--;
                                } else {
                                    $extra_offsets[$i] = -1;
                                }
                            }
                        }
                        $renumbering_traverser = new Php_Parser\Node_Traverser();
                        $position_shifter = new Offset_Shifter_Visitor($stmt_start_pos - 15, $current_line, $extra_offsets);
                        $renumbering_traverser->add_visitor($position_shifter);
                        $replacement_stmts = $renumbering_traverser->traverse($replacement_stmts);
                        if ($error_handler->has_errors()) {
                            foreach ($error_handler->get_errors() as $error) {
                                if ($error->has_column_info()) {
                                    /** @var array{startFilePos: int, endFilePos: int} */
                                    $error_attrs = $error->get_attributes();
                                    $error = new Php_Parser\Error($error->get_raw_message(), ['startFilePos' => $stmt_start_pos + $error_attrs['startFilePos'] - 15, 'endFilePos' => $stmt_start_pos + $error_attrs['endFilePos'] - 15, 'startLine' => $error->get_start_line() + $current_line + $line_offset]);
                                }
                                $this->error_handler->handle_error($error);
                            }
                        }
                        $error_handler->clear_errors();
                        $traverse_children = false;
                        assert(!empty($replacement_stmts));
                        return reset($replacement_stmts);
                    }
                    $this->must_rescan = true;
                    return self::STOP_TRAVERSAL;
                }
                if ($node->stmts) {
                    /** @var int */
                    $stmt_inner_start_pos = $node->stmts[0]->get_attribute('startFilePos');
                    /** @var int */
                    $stmt_inner_end_pos = $node->stmts[count($node->stmts) - 1]->get_attribute('endFilePos');
                    if ($node instanceof Php_Parser\Node\Stmt\Class_Like) {
                        /** @psalm-suppress PossiblyFalseOperand */
                        $stmt_inner_start_pos = strrpos($this->a_file_contents, '{', $stmt_inner_start_pos - $this->a_file_contents_length) + 1;
                        if ($stmt_inner_end_pos < $this->a_file_contents_length) {
                            $stmt_inner_end_pos = strpos($this->a_file_contents, '}', $stmt_inner_end_pos + 1);
                        }
                    }
                    if ($a_s > $stmt_inner_start_pos && $a_e < $stmt_inner_end_pos) {
                        continue;
                    }
                }
            }
            $this->must_rescan = true;
            return self::STOP_TRAVERSAL;
        }
        if ($start_offset !== 0 || $end_offset !== 0 || $line_offset !== 0) {
            if ($start_offset !== 0) {
                if ($cs) {
                    $new_comments = [];
                    foreach ($cs as $c) {
                        if ($c instanceof Php_Parser\Comment\Doc) {
                            $new_comments[] = new Php_Parser\Comment\Doc($c->get_text(), $c->get_start_line() + $line_offset, $c->get_start_file_pos() + $start_offset);
                        } else {
                            $new_comments[] = new Php_Parser\Comment($c->get_text(), $c->get_start_line() + $line_offset, $c->get_start_file_pos() + $start_offset);
                        }
                    }
                    $node->set_attribute('comments', $new_comments);
                    $node->set_attribute('startFilePos', $attrs['startFilePos'] + $start_offset);
                } else {
                    $node->set_attribute('startFilePos', $stmt_start_pos + $start_offset);
                }
            }
            if ($end_offset !== 0) {
                $node->set_attribute('endFilePos', $stmt_end_pos + $end_offset);
            }
            if ($line_offset !== 0) {
                $node->set_attribute('startLine', $attrs['startLine'] + $line_offset);
            }
            return $node;
        }
        return null;
    }
    public function must_rescan(): bool
    {
        return $this->must_rescan || $this->non_method_changes;
    }
    /**
     * @psalm-pure
     */
    private static function balance_brackets(string $fake_class): string
    {
        $tokens = token_get_all($fake_class);
        $brace_count = 0;
        foreach ($tokens as $token) {
            if ($token === '{') {
                ++$brace_count;
            } elseif ($token === '}') {
                --$brace_count;
            }
        }
        if ($brace_count > 0) {
            $fake_class .= str_repeat('}', $brace_count);
        }
        return $fake_class;
    }
}