<?php

declare (strict_types=1);
namespace Psalm\Internal\Language_Server;

use Language_Server_Protocol\Message_Type;
/**
 * @internal
 */
final class Client_Configuration
{
    /**
     * TCP Server Address
     */
    public ?string $tcp_server_address = null;
    /**
     * Use TCP in server mode (default is client)
     */
    public ?bool $tcp_server_mode = null;
    /**
     * Debounce time in milliseconds for onChange events
     */
    public ?int $on_change_debounce_ms = null;
    /**
     * Debounce time in milliseconds for onOpen events
     */
    public ?int $on_open_debounce_ms = null;
    /**
     * Undocumented function
     *
     * @param 'always'|'auto'|null $findUnusedCode
     */
    public function __construct(
        /**
         * Hide Warnings or not
         */
        public ?bool $hide_warnings = true,
        /**
         * Provide Completion or not
         */
        public ?bool $provide_completion = null,
        /**
         * Provide GoTo Definitions or not
         */
        public ?bool $provide_definition = null,
        /**
         * Provide Hover Requests or not
         */
        public ?bool $provide_hover = null,
        /**
         * Provide Signature Help or not
         */
        public ?bool $provide_signature_help = null,
        /**
         * Provide Code Actions or not
         */
        public ?bool $provide_code_actions = null,
        /**
         * Provide Diagnostics or not
         */
        public ?bool $provide_diagnostics = null,
        /**
         * Provide Completion or not
         *
         * @psalm-suppress PossiblyUnusedProperty
         */
        public ?bool $find_unused_variables = null,
        /**
         * Look for dead code
         */
        public ?string $find_unused_code = null,
        /**
         * Log Level
         *
         * @see MessageType
         */
        public ?int $log_level = null,
        /**
         * If added, the language server will not respond to onChange events.
         * You can also specify a line count over which Psalm will not run on-change events.
         */
        public ?int $onchange_line_limit = null,
        /**
         * Location of Baseline file
         */
        public ?string $baseline = null
    )
    {
    }
}