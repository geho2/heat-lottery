<?php
// HEAT lucky node lottery code written by user gh2 at heatslack.herokuapp.com, May 2017.
// The functions curl_get and curl_post were written by David from Code2Design.com
// See user contributed notes at: http://php.net/manual/en/function.curl-exec.php

function curl_post($url, array $post = NULL, array $options = array()) {
    $defaults = array(
        CURLOPT_POST => 1,
        CURLOPT_HEADER => 0,
        CURLOPT_URL => $url,
        CURLOPT_FRESH_CONNECT => 1,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_FORBID_REUSE => 1,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => FALSE,
        CURLOPT_POSTFIELDS => http_build_query($post)
    );

    $ch = curl_init();
    curl_setopt_array($ch, ($options + $defaults));
    if( ! $result = curl_exec($ch))
    {
        trigger_error(curl_error($ch));
    }
    curl_close($ch);
    return $result;
}

function curl_get($url, array $get = NULL, array $options = array()) {
    $defaults = array(
        CURLOPT_URL => $url. (strpos($url, '?') === FALSE ? '?' : ''). http_build_query($get),
        CURLOPT_HEADER => 0,
        CURLOPT_RETURNTRANSFER => TRUE,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => FALSE
    );

    $ch = curl_init();
    curl_setopt_array($ch, ($options + $defaults));
    if( ! $result = curl_exec($ch))
    {
        trigger_error(curl_error($ch));
    }
    curl_close($ch);
    return $result;
}

function make_seed() {
  list($usec, $sec) = explode(' ', microtime());
  return $sec + $usec * 1000000;
}

$payout_per_day=50;
$arrContextOptions=array("ssl"=>array("verify_peer"=>false,"verify_peer_name"=>false));
$api_key='myapikey';
$mysecret = 'my secret phrase';
$data = array();
$options = array();
$mysql_pwd='mysql_password';
$mysql_db='heat';
$lottery_version = '1.0.3';

$link = mysqli_connect("127.0.0.1","root",$mysql_pwd,$mysql_db);
mysqli_select_db($link, $mysql_db);

$query='SELECT * from lottery order by height desc LIMIT 1';
$result = $link->query($query);
$row = $result->fetch_assoc();

$participants = array();
$N_participants=0;

// Fill up an array of lottery participants who satisfy conditions (server version, platform and state)
$url='https://heatwallet.com/nodes.cgi';
$result = file_get_contents($url, false, stream_context_create($arrContextOptions));
$api = json_decode($result);
$N=sizeof($api);
for($i=0; $i<$N; $i++) {
	$address=$api[$i]->{'address'};
	$platform=$api[$i]->{'platform'};
	$hallmark=$api[$i]->{'hallmark'};
	$version=$api[$i]->{'version'};
	$state=$api[$i]->{'state'};
	if(ctype_digit($platform) and (strcmp($version,$lottery_version)==0) and ($state<=1)) {
		$participants[] = array($address,$platform,$hallmark);
		$N_participants++;
	}
}

// Select lottery winner. Previous winner (from mysql database) is excluded.
srand(make_seed());
$bFound=false;
$count=0;
while(!$bFound) {
	$N_winner=rand(0,$N_participants-1);
	$winner=$participants[$N_winner][1];
	$ip=$participants[$N_winner][0];
	if((strlen($winner)>8) and (strcmp($winner,$row['recipient'])<>0)) {
		$hallmark=$participants[$N_winner][2];
		$bFound=true;

		// Check if platform is a valid account number
		$url=sprintf('http://localhost:7733/api/v1/account/find/%s',$winner);
		$result=curl_get($url,$data,$options);
		$api=json_decode($result);
		if(isset($api->{'errorDescription'})) {
			$bFound=false;
			sleep(1);
		}
	}
	$count++;
        if($count>50) {
		exit;
	}
}
$amount = rand(500,1500)/1000;
$amount = $amount * $payout_per_day / 24;
if(strlen($hallmark)>100) {
	$amount = $amount * 1.25;
}
$amount=sprintf('%1.8f',$amount);
printf("The winner is %s out of %s, amount=%s, account=%s" . PHP_EOL,$N_winner,$N_participants,$amount,$winner);

$url='http://localhost:7733/api/v1/tx/sendmoney';
$post = [
    'secretPhrase' => $mysecret,
    'amount' => $amount*1e8,
    'deadline'   => 1440,
    'fee' => 1000000,
    'recipient' => $winner,
    'broadcast' => true,
    'message' => 'heatnodes.org lottery winner - congratulations',
    'messageIsText' => true,
    'messageToEncryptIsText' => true,
    'messageToEncryptToSelfIsText' => true
];
$result = curl_post($url,$post,$options);
$link->close();
?>
