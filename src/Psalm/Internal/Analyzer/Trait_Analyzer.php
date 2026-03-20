<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer;

use Attribute;
use Override;
use Php_Parser\Node\Stmt\Trait_;
use Psalm\Aliases;
use Psalm\Context;
use Psalm\Issue_Buffer;
use function assert;
/**
 * @internal
 */
final class Trait_Analyzer extends Class_Like_Analyzer
{
    public function __construct(Trait_ $class, Source_Analyzer $source, string $fq_class_name, private readonly Aliases $aliases)
    {
        $this->source = $source;
        $this->file_analyzer = $source->get_file_analyzer();
        $this->class = $class;
        $this->fq_class_name = $fq_class_name;
        $codebase = $source->get_codebase();
        $this->storage = $codebase->classlike_storage_provider->get($fq_class_name);
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_namespace(): ?string
    {
        return $this->aliases->namespace;
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_aliases(): Aliases
    {
        return $this->aliases;
    }
    /**
     * @psalm-mutation-free
     * @return array<lowercase-string, string>
     */
    #[Override]
    public function get_aliased_classes_flipped(): array
    {
        return [];
    }
    /**
     * @psalm-mutation-free
     * @return array<string, string>
     */
    #[Override]
    public function get_aliased_classes_flipped_replaceable(): array
    {
        return [];
    }
    public static function analyze(Statements_Analyzer $statements_analyzer, Trait_ $stmt, Context $context): void
    {
        assert($stmt->name !== null);
        $codebase = $statements_analyzer->get_codebase();
        if (!$codebase->classlike_storage_provider->has($stmt->name->name)) {
            return;
        }
        $storage = $codebase->classlike_storage_provider->get($stmt->name->name);
        Attributes_Analyzer::analyze($statements_analyzer, $context, $storage, $stmt->attr_groups, Attribute::TARGET_CLASS, $storage->suppressed_issues + $statements_analyzer->get_suppressed_issues());
        foreach ($storage->docblock_issues as $docblock_issue) {
            Issue_Buffer::maybe_add($docblock_issue);
        }
    }
}