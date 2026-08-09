<?php

namespace Grpc;

/**
 * This is an experimental and incomplete implementation of gRPC server
 * for PHP. APIs are _definitely_ going to be changed.
 *
 * DO NOT USE in production.
 */

class ServerCallWriter
{
    public function __construct($call, $serverContext)
    {
        $this->call_ = $call;
        $this->serverContext_ = $serverContext;
    }

    public function start(
        $data = null,
        array $options = []
    ) {
        $batch = [];
        $this->addSendInitialMetadataOpIfNotSent(
            $batch,
            $this->serverContext_->initialMetadata()
        );
        $this->addSendMessageOpIfHasData($batch, $data, $options);
        $this->call_->startBatch($batch);
    }

    public function write(
        $data,
        array $options = []
    ) {
        $batch = [];
        $this->addSendInitialMetadataOpIfNotSent(
            $batch,
            $this->serverContext_->initialMetadata()
        );
        $this->addSendMessageOpIfHasData($batch, $data, $options);
        $this->call_->startBatch($batch);
    }

    public function finish(
        $data = null,
        array $options = []
    ) {
        $batch = [
            OP_SEND_STATUS_FROM_SERVER =>
            $this->serverContext_->status() ?? Status::ok(),
            OP_RECV_CLOSE_ON_SERVER => true,
        ];
        $this->addSendInitialMetadataOpIfNotSent(
            $batch,
            $this->serverContext_->initialMetadata()
        );
        $this->addSendMessageOpIfHasData($batch, $data, $options);
        $this->call_->startBatch($batch);
    }

    

    private function addSendInitialMetadataOpIfNotSent(
        array &$batch,
        ?array $initialMetadata = null
    ) {
        if (!$this->initialMetadataSent_) {
            $batch[OP_SEND_INITIAL_METADATA] = $initialMetadata ?? [];
            $this->initialMetadataSent_ = true;
        }
    }

    private function addSendMessageOpIfHasData(
        array &$batch,
        $data = null,
        array $options = []
    ) {
        if ($data) {
            $message_array = ['message' => $data->serializeToString()];
            if (array_key_exists('flags', $options)) {
                $message_array['flags'] = $options['flags'];
            }
            $batch[OP_SEND_MESSAGE] = $message_array;
        }
    }

    private $call_;
    private $initialMetadataSent_ = false;
    private $serverContext_;
}
