<?php

declare (strict_types=1);
namespace Psalm\Internal\Scanner;

use function explode;
use function trim;
/**
 * @internal
 */
final class Parsed_Docblock
{
    /** @var array<string, array<int, string>> */
    public array $combined_tags = [];
    private static bool $should_add_new_line_between_annotations = true;
    /** @param array<string, array<int, string>> $tags */
    public function __construct(public string $description, public array $tags, public string $first_line_padding = '')
    {
    }
    public function render(string $left_padding): string
    {
        $doc_comment_text = '/**' . "\n";
        $trimmed_description = trim($this->description);
        if ($trimmed_description !== '') {
            $description_lines = explode("\n", $this->description);
            foreach ($description_lines as $line) {
                $doc_comment_text .= $left_padding . ' *' . (trim($line) ? ' ' . $line : '') . "\n";
            }
        }
        if ($this->tags) {
            if ($trimmed_description !== '') {
                $doc_comment_text .= $left_padding . ' *' . "\n";
            }
            $last_type = null;
            foreach ($this->tags as $type => $lines) {
                if ($last_type !== null && $last_type !== 'psalm-return' && static::should_add_new_line_between_annotations()) {
                    $doc_comment_text .= $left_padding . ' *' . "\n";
                }
                foreach ($lines as $line) {
                    $doc_comment_text .= $left_padding . ' * @' . $type . ($line !== '' ? ' ' . $line : '') . "\n";
                }
                $last_type = $type;
            }
        }
        return $doc_comment_text . ($left_padding . ' */' . "\n" . $left_padding);
    }
    private static function should_add_new_line_between_annotations(): bool
    {
        return self::$should_add_new_line_between_annotations;
    }
    /**
     * Sets whether a new line should be added between the annotations or not.
     */
    public static function add_new_line_between_annotations(bool $should = true): void
    {
        self::$should_add_new_line_between_annotations = $should;
    }
    public static function reset_newline_between_annotations(): void
    {
        self::$should_add_new_line_between_annotations = true;
    }
}