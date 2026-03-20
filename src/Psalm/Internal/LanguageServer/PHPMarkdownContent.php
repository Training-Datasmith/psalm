<?php

declare (strict_types=1);
namespace Psalm\Internal\Language_Server;

use JsonSerializable;
use Language_Server_Protocol\Markup_Content;
use Language_Server_Protocol\Markup_Kind;
use Override;
use Return_Type_Will_Change;
use function get_object_vars;
/**
 * @psalm-api
 * @internal
 */
final class Php_Markdown_Content extends Markup_Content implements JsonSerializable
{
    public function __construct(public string $code, public ?string $title = null, public ?string $description = null)
    {
        $markdown = '';
        if ($title !== null) {
            $markdown = "**{$title}**\n\n";
        }
        if ($description !== null) {
            $markdown = "{$markdown}{$description}\n\n";
        }
        parent::__construct(Markup_Kind::MARKDOWN, "{$markdown}```php\n<?php declare(strict_types=1);\n{$code}\n```");
    }
    /**
     * This is needed because VSCode Does not like nulls
     * meaning if a null is sent then this will not compute
     */
    #[Override]
    #[Return_Type_Will_Change]
    public function jsonSerialize(): mixed
    {
        $vars = get_object_vars($this);
        unset($vars['title'], $vars['description'], $vars['code']);
        return $vars;
    }
}