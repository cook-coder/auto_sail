<?php
require 'vendor/autoload.php';

// 0. Read the env
$dotEnv = new \Dotenv\Dotenv(__DIR__);
$dotEnv->load();

$usernameOrEmails = explode(',', getenv('AUTO_USERNAMEOREMAIL'));
$passwords = explode(',', getenv('AUTO_PASSWORD'));
$usernames = explode(',', getenv('AUTO_USERNAME'));
$userAgent = getenv('AUTO_USERAGENT');
$curl = new \anlutro\cURL\cURL;

if(sizeof($usernameOrEmails) === sizeof($passwords) 
    && sizeof($usernameOrEmails) === sizeof($usernames)){
    for($i = 0; $i < sizeof($usernames); $i++) {
        
        $usernameOrEmail = $usernameOrEmails[$i];
        $password = $passwords[$i];
        $username = $usernames[$i];
        // 1. Login
        $cookies = login();
        if($cookies === false){
            continue;
        }
        // 2. Daily CheckIn
        dailyCheckIn();
        // 3. logout
        logout();
    }
} else{
    echo 'Data Config Error!'.PHP_EOL;
}




/**
 * Make up the full request url 
 * @param string $suffix
 * @return string
 */
function fetchFullUrl($suffix)
{
    $domain = "http://". getenv('AUTO_DOMAIN');
    if($suffix[0] == '/'){
        return $domain. $suffix;
    } else{
        return $domain. "/". $suffix;
    }
}

/**
 * Login and return the session
 * @global \anlutro\cURL\cURL $curl
 * @global string $usernameOrEmail
 * @global string $password
 * @global string $userAgent
 * @return array
 */
function login()
{
    global $curl, $usernameOrEmail, $password, $userAgent;
    $loginUrl = fetchFullUrl('login');
    $loginResponse = $curl->newJsonRequest('POST', $loginUrl, 
            ['nameOrEmail'=> $usernameOrEmail, 'userPassword'=>  md5($password)])
            ->setHeader('User-Agent', $userAgent)
            ->send();
    if($loginResponse->statusCode == 200){
        $body = $loginResponse->body;
        $bodyArray = json_decode($body, true);
        if(isset($bodyArray['sc']) && $bodyArray['sc'] === true){
            $stringIncSessionId = $loginResponse->getHeaders()['set-cookie'][0];
            $sessionIncArr = explode(';', $stringIncSessionId);
            $sessionArr = explode('=', $sessionIncArr[0]);

            $stringIncToken = $loginResponse->getHeaders()['set-cookie'][1];
            $b3logLatkeIncArr = explode(';', $stringIncToken);
            $b3logLatkeArr = explode('=', $b3logLatkeIncArr[0]);

            $cookies = array(
                $sessionArr[0] => $sessionArr[1],
                $b3logLatkeArr[0] => $b3logLatkeArr[1]
            );
            return $cookies;
        } else{
            return false;
        }
    }
}

/**
 * 
 * @global \anlutro\cURL\cURL $curl
 * @global strting $userAgent
 * @global string $sessionArr
 */
function dailyCheckIn()
{
    global $curl, $userAgent, $cookies;
    $checkInUrl = fetchFullUrl('/activity/daily-checkin');
    $curl->newRequest('GET', $checkInUrl)
        ->setHeader('User-Agent', $userAgent)
        ->setCookies($cookies)->send();
}

/**
 * Logout
 * @global \anlutro\cURL\cURL $curl
 * @global array $cookies
 * @global strting $userAgent
 */
function logout()
{
    global $curl, $cookies, $userAgent;
    $logoutUrl = fetchFullUrl('/logout?goto='.fetchFullUrl('/'));
    $logoutResponse = $curl->newRequest('GET', $logoutUrl)
            ->setCookies($cookies)->setHeader('User-Agent', $userAgent)->send();
}