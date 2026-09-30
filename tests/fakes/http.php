<?php
// Programmable HTTP. Every outbound call is logged with the request context it
// was made in, and answered from a queue a scenario fills: a status, headers
// and a body, a WP_Error, or a callable that decides. An empty queue answers
// with agm_http_default()'s responder, else 200 {"ok":true}.

$GLOBALS['agm']['http']       = array();
$GLOBALS['agm']['http_queue'] = array();
$GLOBALS['agm']['http_default'] = null;

/** Queue responses: agm_http_respond(200, ['ok'=>true]), agm_http_respond(new WP_Error(...)), or a callable. */
function agm_http_respond($code_or_error, $body = array('ok' => true), array $headers = array()) {
    if ($code_or_error instanceof WP_Error || is_callable($code_or_error)) {
        $GLOBALS['agm']['http_queue'][] = $code_or_error;
        return;
    }
    $GLOBALS['agm']['http_queue'][] = array('code' => (int) $code_or_error, 'body' => $body, 'headers' => $headers);
}

/** Answers every request once the queue is empty: a callable($url, $args), or null for 200 {"ok":true}. */
function agm_http_default($responder) {
    $GLOBALS['agm']['http_default'] = $responder;
}

/** Every request made so far, optionally only those to one path. */
function agm_http_requests($path = null) {
    return array_values(array_filter($GLOBALS['agm']['http'], function ($request) use ($path) {
        return $path === null || $request['path'] === $path;
    }));
}

function agm_http_answer($url, $args) {
    $next = array_shift($GLOBALS['agm']['http_queue']);
    if ($next === null) {
        $next = $GLOBALS['agm']['http_default'] ?: array('code' => 200, 'body' => array('ok' => true), 'headers' => array());
    }
    if (is_callable($next) && !($next instanceof WP_Error)) {
        $next = call_user_func($next, $url, $args);
    }
    if ($next instanceof WP_Error) {
        return $next;
    }
    $headers = array();
    foreach (isset($next['headers']) ? $next['headers'] : array() as $name => $value) {
        $headers[strtolower($name)] = $value;
    }
    return array(
        'headers'  => $headers,
        'body'     => is_string($next['body']) ? $next['body'] : json_encode($next['body']),
        'response' => array('code' => $next['code'], 'message' => ''),
        'cookies'  => array(),
    );
}

function wp_remote_request($url, $args = array()) {
    $GLOBALS['agm']['http'][] = array(
        'url'     => $url,
        'path'    => parse_url($url, PHP_URL_PATH),
        'query'   => parse_url($url, PHP_URL_QUERY),
        'method'  => isset($args['method']) ? strtoupper($args['method']) : 'GET',
        'headers' => isset($args['headers']) ? $args['headers'] : array(),
        'timeout' => isset($args['timeout']) ? $args['timeout'] : null,
        'args'    => $args,
        'raw'     => isset($args['body']) ? $args['body'] : null,
        'body'    => isset($args['body']) && is_string($args['body']) ? json_decode($args['body'], true) : null,
        'context' => $GLOBALS['agm']['context'],
        'request' => $GLOBALS['agm']['request'],
    );
    return agm_http_answer($url, $args);
}
function wp_remote_get($url, $args = array()) { $args['method'] = 'GET'; return wp_remote_request($url, $args); }
function wp_remote_post($url, $args = array()) { $args['method'] = 'POST'; return wp_remote_request($url, $args); }
function wp_remote_head($url, $args = array()) { $args['method'] = 'HEAD'; return wp_remote_request($url, $args); }
function wp_safe_remote_request($url, $args = array()) { return wp_remote_request($url, $args); }
function wp_safe_remote_get($url, $args = array()) { return wp_remote_get($url, $args); }
function wp_safe_remote_post($url, $args = array()) { return wp_remote_post($url, $args); }
function wp_remote_retrieve_response_code($response) { return is_array($response) ? $response['response']['code'] : ''; }
function wp_remote_retrieve_response_message($response) { return is_array($response) ? $response['response']['message'] : ''; }
function wp_remote_retrieve_body($response) { return is_array($response) ? $response['body'] : ''; }
function wp_remote_retrieve_headers($response) { return is_array($response) ? $response['headers'] : array(); }
function wp_remote_retrieve_header($response, $header) {
    $header = strtolower($header);
    return is_array($response) && isset($response['headers'][$header]) ? $response['headers'][$header] : '';
}
