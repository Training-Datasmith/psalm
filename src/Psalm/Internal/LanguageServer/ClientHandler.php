<?php

declare (strict_types=1);
namespace Psalm\Internal\Language_Server;

use Advanced_Json_Rpc\Notification;
use Advanced_Json_Rpc\Request;
use Advanced_Json_Rpc\Response;
use Advanced_Json_Rpc\Success_Response;
use Amp\Deferred_Future;
/**
 * @internal
 */
final class Client_Handler
{
    public Id_Generator $id_generator;
    public function __construct(public Protocol_Reader $protocol_reader, public Protocol_Writer $protocol_writer)
    {
        $this->id_generator = new Id_Generator();
    }
    /**
     * Sends a request to the client and returns a promise that is resolved with the result or rejected with the error
     *
     * @param string $method The method to call
     * @param array|object $params The method parameters
     * @return mixed Resolved with the result of the request or rejected with an error
     */
    public function request(string $method, array|object $params): mixed
    {
        $id = $this->id_generator->generate();
        $this->protocol_writer->write(new Message(new Request($id, $method, (object) $params)));
        $deferred = new Deferred_Future();
        $listener = function (Message $msg) use ($id, $deferred, &$listener): void {
            /**
             * @psalm-suppress UndefinedPropertyFetch
             * @psalm-suppress MixedArgument
             */
            if ($msg->body && Response::is_response($msg->body) && $msg->body->id === $id) {
                // Received a response
                $this->protocol_reader->remove_listener('message', $listener);
                if (Success_Response::is_success_response($msg->body)) {
                    $deferred->complete($msg->body->result);
                } else {
                    $deferred->error($msg->body->error);
                }
            }
        };
        $this->protocol_reader->on('message', $listener);
        return $deferred->get_future()->await();
    }
    /**
     * Sends a notification to the client
     *
     * @param string $method The method to call
     * @param array|object $params The method parameters
     */
    public function notify(string $method, array|object $params): void
    {
        $this->protocol_writer->write(new Message(new Notification($method, (object) $params)));
    }
}