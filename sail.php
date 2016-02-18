<?php
require 'vendor/autoload.php';

// 0. Read the env
$dotEnv = new \Dotenv\Dotenv(__DIR__);
$dotEnv->load();

$usernameOrEmail = getenv('AUTO_USERNAMEOREMAIL');
$password = getenv('AUTO_PASSWORD');
$username = getenv('AUTO_USERNAME');
$userAgent = getenv('AUTO_USERAGENT');
$curl = new \anlutro\cURL\cURL;

// 1. Login
$cookies = login();

// 2. Daily CheckIn
dailyCheckIn();

// Check if sailed
$hasSailed = hasSailed();
// if not
if(!$hasSailed){
    // Get csrfToken
    $csrfToken = prePost();
    // Create sail article
    $newSailResponse = newSail();
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
    
    $stringIncSessionId = $loginResponse->getHeaders()['set-cookie'][0];
    $sessionIncArr = split(';', $stringIncSessionId);
    $sessionArr = split('=', $sessionIncArr[0]);
    
    $stringIncToken = $loginResponse->getHeaders()['set-cookie'][1];
    $b3logLatkeIncArr = split(';', $stringIncToken);
    $b3logLatkeArr = split('=', $b3logLatkeIncArr[0]);
    
    $cookies = array(
        $sessionArr[0] => $sessionArr[1],
        $b3logLatkeArr[0] => $b3logLatkeArr[1]
    );
    return $cookies;
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
 * Check if sailed
 * @global \anlutro\cURL\cURL $curl
 * @global string $sessionArr
 * @global strting $userAgent
 * @global string $username
 * @return bool
 */
function hasSailed()
{   
    global $curl, $cookies, $userAgent, $username;
    $myListUrl = fetchFullUrl("/member/". $username);
    $myListResponse = $curl->newRequest('GET', $myListUrl)
            ->setHeader('User-Agent', $userAgent)
            ->setCookies($cookies)->send();
    if($myListResponse->statusCode !== 200){
        throw new Exception('列表读取异常', $myListResponse->statusCode);
    }
    $body = $myListResponse->toArray()['body'];
    $listDivPst = strpos($body, "<div class=\"list\">");
    if($listDivPst === false){
        throw new Exception('列表读取异常', $myListResponse->statusCode);
    }
    $listStart = strpos($body, '<ul>', $listDivPst) + mb_strlen("<ul>");
    $listEnd = strpos($body, "</ul>", $listStart);
    $listPart = substr($body, $listStart, $listEnd - $listStart);
    $firstSailPst = strpos($listPart, getenv('AUTO_TAG'));
    if($firstSailPst === false){
        return false;
    }
    $dateStart = strpos($listPart, "</span>", $firstSailPst);
    $dateEnd = strpos($listPart, "</span>", $dateStart + mb_strlen("</span>"));
    $dateStrWithTime = trim(substr($listPart, $dateStart + mb_strlen("</span>"),
            $dateEnd-$dateStart-mb_strlen("</span>")));
    $dateStr = substr($dateStrWithTime, 0, strpos($dateStrWithTime, " "));
    $today = date('Y-m-d');
    return $today == $dateStr;
}

/**
 * Get csrfToken
 * @global \anlutro\cURL\cURL $curl
 * @global array $cookies
 * @global string $userAgent
 * @return string
 */
function prePost()
{
    global $curl, $cookies, $userAgent;
    $prePostUrl = fetchFullUrl('post');
    $prePostResponse = $curl->newRequest('GET', $prePostUrl, [
        'type' => 4,
        'tags' => getenv('AUTO_TAG'). ',段落'
    ])->setCookies($cookies)->setHeader('User-Agent', $userAgent)->send();
    $body = $prePostResponse->toArray()['body'];
    $startPst = strpos($body, "onclick=\"AddArticle.add(null,'");
    $endPst = strpos($body, "'", $startPst + mb_strlen("onclick=\"AddArticle.add(null,'"));
    $csrfToken = substr($body, $startPst + mb_strlen("onclick=\"AddArticle.add(null,'"), 
            $endPst - $startPst - mb_strlen("onclick=\"AddArticle.add(null,'"));
    return $csrfToken;
}

/**
 * Create a new article
 * @global \anlutro\cURL\cURL $curl
 * @global array $cookies
 * @global string $userAgent
 * @global string $csrfToken
 */
function newSail(){
    global $curl, $cookies, $userAgent, $csrfToken;
    $addArticleUrl = fetchFullUrl('/article');
    $curl->newJsonRequest('POST', $addArticleUrl, [
        'articleTitle' => date('Y-m-d'),
        'articleContent' => '###### ['. getenv('AUTO_CONTENT'). '](https://github.com/breezecoder/auto_sail)',
        'articleTags' => getenv('AUTO_TAG').",段落",
        'articleCommentable' => true,
        'articleType' => 4,
        'articleRewardContent' => '',
        'articleRewardPoint' => ''
    ])->setHeader('Referer', fetchFullUrl('post'))
      ->setHeader('csrfToken', $csrfToken)
      ->setCookies($cookies)->setHeader('User-Agent', $userAgent)->send();
}
