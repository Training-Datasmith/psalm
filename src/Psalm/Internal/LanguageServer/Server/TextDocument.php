<?php

declare (strict_types=1);
namespace Psalm\Internal\Language_Server\Server;

use Language_Server_Protocol\Code_Action;
use Language_Server_Protocol\Code_Action_Context;
use Language_Server_Protocol\Code_Action_Kind;
use Language_Server_Protocol\Completion_List;
use Language_Server_Protocol\Hover;
use Language_Server_Protocol\Location;
use Language_Server_Protocol\Position;
use Language_Server_Protocol\Range;
use Language_Server_Protocol\Signature_Help;
use Language_Server_Protocol\Text_Document_Content_Change_Event;
use Language_Server_Protocol\Text_Document_Identifier;
use Language_Server_Protocol\Text_Document_Item;
use Language_Server_Protocol\Text_Edit;
use Language_Server_Protocol\Versioned_Text_Document_Identifier;
use Language_Server_Protocol\Workspace_Edit;
use Psalm\Codebase;
use Psalm\Exception\Type_Parse_Tree_Exception;
use Psalm\Exception\Unanalyzed_File_Exception;
use Psalm\Internal\Analyzer\Project_Analyzer;
use Psalm\Internal\Language_Server\Language_Server;
use UnexpectedValueException;
use function array_values;
use function count;
use function preg_match;
use function substr_count;
/**
 * Provides method handlers for all textDocument/* methods
 *
 * @internal
 */
