<?php
// WooCommerce's logger. Entries are kept so a scenario can check what was
// logged, and that no email address ever was.

$GLOBALS['agm']['logs'] = array();

class Agm_Fake_Logger {
    public function log($level, $message, $context = array()) {
        $GLOBALS['agm']['logs'][] = array(
            'level'   => $level,
            'message' => (string) $message,
            'context' => $context,
            'request' => $GLOBALS['agm']['request'],
        );
    }
    public function emergency($m, $c = array()) { $this->log('emergency', $m, $c); }
    public function alert($m, $c = array()) { $this->log('alert', $m, $c); }
    public function critical($m, $c = array()) { $this->log('critical', $m, $c); }
    public function error($m, $c = array()) { $this->log('error', $m, $c); }
    public function warning($m, $c = array()) { $this->log('warning', $m, $c); }
    public function notice($m, $c = array()) { $this->log('notice', $m, $c); }
    public function info($m, $c = array()) { $this->log('info', $m, $c); }
    public function debug($m, $c = array()) { $this->log('debug', $m, $c); }
}

function wc_get_logger() {
    static $logger = null;
    if ($logger === null) {
        $logger = new Agm_Fake_Logger();
    }
    return $logger;
}

/** Log entries, optionally only those whose message contains a phrase. */
function agm_logs($contains = null) {
    return array_values(array_filter($GLOBALS['agm']['logs'], function ($entry) use ($contains) {
        return $contains === null || stripos($entry['message'], $contains) !== false;
    }));
}
