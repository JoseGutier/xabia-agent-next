<?php

namespace Google\Cloud\AIPlatform\V1;

/**
 * The service that handles the CRUD of Vertex AI Dataset and its child
 * resources.
 */
class DatasetServiceGrpcClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * Creates a Dataset.
     * @param \Google\Cloud\AIPlatform\V1\CreateDatasetRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function CreateDataset(\Google\Cloud\AIPlatform\V1\CreateDatasetRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/google.cloud.aiplatform.v1.DatasetService/CreateDataset',
        $argument,
        ['\Google\LongRunning\Operation', 'decode'],
        $metadata, $options);
    }

    /**
     * Gets a Dataset.
     * @param \Google\Cloud\AIPlatform\V1\GetDatasetRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function GetDataset(\Google\Cloud\AIPlatform\V1\GetDatasetRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/google.cloud.aiplatform.v1.DatasetService/GetDataset',
        $argument,
        ['\Google\Cloud\AIPlatform\V1\Dataset', 'decode'],
        $metadata, $options);
    }

    /**
     * Updates a Dataset.
     * @param \Google\Cloud\AIPlatform\V1\UpdateDatasetRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function UpdateDataset(\Google\Cloud\AIPlatform\V1\UpdateDatasetRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/google.cloud.aiplatform.v1.DatasetService/UpdateDataset',
        $argument,
        ['\Google\Cloud\AIPlatform\V1\Dataset', 'decode'],
        $metadata, $options);
    }

    /**
     * Lists Datasets in a Location.
     * @param \Google\Cloud\AIPlatform\V1\ListDatasetsRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function ListDatasets(\Google\Cloud\AIPlatform\V1\ListDatasetsRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/google.cloud.aiplatform.v1.DatasetService/ListDatasets',
        $argument,
        ['\Google\Cloud\AIPlatform\V1\ListDatasetsResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Deletes a Dataset.
     * @param \Google\Cloud\AIPlatform\V1\DeleteDatasetRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function DeleteDataset(\Google\Cloud\AIPlatform\V1\DeleteDatasetRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/google.cloud.aiplatform.v1.DatasetService/DeleteDataset',
        $argument,
        ['\Google\LongRunning\Operation', 'decode'],
        $metadata, $options);
    }

    /**
     * Imports data into a Dataset.
     * @param \Google\Cloud\AIPlatform\V1\ImportDataRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function ImportData(\Google\Cloud\AIPlatform\V1\ImportDataRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/google.cloud.aiplatform.v1.DatasetService/ImportData',
        $argument,
        ['\Google\LongRunning\Operation', 'decode'],
        $metadata, $options);
    }

    /**
     * Exports data from a Dataset.
     * @param \Google\Cloud\AIPlatform\V1\ExportDataRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function ExportData(\Google\Cloud\AIPlatform\V1\ExportDataRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/google.cloud.aiplatform.v1.DatasetService/ExportData',
        $argument,
        ['\Google\LongRunning\Operation', 'decode'],
        $metadata, $options);
    }

    /**
     * Lists DataItems in a Dataset.
     * @param \Google\Cloud\AIPlatform\V1\ListDataItemsRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function ListDataItems(\Google\Cloud\AIPlatform\V1\ListDataItemsRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/google.cloud.aiplatform.v1.DatasetService/ListDataItems',
        $argument,
        ['\Google\Cloud\AIPlatform\V1\ListDataItemsResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Gets an AnnotationSpec.
     * @param \Google\Cloud\AIPlatform\V1\GetAnnotationSpecRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function GetAnnotationSpec(\Google\Cloud\AIPlatform\V1\GetAnnotationSpecRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/google.cloud.aiplatform.v1.DatasetService/GetAnnotationSpec',
        $argument,
        ['\Google\Cloud\AIPlatform\V1\AnnotationSpec', 'decode'],
        $metadata, $options);
    }

    /**
     * Lists Annotations belongs to a dataitem
     * @param \Google\Cloud\AIPlatform\V1\ListAnnotationsRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function ListAnnotations(\Google\Cloud\AIPlatform\V1\ListAnnotationsRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/google.cloud.aiplatform.v1.DatasetService/ListAnnotations',
        $argument,
        ['\Google\Cloud\AIPlatform\V1\ListAnnotationsResponse', 'decode'],
        $metadata, $options);
    }

}
