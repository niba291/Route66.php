<?php

/**
 * Class Route66
 *
 * PHP micro-router
 * 
 * @package Route66
 */
class Route66 {
   
    static $base = "";

    static $before = null;

    static $beforeFunction = [];

    static $afterFunction = [];

    static $after = null;

    static $mounter = null;

    static $routes = [];

    static $names = [];

    static $cache = [];

    static $nomatch = null;

    static $rxalias = [
        ":all"   => ".*",
        ":seg"   => "[^/]+",
        ":slug"  => "[a-z0-9-]+",
        ":slug2" => "[\w-]+",            // slug + underscores
        ":num"   => "[0-9]+",
        ":alpha" => "[A-Za-z]+",
        ":alnum" => "[0-9A-Za-z]+",
    ];

    const NOHALT = "DtMnL29y";

    public static function __callStatic($meths, $args) {
        $args[0] = self::$base . $args[0];

        // detect var_regs vs route_name
        $regs = null;
        $name = null;

        switch (count($args)) {
            case 3:
                $regs = is_array($args[2])  ? $args[2] : null;
                $name = is_string($args[2]) ? $args[2] : null;
                break;
            case 4:
                $regs = is_array($args[2])  ? $args[2] : $args[3];
                $name = is_string($args[2]) ? $args[2] : $args[3];
                break;
        }

        $hash = self::hash($args[0], json_encode($regs));

        if ($name !== null)
            self::$names[$name] = $hash;

        if (isset(self::$cache[$hash]))
            $args[0] = self::$cache[$hash];
        else {
            // detect and process named params & add"l sugar (unescaped   @foo, @foo:bar, :bar)
            $sweet = "#(?<!\\\\)@(\w+)(:\w+)?|(?<![\\?])(:\w+)#i";

            if (preg_match($sweet, $args[0])) {
                $args[0] = str_replace(["(",")"], ["(?:", ")?"], $args[0]);        // todo: ignore (?

                $args[0] = preg_replace_callback($sweet, function($match) use ($regs) {
                    $var = $match[1];
                    $typ = isset($match[2]) && $match[2] !== "" ? $match[2] : ( isset($match[3]) ? $match[3] : ":seg" );

                    $rex = null;

                    if (isset($regs[$var])) {
                        if ($regs[$var][0] == ":")
                            $typ = $regs[$var];
                        else
                            $rex = $regs[$var];
                    }

                    if (!$rex && $typ) {
                        if (!isset(self::$rxalias[$typ]))
                            trigger_error("Unknown ':{$typ}' regex alias; '@{$var}' param will not be validated.");
                        else
                            $rex = self::$rxalias[$typ];
                    }

                    return "(" . $rex . ")";

                }, $args[0]);
            }

            self::$cache[$hash] = $args[0];
        }

        foreach (explode("|", strtoupper($meths)) as $meth) {
            if (!isset(self::$routes[$meth]))
                self::$routes[$meth] = [];

            self::$routes[$meth][$args[0]] = [$args[1], self::$before, self::$after];
        }
    }

    protected static function hash($uri, $params) {
        return hash("md5", $uri . " " . json_encode($params));
    }

    public static function alias($alias, $regex) {
        self::$rxalias[$alias] = $regex;
    }

    public static function any($route, $callback, $regs = null) {
        self::match("get|post|put|patch|delete|head|options", $route, $callback, $regs);
    }

    public static function mount(callable $mounter) {
        self::$mounter = $mounter;
    }

    public static function base($base, $before = null, $after = null) {
        self::$base = $base;
        self::$before = $before;
        self::$after = $after;
    }

    public static function match($meths, $route, $callback, $regs = null) {
        self::__callStatic($meths, [$route, $callback, $regs]);
    }

    public static function nomatch($callback) {
        self::$nomatch = $callback;
    }

    protected static function find($meth, $uri, $from_route = null) {
        $meth = strtoupper($meth);
        // named route?
        if (strpos($uri, "/") === false) {
            if ($hash = @self::$names[$uri])
                $uri = self::$cache[$hash];
        }

        if (!isset(self::$routes[$meth]))
            return false;

        $rset = self::$routes[$meth];

        $do_try = $from_route === null;

        if ($do_try && isset($rset[$uri]))        // this optimization prevents pre-routes from working
            return [$rset[$uri], [], $uri];

        foreach ($rset as $route => $funcs) {
            if ($do_try) {
                if (preg_match("#^" . $route . "$#", $uri, $params)) {
                    array_shift($params);
                    return [$funcs, $params, $route];
                }
            }
            else if ($route === $from_route)
                $do_try = true;
        }

        return false;
    }

