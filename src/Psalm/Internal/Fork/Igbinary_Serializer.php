<?php

declare (strict_types=1);
namespace Psalm\Internal\Fork;

use Amp\Serialization\Serialization_Exception;
use Amp\Serialization\Serializer;
use Override;
use Throwable;
use function igbinary_serialize;
use function igbinary_unserialize;
use function sprintf;
/**
 * @internal
 */
final class Igbinary_Serializer implements Serializer
{
    #[Override]
    public function serialize(mixed $data): string
    {
        try {
            $data = igbinary_serialize($data);
            if ($data === false) {
                throw new Serialization_Exception("Could not serialize data!");
            }
            return $data;
        } catch (Throwable $exception) {
            throw new Serialization_Exception(sprintf('The given data could not be serialized: %s', $exception->get_message()), 0, $exception);
        }
    }
    #[Override]
    public function unserialize(string $data): mixed
    {
        try {
            return igbinary_unserialize($data);
        } catch (Throwable $exception) {
            throw new Serialization_Exception('Exception thrown when unserializing data', 0, $exception);
        }
    }
}