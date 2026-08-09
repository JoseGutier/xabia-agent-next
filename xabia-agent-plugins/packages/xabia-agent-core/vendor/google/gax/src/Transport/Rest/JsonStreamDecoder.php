<?php

namespace Google\ApiCore\Transport\Rest;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

class JsonStreamDecoder
{
    const ESCAPE_CHAR = '\\';

    private StreamInterface $stream;
    private bool $closeCalled = false;
    private string $decodeType;
    private bool $ignoreUnknown = true;
    private int $readChunkSize = 1024;

    /**
     * JsonStreamDecoder is a HTTP-JSON response stream decoder for JSON-ecoded
     * protobuf messages. The response stream must be a JSON array, where the first
     * byte is the opening of the array (i.e. '['), and the last byte is the closing
     * of the array (i.e. ']'). Each array item must be a JSON object and comma
     * separated.
     *
     * @param StreamInterface $stream The stream to decode.
     * @param string $decodeType The type name of response messages to decode.
     * @param array<mixed> $options {
     *     An array of optional arguments.
     *
     *     @type bool $ignoreUnknown
     *           Toggles whether or not to throw an exception when an unknown field
     *           is encountered in a response message. The default is true.
     *     @type int $readChunkSizeBytes
     *           The upper size limit in bytes that can be read at a time from the
     *           response stream. The default is 1 KB.
     * }
     *
     * @experimental
     */
    public function __construct(StreamInterface $stream, string $decodeType, array $options = [])
    {
        $this->stream = $stream;
        $this->decodeType = $decodeType;

        if (isset($options['ignoreUnknown'])) {
            $this->ignoreUnknown = $options['ignoreUnknown'];
        }
        if (isset($options['readChunkSize'])) {
            $this->readChunkSize = $options['readChunkSizeBytes'];
        }
    }

    /**
     * Begins decoding the configured response stream. It is a generator which
     * yields messages of the given decode type from the stream until the stream
     * completes. Throws an Exception if the stream is closed before the closing
     * byte is read or if it encounters an error while decoding a message.
     *
     * @throws RuntimeException
     * @return \Generator
     */
    public function decode()
    {
        try {
            foreach ($this->_decode() as $response) {
                yield $response;
            }
        } catch (RuntimeException $re) {
            $msg = $re->getMessage();
            $streamClosedException =
                strpos($msg, 'Stream is detached') !== false ||
                strpos($msg, 'Unexpected stream close') !== false;

            
            
            if (!$this->closeCalled || !$streamClosedException) {
                throw $re;
            }
        }
    }

    /**
     * @return \Generator
     */
    private function _decode()
    {
        $decodeType = $this->decodeType;
        $str = false;
        $prev = $chunk = '';
        $start = $end = $cursor = $level = 0;
        while ($chunk !== '' || !$this->stream->eof()) {
            
            $chunk .= $this->stream->read($this->readChunkSize);

            
            
            if ($this->stream->eof() && $chunk === ']') {
                $level--;
                break;
            }

            
            $chunkLength = strlen($chunk);
            while ($cursor < $chunkLength) {
                
                $b = $chunk[$cursor];

                
                
                if ($b === '"' && $prev !== self::ESCAPE_CHAR) {
                    $str = !$str;
                }

                
                if ($b === "\n" && $level === 1) {
                    $start++;
                }

                
                if ($b === ',' && $level === 1) {
                    $start++;
                }
                
                
                if (($b === '{' || $b === '[') && !$str) {
                    $level++;
                    
                    
                    if ($level === 1) {
                        $start++;
                    }
                }
                
                if ($b === '}' && !$str) {
                    $level--;
                    if ($level === 1) {
                        $end = $cursor + 1;
                    }
                }
                
                if ($b === ']' && !$str) {
                    $level--;
                    
                    
                    if ($level === 1) {
                        throw new \RuntimeException('Received closing byte mid-message');
                    }
                }

                
                
                
                
                
                
                if ($end !== 0) {
                    $length = $end - $start;
                    /** @var \Google\Protobuf\Internal\Message $return */
                    $return = new $decodeType();
                    $return->mergeFromJsonString(
                        substr($chunk, $start, $length),
                        $this->ignoreUnknown
                    );
                    yield $return;

                    
                    
                    $remaining = $chunkLength - $length;
                    $chunk = substr($chunk, $end, $remaining);

                    
                    $start = 0;
                    $end = 0;
                    $cursor = 0;
                    break;
                }

                $cursor++;

                
                if ($b === self::ESCAPE_CHAR && $prev === self::ESCAPE_CHAR) {
                    $b = '';
                }
                $prev = $b;
            }
            
            
            if ($cursor === $chunkLength && $this->stream->eof()) {
                break;
            }
        }
        if ($level > 0) {
            throw new \RuntimeException('Unexpected stream close before receiving the closing byte');
        }
    }

    /**
     * Closes the underlying stream. If the stream is actively being decoded, an
     * exception will not be thrown due to the interruption.
     *
     * @return void
     */
    public function close()
    {
        $this->closeCalled = true;
        $this->stream->close();
    }
}
