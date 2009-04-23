<?php
define('CLI', isset($argv));
if(CLI && !isset($argv[1])) die("Usage: tm <uri> [<method>] <- [<payload>]\n");
define('HTTPBASE', '/tm/');
define('DATADIR', '/home/reed/temp/tmdata/');
define('USERDIR', DATADIR.'users/');
define('UFILE', '.u.json');
define('STORE', DATADIR.'store/');
define('HPRE', 'h_');
define('AUTHREALM', 'tinman');
$global_file_cache = array(); // per-request file contents cache.
$routes = array(
    'about' => 'about',
    'store\/.*' => 'store'
);
//TODO: dir manifests w/ perms.
$users = array(
    'admin' => array(
        'salt' => 'saysthetinman',
        'password' => '06279822ffa578d8d070b1affd8544f3',
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

function auth_valid_username($un) {
    return preg_match('/^\w{4,24}$/', $un);
}

function auth_ufile_load($un) {
    $subdir = substr($un, 0, 2).'/';
    $p = USERDIR.$subdir.UFILE;
    $fc = file_load($p);
    return @json_decode($fc);
}

function auth_user_exists($un) {
    return auth_ufile_load($un);
}

function auth_test_pw($un, $pw) {
    if(!auth_valid_username($un)) return false;
    if(!($user = file_load($un))) return false;
    if(!isset($user['salt'], $user['password'])) return false;
    return md5($pw.$user['salt']) == $user['password'];
}

function auth_user() {
    $user = false;
    if(isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
        $un = $_SERVER['PHP_AUTH_USER'];
        $pw = $_SERVER['PHP_AUTH_PW'];
        if(auth_user_exists($un) && auth_test_pw($un, $pw)){
            $user = auth_user_exists($un);
        } else {
            $user = false;
        }
    }
    return $user;
}

function condition_uri($uri) {
    $uri = preg_replace('/^'.str_replace('/', '\/', HTTPBASE).'/', '', '/'.ltrim($uri, '/'));
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
        if(preg_match('/^'.$p.'\/?$/', $uri)) {
            $h = $h_;
            break;
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

function req_header($hn) {
    $h = 'HTTP_';
    $h .= strtoupper(preg_replace('/\W+/', '_', $hn));
    return isset($_SERVER[$h]) ? $_SERVER[$h] : false;
}

function etag_get_deep($s) {
    return md5($s.'TOMCLANCY4EVER');
}

function etag($s) {
    return etag_get_deep($s);
}

function etag_file($f) {
    return etag_get_deep(file_get_contents($f));
}

function etag_match_file($s, $f) {
    return etag_get_deep($s) === etag_file($f);
}

function file_load($p, $c=true) {
    global $global_file_cache;
    $f = false;
    if(isset($global_file_cache[$p])) {
        $f = $global_file_cache[$p];
    } elseif(is_readable($p)) {
        $f = file_get_contents($p);
        if($c) $global_file_cache[$p] = $f;
    }
    return $f;
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
    if(file_load($p)) {
        if(req_header('if-none-match') === etag_file($p)) {
            header('HTTP/1.1 304 Not Modified');
        } else {
            header('Content-Type: '.store_get_type($p));
            $c = file_load($p);
            header('Etag: '.etag($c));
            print $c;
        }
    } else {
        header('HTTP/1.1 404 Not Found');
        header('Content-Type: text/plain');
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
        if(req_header('if-match') && is_readable($p.$fn)) {
            if(req_header('if-match') !== etag_file($p.fn)) {
                header('HTTP/1.1 412 Precondition Failed');
                header('Content-Type: text/plain');
                print "Entity tag mismatch.\n";
            }
        } else {
            if(@file_put_contents($p.$fn, $b)) {
                global $global_file_cache;
                if(isset($global_file_cache[$p.$fn])) {
                    unset($global_file_cache[$p.$fn]);
                }
                header('HTTP/1.1 201 Created');
                header('Etag: '.etag_file($p.$fn));
                header('Content-Type: text/plain');
                print rtrim($uri, '/')."\n";
            } else {
                header('HTTP/1.1 500 Internal Server Error');
                header('Content-Type: text/plain');
                print "Could not save.\n";
            }
        }
    } else {
        header('HTTP/1.1 403 Forbidden');
        print "Resource is immutable.\n";
    }
    return true;
}

function h_delete_store() {
    if(!auth_is('admin')) auth_challenge();
    global $uri;
    $p = store_get_path($uri);
    if(file_load($p) && is_writable($p)) {
        if(req_header('if-match')) {
            if(req_header('if-match') !== etag_file($p)) {
                header('HTTP/1.1 412 Precondition Failed');
                header('Content-Type: text/plain');
                print "Entity tag mismatch.\n";
                return;
            }
        }
        unlink($p);
        header('HTTP/1.1 200 OK');
        header('Content-Type: text/plain');
        print "Deleted.\n";
    } else {
        header('HTTP/1.1 404 Not Found');
        header('Content-Type: text/plain');
        print "File not found.\n";
    }
}

ob_start();
$method = strtolower(CLI ? isset($argv[2]) ? $argv[2] : 'get' : $_SERVER['REQUEST_METHOD']);
$uri = CLI ? isset($argv[1]) ? $argv[1] : condition_uri('/') : condition_uri($_SERVER['REQUEST_URI']);
$hdrs = array();

if(!valid_http_method($method)) {
    header('HTTP/1.1 501 Method Not Implemented');
    header('Content-Type: text/plain');
    exit;
}

if(($h = valid_uri($uri)) && handler_exists($h, $method)) {
    call_handler($h, $method);
} elseif(($h = valid_uri($uri))) {
    header('HTTP/1.1 405 Method Not Allowed');
    header('Allowed: '.join(', ', handler_available_methods($h)));
} else {
    header('HTTP/1.1 404 Not Found');
    header('Content-Type: text/plain');
    print "That resource does not exist or is unavailable.\n";
    print "$uri\n";
}
$body = ob_get_contents();
ob_end_clean();
print $body;
exit;
