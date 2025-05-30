<?php
require_once 'STOVENotify.php';

try {
    $stoveClaim = new STOVENotify('config.json');
    $stoveClaim->run();
    
    exit(0);
    
} catch (Exception $e) {
    error_log("STOVENotify Error: " . $e->getMessage());
    echo "Error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}