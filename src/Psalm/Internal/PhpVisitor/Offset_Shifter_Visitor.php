<?php

declare (strict_types=1);
namespace Psalm\Internal\Php_Visitor;

use Override;
use Php_Parser;
/**
 * Shifts all nodes in a given AST by a set amount
 *
 * @internal
 */
final class Offset_Shifter_Visitor extends Php_Parser\Node_Visitor_Abstract
{
    /**
     * @param array<int, int> $extra_offsets
     */
    public function __construct(private readonly int $file_offset, private readonly int $line_offset, private array $extra_offsets)
    {
    }
    #[Override]
    public function enter_node(Php_Parser\Node $node): ?int
    {
        /** @var array{startFilePos: int, endFilePos: int, startLine: int} */
        $attrs = $node->get_attributes();
        if ($cs = $node->get_comments()) {
            $new_comments = [];
            foreach ($cs as $c) {
                if ($c instanceof Php_Parser\Comment\Doc) {
                    $new_comments[] = new Php_Parser\Comment\Doc($c->get_text(), $c->get_start_line() + $this->line_offset, $c->get_start_file_pos() + $this->file_offset + ($this->extra_offsets[$c->get_start_file_pos()] ?? 0));
                } else {
                    $new_comments[] = new Php_Parser\Comment($c->get_text(), $c->get_start_line() + $this->line_offset, $c->get_start_file_pos() + $this->file_offset + ($this->extra_offsets[$c->get_start_file_pos()] ?? 0));
                }
            }
            $node->set_attribute('comments', $new_comments);
        }
        $node->set_attribute('startFilePos', $attrs['startFilePos'] + $this->file_offset + ($this->extra_offsets[$attrs['startFilePos']] ?? 0));
        $node->set_attribute('endFilePos', $attrs['endFilePos'] + $this->file_offset + ($this->extra_offsets[$attrs['endFilePos']] ?? 0));
        $node->set_attribute('startLine', $attrs['startLine'] + $this->line_offset);
        return null;
    }
}