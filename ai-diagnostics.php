<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', '');
}

if (!defined('GEMINI_TEXT_MODEL')) {
    define('GEMINI_TEXT_MODEL', 'gemini-2.5-flash');
}

header('Content-Type: text/plain; charset=UTF-8');

echo "AlaalaMo AI diagnostics\n";
echo "Gemini key configured: " . (GEMINI_API_KEY !== '' && GEMINI_API_KEY !== 'replace-with-gemini-api-key' ? 'yes' : 'no') . "\n";
echo "PHP cURL enabled: " . (function_exists('curl_init') ? 'yes' : 'no') . "\n";
echo "Gemini model: " . GEMINI_TEXT_MODEL . "\n";

if (GEMINI_API_KEY === '' || GEMINI_API_KEY === 'replace-with-gemini-api-key' || !function_exists('curl_init')) {
    exit;
}

$payload = json_encode([
    'contents' => [
        [
            'role' => 'user',
            'parts' => [
                ['text' => 'Reply with exactly: OK'],
            ],
        ],
    ],
]);

$curl = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode(GEMINI_TEXT_MODEL) . ':generateContent');
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'x-goog-api-key: ' . GEMINI_API_KEY,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_TIMEOUT => 45,
]);

$response = curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
$error = curl_error($curl);
curl_close($curl);

echo "HTTP status: " . $status . "\n";
echo "cURL error: " . ($error ?: 'none') . "\n";

if ($response === false) {
    echo "Gemini response: no response\n";
    exit;
}

$decoded = json_decode($response, true);
$parts = [];

foreach (($decoded['candidates'][0]['content']['parts'] ?? []) as $part) {
    if (isset($part['text']) && is_string($part['text'])) {
        $parts[] = $part['text'];
    }
}

if ($parts) {
    echo "Gemini output: " . trim(implode("\n", $parts)) . "\n";
    exit;
}

if (isset($decoded['error'])) {
    echo "Gemini error code: " . ($decoded['error']['code'] ?? 'none') . "\n";
    echo "Gemini error status: " . ($decoded['error']['status'] ?? 'unknown') . "\n";
    echo "Gemini error message: " . ($decoded['error']['message'] ?? 'none') . "\n";
    exit;
}

echo "Gemini raw response:\n";
echo substr($response, 0, 2000) . "\n";
