<?php

declare (strict_types=1);
namespace Psalm\Internal\Php_Visitor;

use Override;
use Php_Parser;
use ReflectionClass;
use Throwable;
use function count;
use function end;
use function explode;
use function strcasecmp;
use function trait_exists;
/**
 * Given a list of file diffs, this scans an AST to find the sections it can replace, and parses
 * just those methods.
 *
 * @internal
 */
final class Trait_Finder extends Php_Parser\Node_Visitor_Abstract
{
    /** @var list<PhpParser\Node\Stmt\Trait_> */
    private array $matching_trait_nodes = [];
    public function __construct(private readonly string $fq_trait_name)
    {
    }
    #[Override]
    public function enter_node(Php_Parser\Node $node, bool &$traverse_children = true): ?int
    {
        if ($node instanceof Php_Parser\Node\Stmt\Trait_) {
            /** @var ?string */
            $resolved_name = $node->get_attribute('resolvedName');
            if ($resolved_name === null) {
                // compare ends of names, a temporary hack because PHPParser caches
                // may not have that attribute
                $fq_trait_name_parts = explode('\\', $this->fq_trait_name);
                /** @psalm-suppress PossiblyNullPropertyFetch */
                if ($node->name->name !== null && strcasecmp($node->name->name, end($fq_trait_name_parts)) === 0) {
                    $this->matching_trait_nodes[] = $node;
                }
            } elseif (strcasecmp($resolved_name, $this->fq_trait_name) === 0) {
                $this->matching_trait_nodes[] = $node;
            }
        }
        if ($node instanceof Php_Parser\Node\Stmt\Class_Like || $node instanceof Php_Parser\Node\Function_Like) {
            return Php_Parser\Node_Visitor::DONT_TRAVERSE_CHILDREN;
        }
        return null;
    }
    public function get_node(): ?Php_Parser\Node\Stmt\Trait_
    {
        if (!count($this->matching_trait_nodes)) {
            return null;
        }
        if (count($this->matching_trait_nodes) === 1 || !trait_exists($this->fq_trait_name)) {
            return $this->matching_trait_nodes[0];
        }
        try {
            $reflection_trait = new ReflectionClass($this->fq_trait_name);
        } catch (Throwable) {
            return null;
        }
        foreach ($this->matching_trait_nodes as $node) {
            if ($node->get_line() === $reflection_trait->get_start_line()) {
                return $node;
            }
        }
        return null;
    }
}