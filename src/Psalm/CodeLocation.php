<?php

declare (strict_types=1);
namespace Psalm;

use Exception;
use LogicException;
use Php_Parser;
use Psalm\Internal\Analyzer\Comment_Analyzer;
use Psalm\Internal\Analyzer\Project_Analyzer;
use Psalm\Storage\Immutable_Non_Cloneable_Trait;
use UnexpectedValueException;
use function explode;
use function max;
use function mb_strcut;
use function min;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function str_replace;
use function strlen;
use function strpos;
use function strrpos;
use function substr_count;
use function trim;
use const PREG_OFFSET_CAPTURE;
/**
 * @psalm-immutable
 */
class Code_Location
{
    use Immutable_Non_Cloneable_Trait;
    public string $file_path;
    public string $file_name;
    public int $raw_line_number;
    private int $end_line_number = -1;
    public int $raw_file_start;
    public int $raw_file_end;
    protected int $file_start;
    protected int $file_end;
    protected int $preview_start;
    private int $preview_end = -1;
    private int $selection_start = -1;
    private int $selection_end = -1;
    private int $column_from = -1;
    private int $column_to = -1;
    private string $snippet = '';
    public ?int $docblock_start = null;
    private ?int $docblock_start_line_number = null;
    private bool $have_recalculated = false;
    public const VAR_TYPE = 0;
    public const FUNCTION_RETURN_TYPE = 1;
    public const FUNCTION_PARAM_TYPE = 2;
    public const FUNCTION_PHPDOC_RETURN_TYPE = 3;
    public const FUNCTION_PHPDOC_PARAM_TYPE = 4;
    public const FUNCTION_PARAM_VAR = 5;
    public const CATCH_VAR = 6;
    public const FUNCTION_PHPDOC_METHOD = 7;
    private const PROPERTY_KEYS_FOR_UNSERIALIZE = ['file_path' => 'file_path', 'file_name' => 'file_name', 'raw_line_number' => 'raw_line_number', "\x00" . self::class . "\x00" . 'end_line_number' => 'end_line_number', 'raw_file_start' => 'raw_file_start', 'raw_file_end' => 'raw_file_end', "\x00*\x00" . 'file_start' => 'file_start', "\x00*\x00" . 'file_end' => 'file_end', "\x00*\x00" . 'single_line' => 'single_line', "\x00*\x00" . 'preview_start' => 'preview_start', "\x00" . self::class . "\x00" . 'preview_end' => 'preview_end', "\x00" . self::class . "\x00" . 'selection_start' => 'selection_start', "\x00" . self::class . "\x00" . 'selection_end' => 'selection_end', "\x00" . self::class . "\x00" . 'column_from' => 'column_from', "\x00" . self::class . "\x00" . 'column_to' => 'column_to', "\x00" . self::class . "\x00" . 'snippet' => 'snippet', "\x00" . self::class . "\x00" . 'text' => 'text', 'docblock_start' => 'docblock_start', "\x00" . self::class . "\x00" . 'docblock_start_line_number' => 'docblock_start_line_number', "\x00*\x00" . 'docblock_line_number' => 'docblock_line_number', "\x00" . self::class . "\x00" . 'regex_type' => 'regex_type', "\x00" . self::class . "\x00" . 'have_recalculated' => 'have_recalculated', 'previous_location' => 'previous_location'];
    public function __construct(File_Source $file_source, Php_Parser\Node $stmt, public ?Code_Location $previous_location = null, protected bool $single_line = false, private ?int $regex_type = null, private ?string $text = null, protected ?int $docblock_line_number = null)
    {
        /** @psalm-suppress ImpureMethodCall Actually mutation-free just not marked */
        $this->file_start = (int) $stmt->get_attribute('startFilePos');
        /** @psalm-suppress ImpureMethodCall Actually mutation-free just not marked */
        $this->file_end = (int) $stmt->get_attribute('endFilePos');
        $this->raw_file_start = $this->file_start;
        $this->raw_file_end = $this->file_end;
        $this->file_path = $file_source->get_file_path();
        $this->file_name = $file_source->get_file_name();
        /** @psalm-suppress ImpureMethodCall Actually mutation-free just not marked */
        $doc_comment = $stmt->get_doc_comment();
        $this->docblock_start = $doc_comment ? $doc_comment->get_start_file_pos() : null;
        $this->docblock_start_line_number = $doc_comment ? $doc_comment->get_start_line() : null;
        $this->preview_start = $this->docblock_start ?: $this->file_start;
        /** @psalm-suppress ImpureMethodCall Actually mutation-free just not marked */
        $this->raw_line_number = $stmt->get_start_line();
    }
    /**
     * Suppresses memory usage when unserializing objects.
     *
     * @see \Psalm\Storage\UnserializeMemoryUsageSuppressionTrait
     */
    public function __unserialize(array $properties): void
    {
        foreach (self::PROPERTY_KEYS_FOR_UNSERIALIZE as $key => $property_name) {
            /** @psalm-suppress PossiblyUndefinedStringArrayOffset */
            $this->{$property_name} = $properties[$key];
        }
    }
    /**
     * @psalm-suppress PossiblyUnusedMethod Part of public API
     * @return static
     */
    public function set_comment_line(?int $line): self
    {
        if ($line === $this->docblock_line_number) {
            return $this;
        }
        $cloned = clone $this;
        $cloned->docblock_line_number = $line;
        return $cloned;
    }
    /**
     * @psalm-external-mutation-free
     * @psalm-suppress InaccessibleProperty Mainly used for caching
     */
    private function calculate_real_location(): void
    {
        if ($this->have_recalculated) {
            return;
        }
        $this->have_recalculated = true;
        $this->selection_start = $this->file_start;
        $this->selection_end = $this->file_end + 1;
        $project_analyzer = Project_Analyzer::get_instance();
        $codebase = $project_analyzer->get_codebase();
        /** @psalm-suppress ImpureMethodCall */
        $file_contents = $codebase->get_file_contents($this->file_path);
        $file_length = strlen($file_contents);
        $search_limit = $this->single_line ? $this->selection_start : $this->selection_end;
        if ($search_limit <= $file_length) {
            $preview_end = strpos($file_contents, "\n", $search_limit);
        } else {
            $preview_end = false;
        }
        // if the string didn't contain a newline
        if ($preview_end === false) {
            $preview_end = $this->selection_end;
        }
        $this->preview_end = $preview_end;
        if ($this->docblock_line_number && $this->docblock_start_line_number && $this->preview_start < $this->selection_start) {
            $preview_lines = explode("\n", mb_strcut($file_contents, $this->preview_start, $this->selection_start - $this->preview_start - 1));
            $preview_offset = 0;
            $comment_line_offset = $this->docblock_line_number - $this->docblock_start_line_number;
            for ($i = 0; $i < $comment_line_offset; ++$i) {
                $preview_offset += strlen($preview_lines[$i]) + 1;
            }
            if (!isset($preview_lines[$i])) {
                throw new Exception('Should have offset');
            }
            $key_line = $preview_lines[$i];
            $indentation = (int) strpos($key_line, '@');
            $key_line = trim((string) preg_replace('@\**/\s*@', '', mb_strcut($key_line, $indentation)));
            $this->selection_start = $preview_offset + $indentation + $this->preview_start;
            $this->selection_end = $this->selection_start + strlen($key_line);
        }
        if ($this->regex_type !== null) {
            $regex = match ($this->regex_type) {
                self::VAR_TYPE => '/@(?:psalm-)?var[ \t]+' . Comment_Analyzer::TYPE_REGEX . '/',
                self::FUNCTION_RETURN_TYPE => '/\:\s+(\??\s*[A-Za-z0-9_\\\\\\[\]]+)/',
                self::FUNCTION_PARAM_TYPE => '/^(\??\s*[A-Za-z0-9_\\\\\\[\]]+)\s/',
                self::FUNCTION_PHPDOC_RETURN_TYPE => '/@(?:psalm-)?return[ \t]+' . Comment_Analyzer::TYPE_REGEX . '/',
                self::FUNCTION_PHPDOC_METHOD => '/@(?:psalm-)?method[ \t]+(.*)/',
                self::FUNCTION_PHPDOC_PARAM_TYPE => '/@(?:psalm-)?param[ \t]+' . Comment_Analyzer::TYPE_REGEX . '/',
                self::FUNCTION_PARAM_VAR => '/(\$[^ ]*)/',
                self::CATCH_VAR => '/(\$[^ ^\)]*)/',
                default => throw new UnexpectedValueException('Unrecognised regex type ' . $this->regex_type),
            };
            $preview_snippet = mb_strcut($file_contents, $this->selection_start, $this->selection_end - $this->selection_start);
            if ($this->text) {
                $regex = '/(' . str_replace(',', ',[ ]*', preg_quote($this->text, '/')) . ')/';
            }
            if (preg_match($regex, $preview_snippet, $matches, PREG_OFFSET_CAPTURE)) {
                if (!isset($matches[1]) || $matches[1][1] === -1) {
                    throw new LogicException("Failed to match anything to 1st capturing group, " . "or regex doesn't contain 1st capturing group, regex type " . $this->regex_type);
                }
                $this->selection_start = $this->selection_start + $matches[1][1];
                $this->selection_end = $this->selection_start + strlen($matches[1][0]);
            }
        }
        // reset preview start to beginning of line
        $this->preview_start = (int) strrpos($file_contents, "\n", min($this->preview_start, $this->selection_start) - strlen($file_contents)) + 1;
        $this->selection_start = max($this->preview_start, $this->selection_start);
        $this->selection_end = min($this->preview_end, $this->selection_end);
        if ($this->preview_end - $this->selection_end > 200) {
            $this->preview_end = (int) strrpos($file_contents, "\n", $this->selection_end + 200 - strlen($file_contents));
            // if the line is over 200 characters long
            if ($this->preview_end < $this->selection_end) {
                $this->preview_end = $this->selection_end + 50;
            }
        }
        $this->snippet = mb_strcut($file_contents, $this->preview_start, $this->preview_end - $this->preview_start);
        // text is within snippet. It's 50% faster to cut it from the snippet than from the full text
        $selection_length = $this->selection_end - $this->selection_start;
        $this->text = mb_strcut($this->snippet, $this->selection_start - $this->preview_start, $selection_length);
        // reset preview start to beginning of line
        if ($file_contents !== '') {
            $this->column_from = $this->selection_start - (int) strrpos($file_contents, "\n", $this->selection_start - strlen($file_contents));
        } else {
            $this->column_from = $this->selection_start;
        }
        $newlines = substr_count($this->text, "\n");
        if ($newlines) {
            $last_newline_pos = strrpos($file_contents, "\n", $this->selection_end - strlen($file_contents) - 1);
            $this->column_to = $this->selection_end - (int) $last_newline_pos;
        } else {
            $this->column_to = $this->column_from + strlen($this->text);
        }
        $this->end_line_number = $this->get_line_number() + $newlines;
    }
    public function get_line_number(): int
    {
        return $this->docblock_line_number ?: $this->raw_line_number;
    }
    public function get_end_line_number(): int
    {
        $this->calculate_real_location();
        return $this->end_line_number;
    }
    public function get_snippet(): string
    {
        $this->calculate_real_location();
        return $this->snippet;
    }
    public function get_selected_text(): string
    {
        $this->calculate_real_location();
        return (string) $this->text;
    }
    public function get_column(): int
    {
        $this->calculate_real_location();
        return $this->column_from;
    }
    public function get_end_column(): int
    {
        $this->calculate_real_location();
        return $this->column_to;
    }
    /**
     * @return array{0: int, 1: int}
     */
    public function get_selection_bounds(): array
    {
        $this->calculate_real_location();
        return [$this->selection_start, $this->selection_end];
    }
    /**
     * @return array{0: int, 1: int}
     */
    public function get_snippet_bounds(): array
    {
        $this->calculate_real_location();
        return [$this->preview_start, $this->preview_end];
    }
    public function get_hash(): string
    {
        return $this->file_name . ' ' . $this->raw_file_start . $this->raw_file_end;
    }
    public function get_short_summary(): string
    {
        return $this->file_name . ':' . $this->get_line_number() . ':' . $this->get_column();
    }
}