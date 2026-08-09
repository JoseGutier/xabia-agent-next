<?php

namespace Google\Cloud\AIPlatform\V1;

/**
 * A service for serving online feature values.
 */
class FeaturestoreOnlineServingServiceGrpcClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * Reads Feature values of a specific entity of an EntityType. For reading
     * feature values of multiple entities of an EntityType, please use
     * StreamingReadFeatureValues.
     * @param \Google\Cloud\AIPlatform\V1\ReadFeatureValuesRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function ReadFeatureValues(\Google\Cloud\AIPlatform\V1\ReadFeatureValuesRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/google.cloud.aiplatform.v1.FeaturestoreOnlineServingService/ReadFeatureValues',
        $argument,
        ['\Google\Cloud\AIPlatform\V1\ReadFeatureValuesResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Reads Feature values for multiple entities. Depending on their size, data
     * for different entities may be broken
     * up across multiple responses.
     * @param \Google\Cloud\AIPlatform\V1\StreamingReadFeatureValuesRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\ServerStreamingCall
     */
    public function StreamingReadFeatureValues(\Google\Cloud\AIPlatform\V1\StreamingReadFeatureValuesRequest $argument,
      $metadata = [], $options = []) {
        return $this->_serverStreamRequest('/google.cloud.aiplatform.v1.FeaturestoreOnlineServingService/StreamingReadFeatureValues',
        $argument,
        ['\Google\Cloud\AIPlatform\V1\ReadFeatureValuesResponse', 'decode'],
        $metadata, $options);
    }

}
