<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer;

use Override;
use Psalm\Aliases;
use Psalm\Codebase;
use Psalm\Node_Type_Provider;
use Psalm\Statements_Source;
use Psalm\Type\Union;
/**
 * @internal
 */
abstract class Source_Analyzer implements Statements_Source
{
    protected Source_Analyzer $source;
    public function __destruct()
    {
        unset($this->source);
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_aliases(): Aliases
    {
        return $this->source->get_aliases();
    }
    /**
     * @psalm-mutation-free
     * @return array<lowercase-string, string>
     */
    #[Override]
    public function get_aliased_classes_flipped(): array
    {
        return $this->source->get_aliased_classes_flipped();
    }
    /**
     * @psalm-mutation-free
     * @return array<string, string>
     */
    #[Override]
    public function get_aliased_classes_flipped_replaceable(): array
    {
        return $this->source->get_aliased_classes_flipped_replaceable();
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_fqcln(): ?string
    {
        return $this->source->get_fqcln();
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_class_name(): ?string
    {
        return $this->source->get_class_name();
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_parent_fqcln(): ?string
    {
        return $this->source->get_parent_fqcln();
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_file_name(): string
    {
        return $this->source->get_file_name();
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_file_path(): string
    {
        return $this->source->get_file_path();
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_root_file_name(): string
    {
        return $this->source->get_root_file_name();
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_root_file_path(): string
    {
        return $this->source->get_root_file_path();
    }
    #[Override]
    public function set_root_file_path(string $file_path, string $file_name): void
    {
        $this->source->set_root_file_path($file_path, $file_name);
    }
    /** @psalm-mutation-free */
    #[Override]
    public function has_parent_file_path(string $file_path): bool
    {
        return $this->source->has_parent_file_path($file_path);
    }
    /** @psalm-mutation-free */
    #[Override]
    public function has_already_required_file_path(string $file_path): bool
    {
        return $this->source->has_already_required_file_path($file_path);
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_require_nesting(): int
    {
        return $this->source->get_require_nesting();
    }
    /**
     * @psalm-mutation-free
     */
    #[Override]
    public function get_source(): Statements_Source
    {
        return $this->source;
    }
    /**
     * Get a list of suppressed issues
     *
     * @psalm-mutation-free
     * @return array<string>
     */
    #[Override]
    public function get_suppressed_issues(): array
    {
        return $this->source->get_suppressed_issues();
    }
    /**
     * @param array<int, string> $new_issues
     */
    #[Override]
    public function add_suppressed_issues(array $new_issues): void
    {
        $this->source->add_suppressed_issues($new_issues);
    }
    /**
     * @param array<int, string> $new_issues
     */
    #[Override]
    public function remove_suppressed_issues(array $new_issues): void
    {
        $this->source->remove_suppressed_issues($new_issues);
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_namespace(): ?string
    {
        return $this->source->get_namespace();
    }
    /** @psalm-mutation-free */
    #[Override]
    public function is_static(): bool
    {
        return $this->source->is_static();
    }
    /**
     * @psalm-mutation-free
     */
    #[Override]
    public function get_codebase(): Codebase
    {
        return $this->source->get_codebase();
    }
    /**
     * @psalm-mutation-free
     */
    public function get_project_analyzer(): Project_Analyzer
    {
        return $this->source->get_project_analyzer();
    }
    /**
     * @psalm-mutation-free
     */
    public function get_file_analyzer(): File_Analyzer
    {
        return $this->source->get_file_analyzer();
    }
    /**
     * @psalm-mutation-free
     * @return array<string, array<string, Union>>|null
     */
    #[Override]
    public function get_template_type_map(): ?array
    {
        return $this->source->get_template_type_map();
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_node_type_provider(): Node_Type_Provider
    {
        return $this->source->get_node_type_provider();
    }
}