    public static function dispatch($meth = null, $uri = null, $params = []) {
        $meth = $meth === null ? $_SERVER["REQUEST_METHOD"] : $meth;
        $uri = $uri === null ? parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH) : $uri;

        if ($fn = self::$mounter)
            $fn($uri);

        $found = false;
        $from_route = null;

        while ($route = self::find($meth, $uri, $from_route)) {

            // Execute global before middlewares
            foreach (self::$beforeFunction as $beforeMiddleware) {
                $res = call_user_func($beforeMiddleware);
                if ($res !== self::NOHALT)
                    return;
            }

            // before; todo: should receive request?
            if (isset($route[0][1])) {
                $bres = call_user_func($route[0][1]);
                if ($bres !== self::NOHALT)
                    return;
            }

            $res = call_user_func_array($route[0][0], empty($params) ? $route[1] : $params);

            foreach (self::$afterFunction as $afterMiddleware) {
                call_user_func($afterMiddleware);
            }

            // pass-thru now, maybe waterfall later
            if ($res === self::NOHALT)
                $from_route = $route[2];
            else {
                $found = true;

                // after; todo: should receive response?
                if (isset($route[0][2]))
                    call_user_func($route[0][2]);

                if (isset($res))
                    echo $res;

                break;
            }
        }

        if ($found)
            return;

        if ($nomatch = self::$nomatch) {
            $nomatch($meth, $uri);
            return;
        }

        // default nomatch
        header($_SERVER["SERVER_PROTOCOL"] . " 404 Not Found");
        exit("404 Not Found.");
    }

    public static function is_ajax() {
        return isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && strtolower($_SERVER["HTTP_X_REQUESTED_WITH"]) === "xmlhttprequest";
    }

    public static function is_https() {
        return (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") || $_SERVER["SERVER_PORT"] == 443;
    }

    public static function redirect($loc, $code = 301) {
        header("Location: $loc", true, $code);
        exit();
    }

    public static function invalid($msg = "400 Bad Request.") {
        header($_SERVER["SERVER_PROTOCOL"] . " 400 Bad Request");
        exit($msg);
    }

    public static function json($data, $code = 200) {
        header($_SERVER["SERVER_PROTOCOL"] . " $code");
        header("Content-Type: application/json");
        return json_encode($data);
    }

    public static function json_reqd() {
        return strpos(getallheaders()["Accept"], "application/json") !== false;
    }

    public static function deny($msg = null, $code = 401, $auth = "Basic", $realm = null) {
        if ($realm === null)
            $realm = $_SERVER["HTTP_HOST"];

        $HTTP = $_SERVER["SERVER_PROTOCOL"];

        if ($code == 401) {
            header("WWW-Authenticate: {$auth} realm=\"{$realm}\"", true);
            header("{$HTTP} 401 Unauthorized", true, $code);
            echo $msg === null ? "Access denied: Not logged in" : $msg;
        }
        else if ($code == 403) {
            header("{$HTTP} 403 Forbidden", true, $code);
            echo $msg === null ? "Access denied: Resource restricted" : $msg;
        }
    }

    public static function export() {
        return [self::$cache, self::$names];
    }

    public static function import(Array $cfg) {
        self::$cache = $cfg[0];
        self::$names = $cfg[1];
    }

    public static function getJsonRequest() {
        $input = file_get_contents("php://input");
        return json_decode($input, true);
    }

    public static function before($callback) {
        self::$beforeFunction[] = $callback;
    }

    public static function after($callback) {
        self::$afterFunction[] = $callback;
    }

    public static function enable_cors() {
        self::before(function() {
            header("Access-Control-Allow-Origin: *");
            header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
            header("Access-Control-Allow-Headers: Content-Type, Authorization");

            if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
                header($_SERVER["SERVER_PROTOCOL"] . " 200 OK");
                exit();
            }
        });
    }
}
