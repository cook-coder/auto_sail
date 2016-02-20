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

        // 3. Diary
        if(in_array(date('N'), ['6','7'])){
            echo 'No need for weekend!'.PHP_EOL;
        } else{
            // Check if sailed
            $hasSailed = hasSailed();
            // if not
            if($hasSailed){
                echo 'Diary has been written!'.PHP_EOL;
            } else{
                // Get csrfToken
                $csrfToken = prePost();
                // Create sail article
                $newSailResponse = newSail();
                echo 'Diary created!'.PHP_EOL;
            }
        }
        // 4. logout
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