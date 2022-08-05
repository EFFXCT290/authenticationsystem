<?php
//error_reporting(0);
include '../../includes/misc/autoload.phtml';
include '../../includes/api/shared/autoload.phtml';
include '../../includes/api/1.0/autoload.phtml';

if (isset($_SERVER['HTTP_CDN_HOST'])) {
	ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);	
    $row = misc\cache\fetch('KeyAuthApp:' . misc\etc\sanitize($_SERVER['HTTP_CDN_HOST']), "SELECT * FROM `apps` WHERE `customDomainAPI` = '" . misc\etc\sanitize($_SERVER['HTTP_CDN_HOST']) . "'", 0);
} else {
    $ownerid = misc\etc\sanitize($_POST['ownerid'] ?? $_GET['ownerid']); // ownerid of account that owns application
    $name = misc\etc\sanitize($_POST['name'] ?? $_GET['name']); // application name
    $row = misc\cache\fetch('KeyAuthApp:' . $name . ':' . $ownerid, "SELECT * FROM `apps` WHERE `ownerid` = '$ownerid' AND `name` = '$name'", 0);
}

if ($row == "not_found") {
    die("KeyAuth_Invalid");
}

// app settings
$secret = $row['secret'];
$hwidenabled = $row['hwidcheck'];
$vpnblock = $row['vpnblock'];
$status = $row['enabled'];
$paused = $row['paused'];
$currentver = $row['ver'];
$download = $row['download'];
$webhook = $row['webhook'];
$appdisabled = $row['appdisabled'];
$hashcheck = $row['hashcheck'];
$serverhash = $row['hash'];

$banned = $row['banned'];
$owner = $row['owner'];

// custom error messages
$usernametaken = $row['usernametaken'];
$keynotfound = $row['keynotfound'];
$keyused = $row['keyused'];
$nosublevel = $row['nosublevel'];
$usernamenotfound = $row['usernamenotfound'];
$passmismatch = $row['passmismatch'];
$hwidmismatch = $row['hwidmismatch'];
$noactivesubs = $row['noactivesubs'];
$hwidblacked = $row['hwidblacked'];
$pausedsub = $row['pausedsub'];
$keyexpired = $row['keyexpired'];
$vpnblocked = $row['vpnblocked'];
$keybanned = $row['keybanned'];
$userbanned = $row['userbanned'];
$sessionunauthed = $row['sessionunauthed'];
$hashcheckfail = $row['hashcheckfail'];
$sessionexpiry = $row['session'];
$killOtherSessions = $row['killOtherSessions'];

if ($banned) {
    die(json_encode(array(
        "success" => false,
        "message" => "This application has been banned from KeyAuth.com for violating terms." // yes we self promote to customers of those who break ToS. Should've followed terms :shrug:
    )));
}

