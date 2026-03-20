<?php

declare (strict_types=1);
namespace Psalm\Internal\Scanner;

use Override;
use Php_Parser;
use Php_Parser\Node_Traverser;
use Psalm\Aliases;
use Psalm\Codebase;
use Psalm\File_Source;
use Psalm\Internal\Php_Visitor\Reflector_Visitor;
use Psalm\Progress\Progress;
use Psalm\Progress\Void_Progress;
use Psalm\Storage\File_Storage;
/**
 * @internal
 * @psalm-consistent-constructor
 */
class File_Scanner implements File_Source
{
    public function __construct(public string $file_path, public string $file_name, public bool $will_analyze)
    {
    }
    public function scan(Codebase $codebase, File_Storage $file_storage, bool $storage_from_cache = false, ?Progress $progress = null): void
    {
        if ($progress === null) {
            $progress = new Void_Progress();
        }
        if ((!$this->will_analyze || $file_storage->deep_scan) && $storage_from_cache && !$codebase->register_stub_files) {
            return;
        }
        $stmts = $codebase->get_statements_for_file($file_storage->file_path, $progress);
        foreach ($stmts as $stmt) {
            if (!$stmt instanceof Php_Parser\Node\Stmt\Class_Like && !$stmt instanceof Php_Parser\Node\Stmt\Function_ && !($stmt instanceof Php_Parser\Node\Stmt\Expression && $stmt->expr instanceof Php_Parser\Node\Expr\Include_)) {
                $file_storage->has_extra_statements = true;
                break;
            }
        }
        if ($this->will_analyze) {
            $progress->debug('Deep scanning ' . $file_storage->file_path . "\n");
        } else {
            $progress->debug('Scanning ' . $file_storage->file_path . "\n");
        }
        $traverser = new Node_Traverser();
        $traverser->add_visitor(new Reflector_Visitor($codebase, $this, $file_storage));
        $traverser->traverse($stmts);
        $file_storage->deep_scan = $this->will_analyze;
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_file_path(): string
    {
        return $this->file_path;
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_file_name(): string
    {
        return $this->file_name;
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_root_file_path(): string
    {
        return $this->file_path;
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_root_file_name(): string
    {
        return $this->file_name;
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_aliases(): Aliases
    {
        return new Aliases();
    }
}