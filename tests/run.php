<?php
// Runs the plugin's scenarios, each test in a fresh PHP process so no hook,
// option or queued row leaks from one store into the next.
//
//   php tests/run.php            every scenario file
//   php tests/run.php drain      files whose name contains "drain"
//   php tests/run.php drain lock ...and tests whose name contains "lock"
//
// AGUMAIL_PLUGIN_DIR points it at another copy of the plugin folder.
//
// A scenario file defines test_<file>_... functions; each receives the plugin
// folder. A test passes when it returns normally, so code that exits halfway
// through a test is reported as a failure.

$plugin_dir = getenv('AGUMAIL_PLUGIN_DIR') ?: dirname(__DIR__) . '/agumail-for-woocommerce';
$scenarios  = __DIR__ . '/scenarios';

if ($argc > 3 && $argv[1] === '--child') {
    require __DIR__ . '/bootstrap.php';
    require $plugin_dir . '/agumail.php';
    agm_plugin_loaded();
    require $scenarios . '/' . basename($argv[2]);
    try {
        call_user_func($argv[3], $plugin_dir);
        echo "\nagm-ok\n";
        exit(0);
    } catch (Throwable $e) {
        echo $e->getMessage() . "\n";
        if (!($e instanceof Exception) || get_class($e) !== 'Exception') {
            echo get_class($e) . ' at ' . $e->getFile() . ':' . $e->getLine() . "\n";
        }
        exit(1);
    }
}

$file_filter = isset($argv[1]) ? $argv[1] : '';
$test_filter = isset($argv[2]) ? $argv[2] : '';
$files = glob($scenarios . '/*.php');
$total = 0;
$failed = 0;

foreach ($files as $file) {
    $base = basename($file, '.php');
    if ($file_filter !== '' && strpos($base, $file_filter) === false) {
        continue;
    }
    preg_match_all('/^function\s+(test_[A-Za-z0-9_]+)\s*\(/m', file_get_contents($file), $matches);
    $tests = array_filter($matches[1], function ($name) use ($test_filter) {
        return $test_filter === '' || strpos($name, $test_filter) !== false;
    });
    if (!$tests) {
        continue;
    }
    echo $base . "\n";
    foreach ($tests as $name) {
        $total++;
        $output = array();
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --child '
            . escapeshellarg(basename($file)) . ' ' . escapeshellarg($name) . ' 2>&1';
        exec($command, $output, $code);
        $passed = $code === 0 && end($output) === 'agm-ok';
        $label = str_replace('_', ' ', preg_replace('/^test_' . preg_quote($base, '/') . '_/', '', $name));
        if ($passed) {
            echo "  pass  $label\n";
        } else {
            $failed++;
            $lines = array_filter($output, function ($line) { return $line !== 'agm-ok' && $line !== ''; });
            echo "  FAIL  $label\n        " . implode("\n        ", $lines ?: array('(exited without finishing)')) . "\n";
        }
    }
}

if ($total === 0) {
    echo "no tests matched\n";
    exit(1);
}
echo $failed ? "\n$failed of $total failed\n" : "\nall $total passed\n";
exit($failed ? 1 : 0);
