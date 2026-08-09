<?php
chdir(dirname(__FILE__));
require '../vendor/autoload.php';

$firestore_probes = require './firestore_probes.php';
$spanner_probes = require './spanner_probes.php';
require './stackdriver_util.php';

$_OAUTH_SCOPE = 'https://www.googleapis.com/auth/cloud-platform';
$_FIRESTORE_TARGET = 'firestore.googleapis.com:443';
$_SPANNER_TARGET = 'spanner.googleapis.com:443';

use Google\Auth\ApplicationDefaultCredentials;
use Google\Cloud\Firestore\V1beta1\FirestoreGrpcClient;
use Google\Cloud\Spanner\V1\SpannerGrpcClient;

function getArgs(){
	$options = getopt('',['api:','extension:']);
	return $options;
}

function executeProbes($api){
	global $_OAUTH_SCOPE;
	global $_SPANNER_TARGET;
	global $_FIRESTORE_TARGET;

	global $spanner_probes;
	global $firestore_probes;

	$util = new StackdriverUtil($api);
	$auth = Google\Auth\ApplicationDefaultCredentials::getCredentials($_OAUTH_SCOPE);
	$opts = [
  		'credentials' => Grpc\ChannelCredentials::createSsl(),
  		'update_metadata' => $auth->getUpdateMetadataFunc(),
	];

	if($api == 'spanner'){
		$client = new SpannerGrpcClient($_SPANNER_TARGET, $opts);
		$probe_functions = $spanner_probes;
	}
	else if($api == 'firestore'){
		$client = new FirestoreGrpcClient($_FIRESTORE_TARGET, $opts);
		$probe_functions = $firestore_probes;
	}
	else{
		echo 'grpc not implemented for '.$api;
		exit(1);
	}

	$total = sizeof($probe_functions);
	$success = 0;
	$metrics = [];

	
	foreach ($probe_functions as $probe_name => $probe_function) {
		try{
			$probe_function($client, $metrics);
			$success++;
		}
		catch(Exception $e){
			$util->reportError($e);
		}

	}

	if($success == $total){
		$util->setSuccess(True);
	}

	$util->addMetrics($metrics);
	$util->outputMetrics();

	if($success != $total){
		
		exit(1);
	}

}

function main(){
	$args = getArgs();
	executeProbes($args['api']);
}

main();
