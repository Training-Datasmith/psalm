<?php

declare (strict_types=1);
namespace Psalm\Internal\File_Manipulation;

use Php_Parser\Node\Stmt\Class_;
use Psalm\Doc_Comment;
use Psalm\File_Manipulation;
use Psalm\Internal\Analyzer\Project_Analyzer;
use Psalm\Internal\Scanner\Parsed_Docblock;
use function ltrim;
use function str_replace;
use function strlen;
use function strrpos;
use function substr;
/**
 * @internal
 */
final class Class_Docblock_Manipulator
{
    /**
     * @var array<string, array<int, self>>
     */
    private static array $manipulators = [];
    private readonly int $docblock_start;
    private readonly int $docblock_end;
    private bool $immutable = false;
    private readonly string $indentation;
    public static function get_for_class(Project_Analyzer $project_analyzer, string $file_path, Class_ $stmt): self
    {
        return self::$manipulators[$file_path][$stmt->get_line()] ?? self::$manipulators[$file_path][$stmt->get_line()] = new self($project_analyzer, $stmt, $file_path);
    }
    private function __construct(Project_Analyzer $project_analyzer, private readonly Class_ $stmt, string $file_path)
    {
        $docblock = $stmt->get_doc_comment();
        $this->docblock_start = $docblock ? $docblock->get_start_file_pos() : (int) $stmt->get_attribute('startFilePos');
        $this->docblock_end = (int) $stmt->get_attribute('startFilePos');
        $codebase = $project_analyzer->get_codebase();
        $file_contents = $codebase->get_file_contents($file_path);
        $preceding_newline_pos = (int) strrpos($file_contents, "\n", $this->docblock_end - strlen($file_contents));
        $first_line = substr($file_contents, $preceding_newline_pos + 1, $this->docblock_end - $preceding_newline_pos);
        $this->indentation = str_replace(ltrim($first_line), '', $first_line);
    }
    public function make_immutable(): void
    {
        $this->immutable = true;
    }
    /**
     * Gets a new docblock given the existing docblock, if one exists, and the updated return types
     * and/or parameters
     */
    private function get_docblock(): string
    {
        $docblock = $this->stmt->get_doc_comment();
        if ($docblock) {
            $parsed_docblock = Doc_Comment::parse_preserving_length($docblock);
        } else {
            $parsed_docblock = new Parsed_Docblock('', []);
        }
        $modified_docblock = false;
        if ($this->immutable) {
            $modified_docblock = true;
            $parsed_docblock->tags['psalm-immutable'] = [''];
        }
        if (!$modified_docblock) {
            return $docblock . "\n" . $this->indentation;
        }
        return $parsed_docblock->render($this->indentation);
    }
    /**
     * @return array<int, FileManipulation>
     */
    public static function get_manipulations_for_file(string $file_path): array
    {
        if (!isset(self::$manipulators[$file_path])) {
            return [];
        }
        $file_manipulations = [];
        foreach (self::$manipulators[$file_path] as $manipulator) {
            if ($manipulator->immutable) {
                $file_manipulations[$manipulator->docblock_start] = new File_Manipulation($manipulator->docblock_start, $manipulator->docblock_end, $manipulator->get_docblock());
            }
        }
        return $file_manipulations;
    }
    public static function clear_cache(): void
    {
        self::$manipulators = [];
    }
}