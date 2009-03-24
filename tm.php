<?php
define('CLI', isset($argv));
if(CLI && !isset($argv[1])) die("Usage: tm <uri> [<method>] <- [<payload>]\n");
define('HTTPBASE', '/tm/');
define('STORE', '/home/reed/temp/tinmanstore/');
define('HPRE', 'h_');
define('AUTHREALM', 'tinman');
$routes = array(
    'about' => 'about',
    'store\/.*' => 'store'
);
$users = array(
    'admin' => array(
        'salt' => 'saysthetinman',
        'password' => '5664bd1a971eb97010dcb2f2b98302e7',
        'roles' => array('admin')
    )
);
$user = auth_user();

function auth_challenge() {
    header('HTTP/1.1 401 Unauthorized');
    header('WWW-Authenticate: Basic realm="'.AUTHREALM.'"');
    die("I do not know ye, depart from me.\n");
}

function auth_is($r) {
    global $user;
    $is = false;
    if($user && isset($user['roles']) && in_array($r, $user['roles'])) {
        $is = true;
    }
    return $is;
}

function auth_user_exists($un) {
    global $users;
    return array_key_exists($un, $users);
}

function auth_test_pw($un, $pw) {
    global $users;
    return md5($pw.$users[$un]['salt']) == $users[$un]['password'];
}

function auth_user() {
    global $users, $routes;
    $user = false;
    if(isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
        $un = $_SERVER['PHP_AUTH_USER'];
        $pw = $_SERVER['PHP_AUTH_PW'];
        if(auth_user_exists($un) && auth_test_pw($un, $pw)){
            $user = $users[$un];
        }
    }
    return $user;
}

function condition_uri($uri) {
    $uri = str_replace(HTTPBASE, '', '/'.ltrim($uri, '/'));
    return trim($uri, '/').'/';
}

function valid_http_method($m) {
    $ms = array('get','post','put','delete','head','options','trace');
    return in_array(strtolower($m), $ms);
}

function valid_uri($uri) {
    global $routes;
    $h = false;
    foreach($routes as $p => $h_) {
        if(preg_match('/^'.$p.'\/$/', $uri)) {
            $h = $h_;
        }
    }
    return $h;
}

function handler_name($h, $m='get') {
    return HPRE."${m}_$h";
}

function handler_exists($h, $m='get') {
    return function_exists(handler_name($h, $m));
}

function handler_available_methods($h) {
    $df = get_defined_functions();
    $avail = array();
    foreach($df['user'] as $i => $f) {
        if(($m = preg_match('/^'.HPRE.'([a-z]+)_'.$h.'$/', $f))) {
            $m = preg_replace('/^'.HPRE.'([a-z]+)+_'.$h.'$/', '$1', $f);
            if(valid_http_method($m)) array_push($avail, strtoupper($m));
        }
    }
    return $avail;
}

function call_handler($handler, $method='get') {
    $result = false;
    if(function_exists(handler_name($handler, $method))) {
        $result = call_user_func(handler_name($handler, $method));
    }
    return $result;
}

function store_get_path($uri) {
    $tok = explode('/', rtrim($uri, '/'));
    array_shift($tok);
    $p = STORE.join('/', $tok);
    return $p;
}

function store_get_type($p) {
    $tok = explode('/', $p);
    $fn = array_pop($tok);
    $tok = explode('.', $fn);
    $ext = strtolower(array_pop($tok));
    $ct = 'text/plain';
    $cts = array(
        'json' => 'application/json',
        'txt' => 'text/plain',
        'html' => 'text/html',
        'xml' => 'application/xml',
        'css' => 'text/css',
        'yaml' => 'text/yaml'
    );
    if(array_key_exists($ext, $cts)) {
        $ct = $cts[$ext];
    }
    return $ct;
}

// handlers
function h_get_about() {
    print "This is the Tinman.\n";
    return true;
}

function h_post_about() {
    print file_get_contents("php://input");
    print "\n\n";
    return true;
}

function h_get_store() {
    global $uri;
    $p = store_get_path($uri);
    if(is_readable($p)) {
        header('Content-Type: '.store_get_type($p));
        print file_get_contents($p);
    } else {
        header('HTTP/1.1 404 Not Found');
        print "That resource does not exist or is unavailable.\n";
    }
}

function h_put_store() {
    if(!auth_is('admin')) auth_challenge();
    global $uri;
    $p = store_get_path($uri);
    $ptok = explode('/', $p);
    $fn = array_pop($ptok);
    $p = join('/', $ptok).'/';
    $b = file_get_contents("php://input");
    if(is_writable($p)) {
        if(@file_put_contents($p.$fn, $b)) {
            header('HTTP/1.1 201 Created');
            header('Location: '.HTTPBASE.$uri);
            print "$uri\n";
        } else {
            header('HTTP/1.1 500 Internal Server Error');
            print "Could not save.\n";
        }
    } else {
        header('HTTP/1.1 403 Forbidden');
        print "Resource is immutable.\n";
    }
    return true;
}

ob_start();
$method = strtolower(CLI ? isset($argv[2]) ? $argv[2] : 'get' : $_SERVER['REQUEST_METHOD']);
$uri = CLI ? isset($argv[1]) ? $argv[1] : condition_uri('/') : condition_uri($_SERVER['REQUEST_URI']);
$hdrs = array();

if(!valid_http_method($method)) {
    header('HTTP/1.1 501 Method Not Implemented');
    exit;
}

if(($h = valid_uri($uri)) && handler_exists($h, $method)) {
    call_handler($h, $method);
} elseif(($h = valid_uri($uri))) {
    header('HTTP/1.1 405 Method Not Allowed');
    header('Allowed: '.join(handler_available_methods($h)));
} else {
    header('HTTP/1.1 404 Not Found');
    print "That resource does not exist or is unavailable.\n";
}
$body = ob_get_contents();
ob_end_clean();
print $body;
exit;
