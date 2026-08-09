<?php

namespace Google\Cloud\AIPlatform\V1;

/**
 * A service that migrates resources from automl.googleapis.com,
 * datalabeling.googleapis.com and ml.googleapis.com to Vertex AI.
 */
class MigrationServiceGrpcClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * Searches all of the resources in automl.googleapis.com,
     * datalabeling.googleapis.com and ml.googleapis.com that can be migrated to
     * Vertex AI's given location.
     * @param \Google\Cloud\AIPlatform\V1\SearchMigratableResourcesRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function SearchMigratableResources(\Google\Cloud\AIPlatform\V1\SearchMigratableResourcesRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/google.cloud.aiplatform.v1.MigrationService/SearchMigratableResources',
        $argument,
        ['\Google\Cloud\AIPlatform\V1\SearchMigratableResourcesResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Batch migrates resources from ml.googleapis.com, automl.googleapis.com,
     * and datalabeling.googleapis.com to Vertex AI.
     * @param \Google\Cloud\AIPlatform\V1\BatchMigrateResourcesRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function BatchMigrateResources(\Google\Cloud\AIPlatform\V1\BatchMigrateResourcesRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/google.cloud.aiplatform.v1.MigrationService/BatchMigrateResources',
        $argument,
        ['\Google\LongRunning\Operation', 'decode'],
        $metadata, $options);
    }

}