final class Text_Document
{
    public function __construct(protected Language_Server $server, protected Codebase $codebase, protected Project_Analyzer $project_analyzer)
    {
    }
    /**
     * The document open notification is sent from the client to the server to signal newly opened text documents. The
     * document’s content is now managed by the client and the server must not try to read the document’s content using
     * the document’s Uri. Open in this sense means it is managed by the client. It doesn’t necessarily mean that its
     * content is presented in an editor. An open notification must not be sent more than once without a corresponding
     * close notification send before. This means open and close notification must be balanced and the max open count
     * for a particular textDocument is one. Note that a server’s ability to fulfill requests is independent of whether
     * a text document is open or closed.
     *
     * @param TextDocumentItem $textDocument the document that was opened
     */
    public function did_open(Text_Document_Item $text_document): void
    {
        $this->server->log_debug('textDocument/didOpen', ['version' => $text_document->version, 'uri' => $text_document->uri]);
        $file_path = $this->server->uri_to_path($text_document->uri);
        $this->codebase->remove_temporary_file_changes($file_path);
        $this->codebase->file_provider->open_file($file_path);
        $this->codebase->file_provider->set_open_contents($file_path, $text_document->text);
        $this->server->queue_open_file_analysis($file_path, $text_document->uri, $text_document->version);
    }
    /**
     * The document save notification is sent from the client to the server when the document was saved in the client
     *
     * @param TextDocumentIdentifier $textDocument the document that was opened
     * @param string|null $text Optional the content when saved. Depends on the includeText value
     *                          when the save notification was requested.
     */
    public function did_save(Text_Document_Identifier $text_document, ?string $text = null): void
    {
        $this->server->log_debug('textDocument/didSave', ['uri' => (array) $text_document]);
        $file_path = $this->server->uri_to_path($text_document->uri);
        // reopen file
        $this->codebase->remove_temporary_file_changes($file_path);
        $this->codebase->file_provider->set_open_contents($file_path, $text);
        $this->server->queue_save_file_analysis($file_path, $text_document->uri);
    }
    /**
     * The document change notification is sent from the client to the server to signal changes to a text document.
     *
     * @param VersionedTextDocumentIdentifier $textDocument the document that was changed
     * @param TextDocumentContentChangeEvent[] $contentChanges
     */
    public function did_change(Versioned_Text_Document_Identifier $text_document, array $content_changes): void
    {
        $this->server->log_debug('textDocument/didChange', ['version' => $text_document->version, 'uri' => $text_document->uri]);
        $file_path = $this->server->uri_to_path($text_document->uri);
        if (count($content_changes) === 1 && isset($content_changes[0]) && $content_changes[0]->range === null) {
            $new_content = $content_changes[0]->text;
        } else {
            throw new UnexpectedValueException('Not expecting partial diff');
        }
        if ($this->project_analyzer->onchange_line_limit !== null) {
            if (substr_count((string) $new_content, "\n") > $this->project_analyzer->onchange_line_limit) {
                return;
            }
        }
        $this->codebase->add_temporary_file_changes($file_path, $new_content, $text_document->version);
        $this->server->queue_change_file_analysis($file_path, $text_document->uri, $text_document->version);
    }
    /**
     * The document close notification is sent from the client to the server when the document got closed in the client.
     * The document’s master now exists where the document’s Uri points to (e.g. if the document’s Uri is a file Uri the
     * master now exists on disk). As with the open notification the close notification is about managing the document’s
     * content. Receiving a close notification doesn’t mean that the document was open in an editor before. A close
     * notification requires a previous open notification to be sent. Note that a server’s ability to fulfill requests
     * is independent of whether a text document is open or closed.
     *
     * @param TextDocumentIdentifier $textDocument The document that was closed
     */
    public function did_close(Text_Document_Identifier $text_document): void
    {
        $this->server->log_debug('textDocument/didClose', ['uri' => $text_document->uri]);
        $file_path = $this->server->uri_to_path($text_document->uri);
        $this->codebase->file_provider->close_file($file_path);
        $this->server->client->text_document->publish_diagnostics($text_document->uri, []);
    }
    /**
     * The goto definition request is sent from the client to the server to resolve the definition location of a symbol
     * at a given text document position.
     *
     * @param TextDocumentIdentifier $textDocument The text document
     * @param Position $position The position inside the text document
     */
    public function definition(Text_Document_Identifier $text_document, Position $position): ?Location
    {
        if (!$this->server->client->client_configuration->provide_definition) {
            return null;
        }
        $this->server->log_debug('textDocument/definition');
        $file_path = $this->server->uri_to_path($text_document->uri);
        //This currently doesnt work right with out of project files
        if (!$this->codebase->config->is_in_project_dirs($file_path)) {
            return null;
        }
        try {
            $reference = $this->codebase->get_reference_at_position_as_reference($file_path, $position);
        } catch (Unanalyzed_File_Exception $e) {
            $this->server->log_throwable($e);
            return null;
        }
        if ($reference === null) {
            return null;
        }
        $code_location = $this->codebase->get_symbol_location_by_reference($reference);
        if (!$code_location) {
            return null;
        }
        return new Location($this->server->path_to_uri($code_location->file_path), new Range(new Position($code_location->get_line_number() - 1, $code_location->get_column() - 1), new Position($code_location->get_end_line_number() - 1, $code_location->get_end_column() - 1)));
    }
    /**
     * The hover request is sent from the client to the server to request
     * hover information at a given text document position.
     *
     * @param TextDocumentIdentifier $textDocument The text document
     * @param Position $position The position inside the text document
     */
    public function hover(Text_Document_Identifier $text_document, Position $position): ?Hover
    {
        if (!$this->server->client->client_configuration->provide_hover) {
            return null;
        }
        $this->server->log_debug('textDocument/hover');
        $file_path = $this->server->uri_to_path($text_document->uri);
        //This currently doesnt work right with out of project files
        if (!$this->codebase->config->is_in_project_dirs($file_path)) {
            return null;
        }
        try {
            $reference = $this->codebase->get_reference_at_position_as_reference($file_path, $position);
        } catch (Unanalyzed_File_Exception $e) {
            $this->server->log_throwable($e);
            return null;
        }
        if ($reference === null) {
            return null;
        }
        try {
            $markup = $this->codebase->get_markup_content_for_symbol_by_reference($reference);
        } catch (UnexpectedValueException $e) {
            $this->server->log_throwable($e);
            return null;
        }
        if ($markup === null) {
            return null;
        }
        return new Hover($markup, $reference->range);
    }
    /**
     * The Completion request is sent from the client to the server to compute completion items at a given cursor
     * position. Completion items are presented in the IntelliSense user interface. If computing full completion items
     * is expensive, servers can additionally provide a handler for the completion item resolve request
     * ('completionItem/resolve'). This request is sent when a completion item is selected in the user interface. A
     * typically use case is for example: the 'textDocument/completion' request doesn't fill in the documentation
     * property for returned completion items since it is expensive to compute. When the item is selected in the user
     * interface then a 'completionItem/resolve' request is sent with the selected completion item as a param. The
     * returned completion item should have the documentation property filled in.
     *
     * @param TextDocumentIdentifier $textDocument The text document
     * @param Position $position The position
     */
    public function completion(Text_Document_Identifier $text_document, Position $position): ?Completion_List
    {
        if (!$this->server->client->client_configuration->provide_completion) {
            return null;
        }
        $this->server->log_debug('textDocument/completion');
        $file_path = $this->server->uri_to_path($text_document->uri);
        //This currently doesnt work right with out of project files
        if (!$this->codebase->config->is_in_project_dirs($file_path)) {
            return null;
        }
        try {
            $completion_data = $this->codebase->get_completion_data_at_position($file_path, $position);
            if ($completion_data) {
                [$recent_type, $gap, $offset] = $completion_data;
                if ($gap === '->' || $gap === '::') {
                    $snippet_support = $this->server->client_capabilities->text_document->completion->completion_item->snippet_support ?? false;
                    $completion_items = $this->codebase->get_completion_items_for_classish_thing($recent_type, $gap, $snippet_support);
                } elseif ($gap === '[') {
                    $completion_items = $this->codebase->get_completion_items_for_array_keys($recent_type);
                } else {
                    $completion_items = $this->codebase->get_completion_items_for_partial_symbol($recent_type, $offset, $file_path);
                }
                return new Completion_List($completion_items, false);
            }
        } catch (Unanalyzed_File_Exception|Type_Parse_Tree_Exception $e) {
            $this->server->log_throwable($e);
            return null;
        }
        try {
            $type_context = $this->codebase->get_type_context_at_position($file_path, $position);
            if ($type_context) {
                $completion_items = $this->codebase->get_completion_items_for_type($type_context);
                return new Completion_List($completion_items, false);
            }
        } catch (UnexpectedValueException|Type_Parse_Tree_Exception $e) {
            $this->server->log_throwable($e);
            return null;
        }
        $this->server->log_error('completion not found at ' . $position->line . ':' . $position->character);
        return null;
    }
    /**
     * The signature help request is sent from the client to the server to request signature
     * information at a given cursor position.
     */
    public function signature_help(Text_Document_Identifier $text_document, Position $position): ?Signature_Help
    {
        if (!$this->server->client->client_configuration->provide_signature_help) {
            return null;
        }
        $this->server->log_debug('textDocument/signatureHelp');
        $file_path = $this->server->uri_to_path($text_document->uri);
        //This currently doesnt work right with out of project files
        if (!$this->codebase->config->is_in_project_dirs($file_path)) {
            return null;
        }
        try {
            $argument_location = $this->codebase->get_function_argument_at_position($file_path, $position);
        } catch (Unanalyzed_File_Exception $e) {
            $this->server->log_throwable($e);
            return null;
        }
        if ($argument_location === null) {
            return null;
        }
        try {
            $signature_information = $this->codebase->get_signature_information($argument_location[0], $file_path);
        } catch (UnexpectedValueException $e) {
            $this->server->log_throwable($e);
            return null;
        }
        if (!$signature_information) {
            return null;
        }
        return new Signature_Help([$signature_information], 0, $argument_location[1]);
    }
    /**
     * The code action request is sent from the client to the server to compute commands
     * for a given text document and range. These commands are typically code fixes to
     * either fix problems or to beautify/refactor code.
     */
    public function code_action(Text_Document_Identifier $text_document, Code_Action_Context $context): ?array
    {
        if (!$this->server->client->client_configuration->provide_code_actions) {
            return null;
        }
        $this->server->log_debug('textDocument/codeAction');
        $file_path = $this->server->uri_to_path($text_document->uri);
        //Don't report code actions for files we arent watching
        if (!$this->codebase->config->is_in_project_dirs($file_path)) {
            return null;
        }
        $fixers = [];
        foreach ($context->diagnostics as $diagnostic) {
            if ($diagnostic->source !== 'psalm') {
                continue;
            }
            /** @var array{type: string, snippet: string, line_from: int, line_to: int} */
            $data = (array) $diagnostic->data;
            //$file_path = $this->server->uriToPath($textDocument->uri);
            //$contents = $this->codebase->file_provider->getContents($file_path);
            $snippet_range = new Range(new Position($data['line_from'] - 1, 0), new Position($data['line_to'], 0));
            $indentation = '';
            if (preg_match('/^(\s*)/', $data['snippet'], $matches)) {
                $indentation = $matches[1] ?? '';
            }
            //Suppress Ability
            $fixers["suppress.{$data['type']}"] = new Code_Action("Suppress {$data['type']} for this line", Code_Action_Kind::QUICK_FIX, null, null, null, new Workspace_Edit([$text_document->uri => [new Text_Edit($snippet_range, "{$indentation}/** @psalm-suppress {$data['type']} */\n" . "{$data['snippet']}\n")]]));
        }
        if (empty($fixers)) {
            return null;
        }
        return array_values($fixers);
    }
}