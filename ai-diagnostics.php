<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=UTF-8');

echo "AlaalaMo AI diagnostics\n";
echo "OpenAI key configured: " . (openai_is_configured() ? 'yes' : 'no') . "\n";
echo "PHP cURL enabled: " . (function_exists('curl_init') ? 'yes' : 'no') . "\n";
echo "OpenAI model: " . OPENAI_TEXT_MODEL . "\n";

if (!openai_is_configured() || !function_exists('curl_init')) {
    exit;
}

$payload = json_encode([
    'model' => OPENAI_TEXT_MODEL,
    'input' => 'Reply with exactly: OK',
]);

$curl = curl_init('https://api.openai.com/v1/responses');
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . OPENAI_API_KEY,
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
    echo "OpenAI response: no response\n";
    exit;
}

$decoded = json_decode($response, true);

if (isset($decoded['output_text'])) {
    echo "OpenAI output_text: " . $decoded['output_text'] . "\n";
    exit;
}

if (isset($decoded['error'])) {
    echo "OpenAI error type: " . ($decoded['error']['type'] ?? 'unknown') . "\n";
    echo "OpenAI error code: " . ($decoded['error']['code'] ?? 'none') . "\n";
    echo "OpenAI error message: " . ($decoded['error']['message'] ?? 'none') . "\n";
    exit;
}

echo "OpenAI raw response:\n";
echo substr($response, 0, 2000) . "\n";
