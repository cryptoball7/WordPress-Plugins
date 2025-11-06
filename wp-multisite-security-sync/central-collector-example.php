<?php
// Minimal collector example (for small deployments / demo). Put behind HTTPS and protect the folder.
// Save as collector.php and point site agents' Collector URL to https://central.example.com/collector.php

// Config: shared secret MUST match site agents' API Key
$API_KEY = getenv('MSSS_API_KEY') ?: 'CHANGE_THIS_TO_A_STRONG_SECRET';
$LOG_FILE = __DIR__ . '/msss-central.log';

$body = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_MSSS_SIGNATURE'] ?? '';
$site = $_SERVER['HTTP_X_MSSS_SITE'] ?? '';

if ( ! $sig ) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(array('error' => 'missing signature'));
    exit;
}

$calc = hash_hmac('sha256', $body, $API_KEY);
if ( ! hash_equals($calc, $sig) ) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(array('error' => 'invalid signature'));
    exit;
}

$event = json_decode($body, true);
$line = '[' . date('c') . '] ' . ($site ?: '') . ' ' . json_encode($event) . "\n";
file_put_contents($LOG_FILE, $line, FILE_APPEND | LOCK_EX);

header('Content-Type: application/json');
echo json_encode(array('ok' => true));
exit;
?>