switch ($_POST['type'] ?? $_GET['type']) {
    case 'init':
        $ip = api\shared\primary\getIp();
        if ($vpnblock) {
            if (api\shared\primary\vpnCheck($ip)) {
                die(json_encode(array(
                    "success" => false,
                    "message" => "$vpnblocked"
                )));
            }
        }

        if (!$status) {
            die(json_encode(array(
                "success" => false,
                "message" => "$appdisabled"
            )));
        }

        if ($paused) {
            die(json_encode(array(
                "success" => false,
                "message" => "Application is currently paused, please wait for the developer to say otherwise."
            )));
        }

        $ver = misc\etc\sanitize($_POST['ver'] ?? $_GET['ver']);
        if (is_numeric($ver)) {
            if ($ver != $currentver) {
                // auto-update system
                die(json_encode(array(
                    "success" => false,
                    "message" => "invalidver",
                    "download" => "$download"
                ), JSON_UNESCAPED_SLASHES));
            }
        }

        $hash = misc\etc\sanitize($_POST['hash'] ?? $_GET['hash']);

        if ($hashcheck) {
            if (strpos($serverhash, $hash) === false) {
                if (is_null($serverhash)) {
                    include_once '/usr/share/nginx/html/includes/connection.php'; // create connection with MySQL
                    mysqli_query($link, "UPDATE `apps` SET `hash` = '$hash' WHERE `secret` = '$secret'");
                    misc\cache\purge('KeyAuthApp:' . $name . ':' . $ownerid); // flush cache for application so new hash takes precedent
                } else {
                    die(json_encode(array(
                        "success" => false,
                        "message" => "$hashcheckfail"
                    )));
                }
            }
        }

        $enckey = NULL;

        $row = misc\cache\fetch('KeyAuthStateDuplicates:' . $secret . ':' . $ip, "SELECT `id`, `expiry` FROM `sessions` WHERE `app` = '$secret' AND `ip` = '$ip' AND `validated` = 0 AND `expiry` > " . time() . " LIMIT 1", 0);
        if ($row != "not_found") {
            $sessionid = $row['id'];
            goto dupe;
        }

		$sessionid = misc\etc\generateRandomString();
        // session init
        $time = time() + $sessionexpiry;
        include_once '/usr/share/nginx/html/includes/connection.php'; // create connection with MySQL
        mysqli_query($link, "INSERT INTO `sessions` (`id`, `app`, `expiry`, `enckey`, `ip`) VALUES ('$sessionid','$secret', '$time', '$enckey', '$ip')");
        misc\cache\purge('KeyAuthStateDuplicates:' . $secret . ':' . $ip);

        dupe:

        $row = misc\cache\fetch('KeyAuthAppStats:' . $secret, "SELECT(select count(1) FROM `users` WHERE `app` = '$secret') AS 'numUsers',(select count(1) FROM `sessions` WHERE `app` = '$secret' AND `validated` = 1 AND `expiry` > " . time() . ") AS 'numOnlineUsers',(select count(1) FROM `keys` WHERE `app` = '$secret') AS 'numKeys';", 0, 1800);

        $numUsers = $row['numUsers'];
        $numOnlineUsers = $row['numOnlineUsers'];
        $numKeys = $row['numKeys'];

        die(json_encode(array(
            "success" => true,
            "message" => "Initialized",
            "sessionid" => $sessionid,
            "appinfo" => array(
                "numUsers" => $numUsers,
                "numOnlineUsers" => $numOnlineUsers,
                "numKeys" => $numKeys,
                "version" => $ver,
                "customerPanelLink" => "https://keyauth.cc/panel/$owner/$name/"
            )
        )));

    case 'register':
        // retrieve session info
        $sessionid = misc\etc\sanitize($_POST['sessionid'] ?? $_GET['sessionid']);
        $session = api\shared\primary\getSession($sessionid, $secret);
        $enckey = $session["enckey"];

        // Read in username
        $username = misc\etc\sanitize($_POST['username'] ?? $_GET['username']);

        // Read in license key
        $checkkey = misc\etc\sanitize($_POST['key'] ?? $_GET['key']);

        // Read in password
        $password = misc\etc\sanitize($_POST['pass'] ?? $_GET['pass']);

        // Read in hwid
        $hwid = misc\etc\sanitize($_POST['hwid'] ?? $_GET['hwid']);

        $resp = api\v1_0\register($username, $checkkey, $password, $hwid, $secret);
        switch ($resp) {
            case 'username_taken':
                die(json_encode(array(
                    "success" => false,
                    "message" => "$usernametaken"
                )));
            case 'key_not_found':
                die(json_encode(array(
                    "success" => false,
                    "message" => "$keynotfound"
                )));
            case 'un_too_short':
                die(json_encode(array(
                    "success" => false,
                    "message" => "Username too short, try longer one."
                )));
            case 'pw_leaked':
                die(json_encode(array(
                    "success" => false,
                    "message" => "This password has been leaked in a data breach (not from us), please use a different one."
                )));
            case 'key_already_used':
                die(json_encode(array(
                    "success" => false,
                    "message" => "$keyused"
                )));
            case 'key_banned':
                if (strpos($keybanned, '{reason}') !== false) {
                    include_once '/usr/share/nginx/html/includes/connection.php'; // create connection with MySQL
                    $result = mysqli_query($link, "SELECT `banned` FROM `keys` WHERE `app` = '$secret' AND `key` = '$checkkey'");
                    $row = mysqli_fetch_array($result);
                    $reason = $row['banned'];
                    $keybanned = str_replace("{reason}", $reason, $keybanned);
                }
                die(json_encode(array(
                    "success" => false,
                    "message" => "$keybanned"
                )));
            case 'hwid_blacked':
                die(json_encode(array(
                    "success" => false,
                    "message" => "$hwidblacked"
                )));
            case 'no_subs_for_level':
                die(json_encode(array(
                    "success" => false,
                    "message" => "$nosublevel"
                )));
            default:
                include_once '/usr/share/nginx/html/includes/connection.php'; // create connection with MySQL
                if ($killOtherSessions) {
                    mysqli_query($link, "DELETE FROM `sessions` WHERE `id` != '$sessionid' AND `credential` = '$username' AND `app` = '$secret'");
                    misc\cache\purgePattern('KeyAuthState:' . $secret);
                }
                mysqli_query($link, "UPDATE `sessions` SET `credential` = '$username',`validated` = 1 WHERE `id` = '$sessionid'");
                misc\cache\purge('KeyAuthState:' . $secret . ':' . $sessionid);

                $ip = api\shared\primary\getIp();
                misc\cache\purge('KeyAuthStateDuplicates:' . $secret . ':' . $ip);
                die(json_encode(array(
                    "success" => true,
                    "message" => "Logged in!",
                    "info" => $resp
                )));
        }
    case 'upgrade':
        // retrieve session info
        $sessionid = misc\etc\sanitize($_POST['sessionid'] ?? $_GET['sessionid']);
        $session = api\shared\primary\getSession($sessionid, $secret);
        $enckey = $session["enckey"];

        // Read in username
        $username = misc\etc\sanitize($_POST['username'] ?? $_GET['username']);

        // Read in key
        $checkkey = misc\etc\sanitize($_POST['key'] ?? $_GET['key']);

        include_once '/usr/share/nginx/html/includes/connection.php'; // create connection with MySQL

        // search for key
        $result = mysqli_query($link, "SELECT `banned`, `expires`, `status`, `level` FROM `keys` WHERE `key` = '$checkkey' AND `app` = '$secret'");

        // check if key exists
        if (mysqli_num_rows($result) == 0) {
            die(json_encode(array(
                "success" => false,
                "message" => "$keynotfound"
            )));
        }

        // if key does exist
        elseif (mysqli_num_rows($result) > 0) {

            // get key info
            while ($row = mysqli_fetch_array($result)) {
                $expires = $row['expires'];
                $status = $row['status'];
                $level = $row['level'];
                $banned = $row['banned'];
            }

            // check if used
            if ($status == "Used") {
                die(json_encode(array(
                    "success" => false,
                    "message" => "$keyused"
                )));
            }

            if (!is_null($banned)) {
                die(json_encode(array(
                    "success" => false,
                    "message" => "Key banned: {$banned}"
                )));
            }

            // add current time to key time
            $expiry = $expires + time();

            $result = mysqli_query($link, "SELECT `name` FROM `subscriptions` WHERE `app` = '$secret' AND `level` = '$level'");
            $subName = mysqli_fetch_array($result)['name'];

            $resp = misc\user\extend($username, $subName, $expiry, $secret);
            switch ($resp) {
                case 'missing':
                    die(json_encode(array(
                        "success" => false,
                        "message" => "$usernamenotfound"
                    )));
                    break;
                case 'sub_missing':
                    die(json_encode(array(
                        "success" => false,
                        "message" => "$nosublevel"
                    )));
                    break;
                case 'failure':
                    die(json_encode(array(
                        "success" => false,
                        "message" => "Failed to upgrade for some reason."
                    )));
                    break;
                case 'success':
                    // set key to used, and set usedby
                    mysqli_query($link, "UPDATE `keys` SET `status` = 'Used', `usedon` = '" . time() . "', `usedby` = '$username' WHERE `key` = '$checkkey'");
                    misc\cache\purge('KeyAuthKeys:' . $secret . ':' . $checkkey);
                    die(json_encode(array(
                        "success" => true,
                        "message" => "Upgraded successfully"
                    )));
                    break;
                default:
                    die(json_encode(array(
                        "success" => false,
                        "message" => "Unhandled Error! Contact us if you need help"
                    )));
                    break;
            }
        }

    case 'login':
        // retrieve session info
        $sessionid = misc\etc\sanitize($_POST['sessionid'] ?? $_GET['sessionid']);
        $session = api\shared\primary\getSession($sessionid, $secret);
        $enckey = $session["enckey"];

        // Read in username
        $username = misc\etc\sanitize($_POST['username'] ?? $_GET['username']);

        // Read in HWID
        $hwid = misc\etc\sanitize($_POST['hwid'] ?? $_GET['hwid']);

        // Read in password
        $password = misc\etc\sanitize($_POST['pass'] ?? $_GET['pass']);

        // optional param for web loader
        $token = misc\etc\sanitize($_POST['token'] ?? $_GET['token']);

        $resp = api\v1_0\login($username, $password, $hwid, $secret, $hwidenabled, $token);
        switch ($resp) {
            case 'un_not_found':
                die(json_encode(array(
                    "success" => false,
                    "message" => "$usernamenotfound"
                )));
            case 'pw_mismatch':
                die(json_encode(array(
                    "success" => false,
                    "message" => "$passmismatch"
                )));
            case 'user_banned':
                if (strpos($userbanned, '{reason}') !== false) {
                    include_once '/usr/share/nginx/html/includes/connection.php'; // create connection with MySQL
                    $result = mysqli_query($link, "SELECT `banned` FROM `users` WHERE `app` = '$secret' AND `username` = '$username'");
                    $row = mysqli_fetch_array($result);
                    $reason = $row['banned'];
                    $userbanned = str_replace("{reason}", $reason, $userbanned);
                }
                die(json_encode(array(
                    "success" => false,
                    "message" => "$userbanned"
                )));
            case 'hwid_mismatch':
                die(json_encode(array(
                    "success" => false,
                    "message" => "$hwidmismatch"
                )));
            case 'hwid_blacked':
                die(json_encode(array(
                    "success" => false,
                    "message" => "$hwidblacked"
                )));
            case 'sub_paused':
                die(json_encode(array(
                    "success" => false,
                    "message" => "$pausedsub"
                )));
            case 'no_active_subs':
                die(json_encode(array(
                    "success" => false,
                    "message" => "$noactivesubs"
                )));
            default:
                include_once '/usr/share/nginx/html/includes/connection.php'; // create connection with MySQL
                if ($killOtherSessions) {
                    mysqli_query($link, "DELETE FROM `sessions` WHERE `id` != '$sessionid' AND `credential` = '$username' AND `app` = '$secret'");
                    misc\cache\purgePattern('KeyAuthState:' . $secret);
                }
                mysqli_query($link, "UPDATE `sessions` SET `validated` = 1,`credential` = '$username' WHERE `id` = '$sessionid'");
                misc\cache\purge('KeyAuthState:' . $secret . ':' . $sessionid);

                $ip = api\shared\primary\getIp();
                misc\cache\purge('KeyAuthStateDuplicates:' . $secret . ':' . $ip);
                die(json_encode(array(
                    "success" => true,
                    "message" => "Logged in!",
                    "info" => $resp
                )));
        }

    case 'license':
        // retrieve session info
        $sessionid = misc\etc\sanitize($_POST['sessionid'] ?? $_GET['sessionid']);
        $session = api\shared\primary\getSession($sessionid, $secret);
        $enckey = $session["enckey"];
        $checkkey = misc\etc\sanitize($_POST['key'] ?? $_GET['key']);

        $hwid = misc\etc\sanitize($_POST['hwid'] ?? $_GET['hwid']);

        $resp = api\v1_0\login($checkkey, $checkkey, $hwid, $secret, $hwidenabled);
        switch ($resp) {
            case 'un_not_found':
                break; // user not registered yet or user was deleted

            case 'hwid_mismatch':
                die(json_encode(array(
                    "success" => false,
                    "message" => "$hwidmismatch"
                )));
            case 'user_banned':
                if (strpos($userbanned, '{reason}') !== false) {
                    include_once '/usr/share/nginx/html/includes/connection.php'; // create connection with MySQL
                    $result = mysqli_query($link, "SELECT `banned` FROM `users` WHERE `app` = '$secret' AND `username` = '$checkkey'");
                    $row = mysqli_fetch_array($result);
                    $reason = $row['banned'];
                    $userbanned = str_replace("{reason}", $reason, $userbanned);
                }
                die(json_encode(array(
                    "success" => false,
                    "message" => "$userbanned"
                )));
            case 'pw_mismatch':
                die(json_encode(array(
                    "success" => false,
                    "message" => "$passmismatch"
                )));
            case 'sub_paused':
                die(json_encode(array(
                    "success" => false,
                    "message" => "$pausedsub"
                )));
            case 'hwid_blacked':
                die(json_encode(array(
                    "success" => false,
                    "message" => "$hwidblacked"
                )));
            case 'no_active_subs':
                die(json_encode(array(
                    "success" => false,
                    "message" => "$noactivesubs"
                )));
            default:
                include_once '/usr/share/nginx/html/includes/connection.php'; // create connection with MySQL
                if ($killOtherSessions) {
                    mysqli_query($link, "DELETE FROM `sessions` WHERE `id` != '$sessionid' AND `credential` = '$checkkey' AND `app` = '$secret'");
                    misc\cache\purgePattern('KeyAuthState:' . $secret);
                }
                mysqli_query($link, "UPDATE `sessions` SET `validated` = 1,`credential` = '$checkkey' WHERE `id` = '$sessionid'");
                misc\cache\purge('KeyAuthState:' . $secret . ':' . $sessionid);

                $ip = api\shared\primary\getIp();
                misc\cache\purge('KeyAuthStateDuplicates:' . $secret . ':' . $ip);
                die(json_encode(array(
                    "success" => true,
                    "message" => "Logged in!",
                    "info" => $resp
                )));
        }

        // if login didn't work, attempt to register
        $resp = api\v1_0\register($checkkey, $checkkey, $checkkey, $hwid, $secret);
        switch ($resp) {
            case 'username_taken':
                die(json_encode(array(
                    "success" => false,
                    "message" => "$usernametaken"
                )));
            case 'key_not_found':
                die(json_encode(array(
                    "success" => false,
                    "message" => "$keynotfound"
                )));
            case 'un_too_short':
                die(json_encode(array(
                    "success" => false,
                    "message" => "Username too short, try longer one."
                )));
            case 'pw_leaked':
                die(json_encode(array(
                    "success" => false,
                    "message" => "This password has been leaked in a data breach (not from us), please use a different one."
                )));
            case 'key_already_used':
                die(json_encode(array(
                    "success" => false,
                    "message" => "$keyused"
                )));
            case 'key_banned':
                if (strpos($keybanned, '{reason}') !== false) {
                    include_once '/usr/share/nginx/html/includes/connection.php'; // create connection with MySQL
                    $result = mysqli_query($link, "SELECT `banned` FROM `keys` WHERE `app` = '$secret' AND `key` = '$checkkey'");
                    $row = mysqli_fetch_array($result);
                    $reason = $row['banned'];
                    $keybanned = str_replace("{reason}", $reason, $keybanned);
                }
                die(json_encode(array(
                    "success" => false,
                    "message" => "$keybanned"
                )));
            case 'hwid_blacked':
                die(json_encode(array(
                    "success" => false,
                    "message" => "$hwidblacked"
                )));
            case 'no_subs_for_level':
                die(json_encode(array(
                    "success" => false,
                    "message" => "$nosublevel"
                )));
            default:
                include_once '/usr/share/nginx/html/includes/connection.php'; // create connection with MySQL
                if ($killOtherSessions) {
                    mysqli_query($link, "DELETE FROM `sessions` WHERE `id` != '$sessionid' AND `credential` = '$checkkey' AND `app` = '$secret'");
                    misc\cache\purgePattern('KeyAuthState:' . $secret);
                }
                mysqli_query($link, "UPDATE `sessions` SET `validated` = 1,`credential` = '$checkkey' WHERE `id` = '$sessionid'");
                misc\cache\purge('KeyAuthState:' . $secret . ':' . $sessionid);

                $ip = api\shared\primary\getIp();
                misc\cache\purge('KeyAuthStateDuplicates:' . $secret . ':' . $ip);
                die(json_encode(array(
                    "success" => true,
                    "message" => "Logged in!",
                    "info" => $resp
                )));
        }
    case 'fetchOnline':
        $sessionid = misc\etc\sanitize($_POST['sessionid'] ?? $_GET['sessionid']);
        $session = api\shared\primary\getSession($sessionid, $secret);

        $rows = misc\cache\fetch('KeyAuthOnlineUsers:' . $secret, "SELECT DISTINCT `credential` FROM `sessions` WHERE `validated` = 1 AND `app` = '$secret'", 1, 1800);

        if ($rows == "not_found") {
            die(json_encode(array(
                "success" => false,
                "message" => "No online users found!"
            )));
        }

        die(json_encode(array(
            "success" => true,
            "message" => "Successfully fetched online users.",
            "users" => $rows
        )));
    case 'setvar':
        $sessionid = misc\etc\sanitize($_POST['sessionid'] ?? $_GET['sessionid']);
        $session = api\shared\primary\getSession($sessionid, $secret);
        $enckey = $session["enckey"];
        if (!$session["validated"]) {
            die(json_encode(array(
                "success" => false,
                "message" => "$sessionunauthed"
            )));
        }

        $var = misc\etc\sanitize($_POST['var'] ?? $_GET['var']);
        $data = misc\etc\sanitize($_POST['data'] ?? $_GET['data']);
        include_once '/usr/share/nginx/html/includes/connection.php'; // create connection with MySQL
        mysqli_query($link, "REPLACE INTO `uservars` (`name`, `data`, `user`, `app`) VALUES ('$var', '$data', '" . $session["credential"] . "', '$secret')");

        if (mysqli_affected_rows($link) != 0) {
            misc\cache\purge('KeyAuthUserVar:' . $secret . ':' . $var . ':' . $session["credential"]);
            die(json_encode(array(
                "success" => true,
                "message" => "Successfully set variable"
            )));
        } else {
            die(json_encode(array(
                "success" => false,
                "message" => "Failed to set variable"
            )));
        }
    case 'getvar':
        $sessionid = misc\etc\sanitize($_POST['sessionid'] ?? $_GET['sessionid']);
        $session = api\shared\primary\getSession($sessionid, $secret);
        $enckey = $session["enckey"];
        if (!$session["validated"]) {
            die(json_encode(array(
                "success" => false,
                "message" => "$sessionunauthed"
            )));
        }

        $var = misc\etc\sanitize($_POST['var'] ?? $_GET['var']);

        $row = misc\cache\fetch('KeyAuthUserVar:' . $secret . ':' . $var . ':' . $session["credential"], "SELECT `data` FROM `uservars` WHERE `name` = '$var' AND `user` = '" . $session["credential"] . "' AND `app` = '$secret'", 0);

        if ($row == "not_found") {
            die(json_encode(array(
                "success" => false,
                "message" => "Variable not found for user"
            )));
        }

        $data = $row['data'];
        die(json_encode(array(
            "success" => true,
            "message" => "Successfully retrieved variable",
            "response" => $data
        )));
    case 'var':
        // retrieve session info
        $sessionid = misc\etc\sanitize($_POST['sessionid'] ?? $_GET['sessionid']);
        $session = api\shared\primary\getSession($sessionid, $secret);
        $enckey = $session["enckey"];

        $varid = misc\etc\sanitize($_POST['varid'] ?? $_GET['varid']);

        $row = misc\cache\fetch('KeyAuthVar:' . $secret . ':' . $varid, "SELECT `msg`, `authed` FROM `vars` WHERE `varid` = '$varid' AND `app` = '$secret'", 0);

        if ($row == "not_found") {
            die(json_encode(array(
                "success" => false,
                "message" => "Variable not found."
            )));
        }

        $msg = $row['msg'];
        $authed = $row['authed'];

        if ($authed) // if variable requires user to be authenticated

        {
            if (!$session["validated"]) {
                die(json_encode(array(
                    "success" => false,
                    "message" => "$sessionunauthed"
                )));
            }
        }
        die(json_encode(array(
            "success" => true,
            "message" => "$msg"
        )));
    case 'checkblacklist':
        // retrieve session info
        $sessionid = misc\etc\sanitize($_POST['sessionid'] ?? $_GET['sessionid']);
        $session = api\shared\primary\getSession($sessionid, $secret);
        $enckey = $session["enckey"];

        $hwid = misc\etc\sanitize($_POST['hwid'] ?? $_GET['hwid']);
        $ip = api\shared\primary\getIp();

        $row = misc\cache\fetch('KeyAuthBlacklist:' . $secret . ':' . $ip . ':' . $hwid, "SELECT 1 FROM `bans` WHERE (`hwid` = '$hwid' OR `ip` = '$ip') AND `app` = '$secret'", 0);

        if ($row != "not_found") {
            die(json_encode(array(
                "success" => true,
                "message" => "Client is blacklisted"
            )));
        } else {
            die(json_encode(array(
                "success" => false,
                "message" => "Client is not blacklisted"
            )));
        }
    case 'chatget':
        // retrieve session info
        $sessionid = misc\etc\sanitize($_POST['sessionid'] ?? $_GET['sessionid']);
        $session = api\shared\primary\getSession($sessionid, $secret);
        $enckey = $session["enckey"];
        if (!$session["validated"]) {
            die(json_encode(array(
                "success" => false,
                "message" => "$sessionunauthed"
            )));
        }

        $channel = misc\etc\sanitize($_POST['channel'] ?? $_GET['channel']);
        $rows = misc\cache\fetch('KeyAuthChatMsgs:' . $secret . ':' . $channel, "SELECT `author`, `message`, `timestamp` FROM `chatmsgs` WHERE `channel` = '$channel' AND `app` = '$secret'", 1);

        die(json_encode(array(
            "success" => true,
            "message" => "Successfully retrieved chat messages",
            "messages" => $rows
        )));
    case 'chatsend':
        // retrieve session info
        $sessionid = misc\etc\sanitize($_POST['sessionid'] ?? $_GET['sessionid']);
        $session = api\shared\primary\getSession($sessionid, $secret);
        $enckey = $session["enckey"];
        if (!$session["validated"]) {
            die(json_encode(array(
                "success" => false,
                "message" => "$sessionunauthed"
            )));
        }

        $channel = misc\etc\sanitize($_POST['channel'] ?? $_GET['channel']);
        include_once '/usr/share/nginx/html/includes/connection.php'; // create connection with MySQL
        $result = mysqli_query($link, "SELECT `delay` FROM `chats` WHERE `name` = '$channel' AND `app` = '$secret'");

        if (mysqli_num_rows($result) == 0) {
            die(json_encode(array(
                "success" => false,
                "message" => "Chat channel not found"
            )));
        }

        $row = mysqli_fetch_array($result);
        $delay = $row['delay'];
        $credential = $session["credential"];
        $result = mysqli_query($link, "SELECT `timestamp` FROM `chatmsgs` WHERE `author` = '$credential' AND `channel` = '$channel' AND `app` = '$secret' ORDER BY `id` DESC LIMIT 1");

        $row = mysqli_fetch_array($result);
        $time = $row['timestamp'];

        if (time() - $time < $delay) {
            die(json_encode(array(
                "success" => false,
                "message" => "Chat slower, you've hit the delay limit"
            )));
        }

        $result = mysqli_query($link, "SELECT `time` FROM `chatmutes` WHERE `user` = '$credential' AND `app` = '$secret'");
        if (mysqli_num_rows($result) != 0) {
            $row = mysqli_fetch_array($result);
            $unmuted = $row["time"];
            $unmuted = date("F j, Y, g:i a", $unmuted);
            die(json_encode(array(
                "success" => false,
                "message" => "You're muted from chat until $unmuted"
            )));
        }

        $message = misc\etc\sanitize($_POST['message'] ?? $_GET['message']);
        mysqli_query($link, "INSERT INTO `chatmsgs` (`author`, `message`, `timestamp`, `channel`,`app`) VALUES ('$credential','$message','" . time() . "','$channel','$secret')");
        mysqli_query($link, "DELETE FROM `chatmsgs` WHERE `app` = '$secret' AND `channel` = '$channel' AND `id` NOT IN ( SELECT `id` FROM ( SELECT `id` FROM `chatmsgs` WHERE `channel` = '$channel' AND `app` = '$secret' ORDER BY `id` DESC LIMIT 20) foo );");
        misc\cache\purge('KeyAuthChatMsgs:' . $secret . ':' . $channel);
        die(json_encode(array(
            "success" => true,
            "message" => "Successfully sent chat message"
        )));
    case 'log':
        // retrieve session info
        $sessionid = misc\etc\sanitize($_POST['sessionid'] ?? $_GET['sessionid']);
        $session = api\shared\primary\getSession($sessionid, $secret);
        $enckey = $session["enckey"];

        $credential = $session["credential"];

        $currtime = time();

        $msg = misc\etc\sanitize($_POST['message'] ?? $_GET['message']);

        $pcuser = misc\etc\sanitize($_POST['pcuser'] ?? $_GET['pcuser']);

        if (is_null($webhook)) {
            include_once '/usr/share/nginx/html/includes/connection.php'; // create connection with MySQL
            mysqli_query($link, "INSERT INTO `logs` (`logdate`, `logdata`, `credential`, `pcuser`,`logapp`) VALUES ('$currtime','$msg',NULLIF('$credential', ''),NULLIF('$pcuser', ''),'$secret')");
            die();
        }

        $credential = $session["credential"] ?? "N/A";

        $msg = "📜 Log: " . $msg;

        $ip = api\shared\primary\getIp();

        $timestamp = date("c", strtotime("now"));

        $json_data = json_encode([

            // Message
            //"content" => "Hello World! This is message line ;) And here is the mention, use userID <@12341234123412341>",


            // Username
            "username" => "KeyAuth",

            // Avatar URL.
            // Uncoment to replace image set in webhook
            "avatar_url" => "https://keyauth.com/assets/img/favicon.png",

            // Text-to-speech
            "tts" => false,

            // File upload
            // "file" => "",


            // Embeds Array
            "embeds" => [

                [

                    // Embed Title
                    "title" => $msg,

                    // Embed Type
                    "type" => "rich",

                    // Embed Description
                    //"description" => "Description will be here, someday, you can mention users here also by calling userID <@12341234123412341>",


                    // URL of title link
                    // "url" => "https://gist.github.com/Mo45/cb0813cb8a6ebcd6524f6a36d4f8862c",


                    // Timestamp of embed must be formatted as ISO8601
                    "timestamp" => $timestamp,

                    // Embed left border color in HEX
                    "color" => hexdec("00ffe1"),

                    // Footer
                    "footer" => [

                        "text" => $name

                    ],

                    // Additional Fields array
                    "fields" => [["name" => "🔐 Credential:", "value" => "```" . $credential . "```"], ["name" => "💻 PC Name:", "value" => "```" . $pcuser . "```", "inline" => true], ["name" => "🌎 Client IP:", "value" => "```" . $ip . "```", "inline" => true], ["name" => "📈 Level:", "value" => "```1```", "inline" => true]]

                ]

            ]

        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ch = curl_init($webhook);

        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-type: application/json'
        ));

        curl_setopt($ch, CURLOPT_POST, 1);

        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);

        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);

        curl_setopt($ch, CURLOPT_HEADER, 0);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $response = curl_exec($ch);

        // If you need to debug, or find out why you can't send message uncomment line below, and execute script.
        // echo $response;
        curl_close($ch);
        die();

    case 'webhook':
        // retrieve session info
        $sessionid = misc\etc\sanitize($_POST['sessionid'] ?? $_GET['sessionid']);
        $session = api\shared\primary\getSession($sessionid, $secret);
        $enckey = $session["enckey"];

        $webid = misc\etc\sanitize($_POST['webid'] ?? $_GET['webid']);
        $row = misc\cache\fetch('KeyAuthWebhook:' . $secret . ':' . $webid, "SELECT `baselink`, `useragent`, `authed` FROM `webhooks` WHERE `webid` = '$webid' AND `app` = '$secret'", 0);
        if ($row == "not_found") {
            die(json_encode(array(
                "success" => false,
                "message" => "Webhook Not Found."
            )));
        }

        $baselink = $row['baselink'];
        $useragent = $row['useragent'];
        $authed = $row['authed'];

        if ($authed) // if variable requires user to be authenticated

        {
            if (!$session["validated"]) {
                die(json_encode(array(
                    "success" => false,
                    "message" => "$sessionunauthed"
                )));
            }
        }

        $params = misc\etc\sanitize($_POST['params'] ?? $_GET['params']);
        $body = $_POST['body'] ?? $_GET['body'];
        $contType = misc\etc\sanitize($_POST['conttype'] ?? $_GET['conttype']);

        $url = $baselink .= urldecode($params);

        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_USERAGENT, $useragent);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        if (!is_null($body) && !empty($body))
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        if (!is_null($contType))
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: ' . $contType));

        $response = curl_exec($ch);

        die(json_encode(array(
            "success" => true,
            "message" => "Webhook request successful",
            "response" => "$response"
        )));
    case 'file':
        // retrieve session info
        $sessionid = misc\etc\sanitize($_POST['sessionid'] ?? $_GET['sessionid']);
        $session = api\shared\primary\getSession($sessionid, $secret);
        $enckey = $session["enckey"];

        $fileid = misc\etc\sanitize($_POST['fileid'] ?? $_GET['fileid']);

        $row = misc\cache\fetch('KeyAuthFile:' . $secret . ':' . $fileid, "SELECT `name`, `url`, `authed` FROM `files` WHERE `app` = '$secret' AND `id` = '$fileid'", 0);

        if ($row == "not_found") {
            die(json_encode(array(
                "success" => false,
                "message" => "File not Found"
            )));
        }

        $filename = $row['name'];
        $url = $row['url'];
        $authed = $row['authed'];

        if ($authed) // if file requires user to be authenticated

        {
            if (!$session["validated"]) {
                die(json_encode(array(
                    "success" => false,
                    "message" => "$sessionunauthed"
                )));
            }
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $data = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($statusCode == 403 || $statusCode == 404) {
            die(json_encode(array(
                "success" => false,
                "message" => "File no longer works, please notify the application developer."
            )));
        }
        $contents = bin2hex($data);

        die(json_encode(array(
            "success" => true,
            "message" => "File download successful",
            "contents" => "$contents"
        )));

    case 'ban':
        // retrieve session info
        $sessionid = misc\etc\sanitize($_POST['sessionid'] ?? $_GET['sessionid']);
        $session = api\shared\primary\getSession($sessionid, $secret);
        $enckey = $session["enckey"];

        $credential = $session["credential"];
        if (!$session["validated"]) {
            die(json_encode(array(
                "success" => false,
                "message" => "$sessionunauthed"
            )));
        }

        include_once '/usr/share/nginx/html/includes/connection.php'; // create connection with MySQL
        $hwid = misc\etc\sanitize($_POST['hwid'] ?? $_GET['hwid']);
        if (!empty($hwid)) {
            mysqli_query($link, "INSERT INTO `bans` (`hwid`, `type`, `app`) VALUES ('$hwid','hwid', '$secret')");
        }
        $ip = api\shared\primary\getIp();
        mysqli_query($link, "INSERT INTO `bans` (`ip`, `type`, `app`) VALUES ('$ip','ip', '$secret')");

        mysqli_query($link, "UPDATE `users` SET `banned` = 'User banned from triggering ban function in the client' WHERE `username` = '$credential'");
        if (mysqli_affected_rows($link) != 0) {
            misc\cache\purge('KeyAuthUser:' . $secret . ':' . $credential);
            die(json_encode(array(
                "success" => true,
                "message" => "Successfully Banned User"
            )));
        } else {
            die(json_encode(array(
                "success" => false,
                "message" => "Failed to ban user."
            )));
        }
    case 'check':
        // retrieve session info
        $sessionid = misc\etc\sanitize($_POST['sessionid'] ?? $_GET['sessionid']);
        $session = api\shared\primary\getSession($sessionid, $secret);
        $enckey = $session["enckey"];

        $credential = $session["credential"];
        if (!$session["validated"]) {
            die(json_encode(array(
                "success" => false,
                "message" => "$sessionunauthed"
            )));
        } else {
            die(json_encode(array(
                "success" => true,
                "message" => "Session is validated."
            )));
        }
    default:
        die(json_encode(array(
            "success" => false,
            "message" => "The value inputted for type paramater was not found"
        )));
}
