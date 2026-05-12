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

$test = openai_text_response(
    'Reply with exactly: OK',
    'This is a connectivity test.'
);

echo "OpenAI test response: " . ($test ?: 'FAILED') . "\n";
