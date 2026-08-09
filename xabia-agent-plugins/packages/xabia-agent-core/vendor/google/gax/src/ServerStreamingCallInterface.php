<?php

namespace Google\ApiCore;

/**
 * @internal
 */
interface ServerStreamingCallInterface
{

    /**
     * Start the call.
     *
     * @param mixed $data     The data to send
     * @param array<mixed> $metadata Metadata to send with the call, if applicable
     *                        (optional)
     * @param array<mixed> $options  An array of options, possible keys:
     *                        'flags' => a number (optional)
     * @return void
     */
    public function start($data, array $metadata = [], array $options = []);

    /**
     * @return mixed An iterator of response values.
     */
    public function responses();

    /**
     * Return the status of the server stream.
     *
     * @return \stdClass The API status.
     */
    public function getStatus();

    /**
     * @return mixed The metadata sent by the server.
     */
    public function getMetadata();

    /**
     * @return mixed The trailing metadata sent by the server.
     */
    public function getTrailingMetadata();

    /**
     * @return string The URI of the endpoint.
     */
    public function getPeer();

    /**
     * Cancels the call.
     *
     * @return void
     */
    public function cancel();

    /**
     * Set the CallCredentials for the underlying Call.
     *
     * @param mixed $call_credentials The CallCredentials object
     *
     * @return void
     */
    public function setCallCredentials($call_credentials);
}
