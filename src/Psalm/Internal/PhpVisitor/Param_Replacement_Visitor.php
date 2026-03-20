<?php

declare (strict_types=1);
namespace Psalm\Internal\Php_Visitor;

use Override;
use Php_Parser;
use Psalm\File_Manipulation;
use Psalm\Internal\Analyzer\Comment_Analyzer;
use Psalm\Internal\Scanner\Docblock_Parser;
use function rtrim;
use function str_replace;
use function strlen;
/**
 * @internal
 */
final class Param_Replacement_Visitor extends Php_Parser\Node_Visitor_Abstract
{
    /** @var list<FileManipulation> */
    private array $replacements = [];
    private bool $new_name_replaced = false;
    private bool $new_new_name_used = false;
    public function __construct(private readonly string $old_name, private readonly string $new_name)
    {
    }
    #[Override]
    public function enter_node(Php_Parser\Node $node): ?int
    {
        if ($node instanceof Php_Parser\Node\Expr\Variable) {
            if ($node->name === $this->old_name) {
                $this->replacements[] = new File_Manipulation((int) $node->get_attribute('startFilePos') + 1, (int) $node->get_attribute('endFilePos') + 1, $this->new_name);
            } elseif ($node->name === $this->new_name) {
                if ($this->new_new_name_used) {
                    $this->replacements = [];
                    return self::STOP_TRAVERSAL;
                }
                $this->replacements[] = new File_Manipulation((int) $node->get_attribute('startFilePos') + 1, (int) $node->get_attribute('endFilePos') + 1, $this->new_name . '_new');
                $this->new_name_replaced = true;
            } elseif ($node->name === $this->new_name . '_new') {
                if ($this->new_name_replaced) {
                    $this->replacements = [];
                    return self::STOP_TRAVERSAL;
                }
                $this->new_new_name_used = true;
            }
        } elseif ($node instanceof Php_Parser\Node\Stmt\Class_Method && $docblock = $node->get_doc_comment()) {
            $parsed_docblock = Docblock_Parser::parse($docblock->get_text(), $docblock->get_start_file_pos());
            $replaced = false;
            foreach ($parsed_docblock->tags as $tag_name => $tags) {
                foreach ($tags as $i => $tag) {
                    if ($tag_name === 'param' || $tag_name === 'psalm-param' || $tag_name === 'phpstan-param' || $tag_name === 'phan-param') {
                        $parts = Comment_Analyzer::split_doc_line($tag);
                        if (($parts[1] ?? '') === '$' . $this->old_name) {
                            $parsed_docblock->tags[$tag_name][$i] = str_replace('$' . $this->old_name, '$' . $this->new_name, $tag);
                            $replaced = true;
                        }
                    }
                }
            }
            if ($replaced) {
                $this->replacements[] = new File_Manipulation($docblock->get_start_file_pos(), $docblock->get_start_file_pos() + strlen($docblock->get_text()), rtrim($parsed_docblock->render($parsed_docblock->first_line_padding)), false, false);
            }
        }
        return null;
    }
    /**
     * @return list<FileManipulation>
     */
    public function get_replacements(): array
    {
        return $this->replacements;
    }
}