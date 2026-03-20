<?php

declare (strict_types=1);
namespace Psalm\Internal\Type_Visitor;

use Override;
use Psalm\Internal\Codebase\Scanner;
use Psalm\Storage\File_Storage;
use Psalm\Type\Atomic\T_Class_Constant;
use Psalm\Type\Atomic\T_Literal_Class_String;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Type_Node;
use Psalm\Type\Type_Visitor;
use function strtolower;
/**
 * @internal
 */
final class Type_Scanner extends Type_Visitor
{
    /**
     * @param  array<string, mixed> $phantom_classes
     */
    public function __construct(private readonly Scanner $scanner, private readonly ?File_Storage $file_storage, private array $phantom_classes)
    {
    }
    #[Override]
    protected function enter_node(Type_Node $type): ?int
    {
        if ($type instanceof T_Named_Object) {
            $fq_classlike_name_lc = strtolower($type->value);
            if (!isset($this->phantom_classes[$type->value]) && !isset($this->phantom_classes[$fq_classlike_name_lc])) {
                $this->scanner->queue_class_like_for_scanning($type->value, false, !$type->from_docblock, $this->phantom_classes);
                if ($this->file_storage) {
                    $this->file_storage->referenced_classlikes[$fq_classlike_name_lc] = $type->value;
                }
            }
        }
        if ($type instanceof T_Class_Constant) {
            $this->scanner->queue_class_like_for_scanning($type->fq_classlike_name, false, !$type->from_docblock, $this->phantom_classes);
            if ($this->file_storage) {
                $fq_classlike_name_lc = strtolower($type->fq_classlike_name);
                $this->file_storage->referenced_classlikes[$fq_classlike_name_lc] = $type->fq_classlike_name;
            }
        }
        if ($type instanceof T_Literal_Class_String) {
            $this->scanner->queue_class_like_for_scanning($type->value, false, !$type->from_docblock, $this->phantom_classes);
            if ($this->file_storage) {
                $fq_classlike_name_lc = strtolower($type->value);
                $this->file_storage->referenced_classlikes[$fq_classlike_name_lc] = $type->value;
            }
        }
        return null;
    }
}