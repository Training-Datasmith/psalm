<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer;

use Override;
use Php_Parser;
use Psalm\Aliases;
use Psalm\Code_Location;
use Psalm\File_Manipulation;
use Psalm\Internal\File_Manipulation\File_Manipulation_Buffer;
use function strtolower;
/**
 * @internal
 */
trait Can_Alias
{
    /**
     * @var array<lowercase-string, string>
     */
    private array $aliased_classes = [];
    /**
     * @var array<lowercase-string, CodeLocation>
     */
    private array $aliased_class_locations = [];
    /**
     * @var array<lowercase-string, string>
     */
    private array $aliased_classes_flipped = [];
    /**
     * @var array<lowercase-string, string>
     */
    private array $aliased_classes_flipped_replaceable = [];
    /**
     * @var array<lowercase-string, non-empty-string>
     */
    private array $aliased_functions = [];
    /**
     * @var array<string, string>
     */
    private array $aliased_constants = [];
    public function visit_use(Php_Parser\Node\Stmt\Use_ $stmt): void
    {
        $codebase = $this->get_codebase();
        foreach ($stmt->uses as $use) {
            $use_path = $use->name->to_string();
            $use_path_lc = strtolower($use_path);
            $use_alias = $use->alias->name ?? $use->name->get_last();
            $use_alias_lc = strtolower($use_alias);
            switch ($use->type !== Php_Parser\Node\Stmt\Use_::TYPE_UNKNOWN ? $use->type : $stmt->type) {
                case Php_Parser\Node\Stmt\Use_::TYPE_FUNCTION:
                    $this->aliased_functions[$use_alias_lc] = $use_path;
                    break;
                case Php_Parser\Node\Stmt\Use_::TYPE_CONSTANT:
                    $this->aliased_constants[$use_alias] = $use_path;
                    break;
                case Php_Parser\Node\Stmt\Use_::TYPE_NORMAL:
                    $codebase->analyzer->add_offset_reference($this->get_file_path(), (int) $use->get_attribute('startFilePos'), (int) $use->get_attribute('endFilePos'), $use_path);
                    if ($codebase->collect_locations) {
                        // register the path
                        $codebase->use_referencing_locations[$use_path_lc][] = new Code_Location($this, $use);
                    }
                    if ($codebase->alter_code) {
                        if (isset($codebase->class_transforms[$use_path_lc])) {
                            $new_fq_class_name = $codebase->class_transforms[$use_path_lc];
                            $file_manipulations = [];
                            $file_manipulations[] = new File_Manipulation((int) $use->get_attribute('startFilePos'), (int) $use->get_attribute('endFilePos') + 1, $new_fq_class_name . ($use->alias ? ' as ' . $use_alias : ''));
                            File_Manipulation_Buffer::add($this->get_file_path(), $file_manipulations);
                        }
                        $this->aliased_classes_flipped_replaceable[$use_path_lc] = $use_alias;
                    }
                    $this->aliased_classes[$use_alias_lc] = $use_path;
                    $this->aliased_class_locations[$use_alias_lc] = new Code_Location($this, $stmt);
                    $this->aliased_classes_flipped[$use_path_lc] = $use_alias;
                    break;
            }
        }
    }
    public function visit_group_use(Php_Parser\Node\Stmt\Group_Use $stmt): void
    {
        $use_prefix = $stmt->prefix->to_string();
        $codebase = $this->get_codebase();
        foreach ($stmt->uses as $use) {
            $use_path = $use_prefix . '\\' . $use->name->to_string();
            $use_alias = $use->alias->name ?? $use->name->get_last();
            switch ($use->type !== Php_Parser\Node\Stmt\Use_::TYPE_UNKNOWN ? $use->type : $stmt->type) {
                case Php_Parser\Node\Stmt\Use_::TYPE_FUNCTION:
                    $this->aliased_functions[strtolower($use_alias)] = $use_path;
                    break;
                case Php_Parser\Node\Stmt\Use_::TYPE_CONSTANT:
                    $this->aliased_constants[$use_alias] = $use_path;
                    break;
                case Php_Parser\Node\Stmt\Use_::TYPE_NORMAL:
                    if ($codebase->collect_locations) {
                        // register the path
                        $codebase->use_referencing_locations[strtolower($use_path)][] = new Code_Location($this, $use);
                    }
                    $this->aliased_classes[strtolower($use_alias)] = $use_path;
                    $this->aliased_classes_flipped[strtolower($use_path)] = $use_alias;
                    break;
            }
        }
    }
    /**
     * @psalm-mutation-free
     * @return array<lowercase-string, string>
     */
    #[Override]
    public function get_aliased_classes_flipped(): array
    {
        return $this->aliased_classes_flipped;
    }
    /**
     * @psalm-mutation-free
     * @return array<string, string>
     */
    #[Override]
    public function get_aliased_classes_flipped_replaceable(): array
    {
        return $this->aliased_classes_flipped_replaceable;
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_aliases(): Aliases
    {
        return new Aliases($this->get_namespace(), $this->aliased_classes, $this->aliased_functions, $this->aliased_constants);
    }
}