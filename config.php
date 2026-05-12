<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

const DB_HOST = '127.0.0.1';
const DB_NAME = 'alaalamo';
const DB_USER = 'root';
const DB_PASS = '';
const APP_URL = 'https://yourdomain.com';
const MAIL_FROM = 'no-reply@yourdomain.com';
const MAIL_FROM_NAME = 'AlaalaMo';
const SMTP_HOST = 'smtp.hostinger.com';
const SMTP_PORT = 465;
const SMTP_USERNAME = 'no-reply@yourdomain.com';
const SMTP_PASSWORD = 'replace-with-hostinger-email-password';
const SMTP_ENCRYPTION = 'ssl';
const OTP_TTL_MINUTES = 2;
const MAX_PROFILE_IMAGES = 20;
const MAX_MILESTONES = 5;
const MAX_MILESTONE_IMAGES = 3;
const MAX_MEMORIALS_PER_QR = 5;
const ADDITIONAL_MEMORIAL_PRICE = 700;
const OPENAI_API_KEY = '';
const OPENAI_TEXT_MODEL = 'gpt-5-mini';
const OPENAI_TTS_MODEL = 'gpt-4o-mini-tts';
const OPENAI_TTS_VOICE = 'ash';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function start_app_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function redirect_to(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function clean_input(string $value): string
{
    return trim(preg_replace('/\s+/', ' ', $value) ?? '');
}

function app_base_url(): string
{
    if (APP_URL !== '' && APP_URL !== 'https://yourdomain.com') {
        return rtrim(APP_URL, '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host . '/alaalamo';
}

function generate_token(): string
{
    return bin2hex(random_bytes(32));
}

function ensure_qr_group(int $userId): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM qr_groups WHERE user_id = ? ORDER BY id ASC LIMIT 1');
    $stmt->execute([$userId]);
    $group = $stmt->fetch();

    if ($group) {
        return $group;
    }

    $token = generate_token();
    $pdo->prepare('INSERT INTO qr_groups (user_id, public_token, plan_type) VALUES (?, ?, "premium")')
        ->execute([$userId, $token]);

    $stmt = $pdo->prepare('SELECT * FROM qr_groups WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $pdo->lastInsertId()]);

    return $stmt->fetch();
}

function ensure_upload_dir(string $directory): void
{
    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
}

function store_uploaded_image(array $file, string $subdirectory): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    if (!isset($allowedTypes[$mimeType])) {
        return null;
    }

    $relativeDirectory = 'uploads/' . trim($subdirectory, '/');
    $absoluteDirectory = __DIR__ . '/' . $relativeDirectory;
    ensure_upload_dir($absoluteDirectory);

    $filename = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mimeType];
    $target = $absoluteDirectory . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return null;
    }

    return $relativeDirectory . '/' . $filename;
}

function openai_is_configured(): bool
{
    return OPENAI_API_KEY !== '' && OPENAI_API_KEY !== 'replace-with-openai-api-key';
}

function openai_text_response(string $instructions, string $input): ?string
{
    if (!openai_is_configured() || !function_exists('curl_init')) {
        return null;
    }

    $payload = json_encode([
        'model' => OPENAI_TEXT_MODEL,
        'instructions' => $instructions,
        'input' => $input,
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
        CURLOPT_TIMEOUT => 90,
    ]);

    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($response === false || $status < 200 || $status >= 300) {
        error_log('OpenAI text generation failed: HTTP ' . $status . ' ' . $error . ' ' . (string) $response);
        return null;
    }

    $data = json_decode($response, true);

    if (isset($data['output_text']) && is_string($data['output_text'])) {
        return trim($data['output_text']);
    }

    $parts = [];
    foreach (($data['output'] ?? []) as $output) {
        foreach (($output['content'] ?? []) as $content) {
            if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                $parts[] = $content['text'];
            }
        }
    }

    return $parts ? trim(implode("\n", $parts)) : null;
}

function openai_speech_file(string $text, string $subdirectory): ?string
{
    if (!openai_is_configured() || !function_exists('curl_init')) {
        return null;
    }

    $relativeDirectory = 'uploads/' . trim($subdirectory, '/');
    $absoluteDirectory = __DIR__ . '/' . $relativeDirectory;
    ensure_upload_dir($absoluteDirectory);

    $filename = bin2hex(random_bytes(16)) . '.mp3';
    $target = $absoluteDirectory . '/' . $filename;
    $payload = json_encode([
        'model' => OPENAI_TTS_MODEL,
        'voice' => OPENAI_TTS_VOICE,
        'input' => $text,
        'instructions' => 'Narrate solemnly, gently, and respectfully, as if guiding family members through a cherished memory.',
        'response_format' => 'mp3',
    ]);

    $curl = curl_init('https://api.openai.com/v1/audio/speech');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . OPENAI_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 120,
    ]);

    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($response === false || $status < 200 || $status >= 300) {
        error_log('OpenAI speech generation failed: HTTP ' . $status . ' ' . $error . ' ' . (string) $response);
        return null;
    }

    file_put_contents($target, $response);

    return $relativeDirectory . '/' . $filename;
}

function build_memorial_context(array $memorial, array $milestones): string
{
    $lines = [
        'Loved one: ' . ($memorial['loved_one_name'] ?? ''),
        'Birth date: ' . ($memorial['birth_date'] ?? ''),
        'Death date: ' . ($memorial['death_date'] ?? ''),
        'Resting place: ' . ($memorial['resting_place'] ?? ''),
        'Short description: ' . ($memorial['short_description'] ?? ''),
        '',
        'Milestones:',
    ];

    foreach ($milestones as $index => $milestone) {
        $lines[] = ($index + 1) . '. ' . ($milestone['title'] ?? '');
        $lines[] = 'Date or period: ' . ($milestone['milestone_date'] ?? '');
        $lines[] = 'Details: ' . ($milestone['description'] ?? '');
    }

    return implode("\n", $lines);
}

function generate_otp(): string
{
    return (string) random_int(100000, 999999);
}

function create_otp(int $userId, string $purpose): string
{
    $otp = generate_otp();
    $expiresAt = (new DateTimeImmutable('+' . OTP_TTL_MINUTES . ' minutes'))->format('Y-m-d H:i:s');

    $pdo = db();
    $pdo->prepare(
        'UPDATE email_otps SET consumed_at = NOW() WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL'
    )->execute([$userId, $purpose]);

    $pdo->prepare(
        'INSERT INTO email_otps (user_id, purpose, otp_hash, expires_at) VALUES (?, ?, ?, ?)'
    )->execute([$userId, $purpose, password_hash($otp, PASSWORD_DEFAULT), $expiresAt]);

    return $otp;
}

function send_otp_email(string $email, string $otp, string $purpose): bool
{
    $autoloadPath = __DIR__ . '/vendor/autoload.php';

    if (!is_file($autoloadPath)) {
        error_log('PHPMailer is not installed. Run composer install before sending OTP emails.');
        return false;
    }

    require_once $autoloadPath;

    $subject = $purpose === 'login' ? 'Your AlaalaMo login OTP' : 'Verify your AlaalaMo email';
    $preheader = $purpose === 'login'
        ? 'Use this code to log in to your AlaalaMo account.'
        : 'Use this code to verify your AlaalaMo account.';
    $plainText = "Your AlaalaMo OTP is {$otp}. It expires in " . OTP_TTL_MINUTES . " minutes.";
    $html = '
        <div style="font-family: Arial, sans-serif; max-width: 560px; margin: 0 auto; color: #1f2933;">
            <p style="display:none; visibility:hidden; opacity:0;">' . htmlspecialchars($preheader, ENT_QUOTES, 'UTF-8') . '</p>
            <h1 style="color:#214c63; margin-bottom: 8px;">Your AlaalaMo OTP</h1>
            <p style="font-size:16px; line-height:1.6;">' . htmlspecialchars($preheader, ENT_QUOTES, 'UTF-8') . '</p>
            <p style="font-size:34px; font-weight:800; letter-spacing:6px; color:#214c63; background:#f5eee4; padding:18px 22px; border-radius:8px; text-align:center;">' . htmlspecialchars($otp, ENT_QUOTES, 'UTF-8') . '</p>
            <p style="font-size:14px; color:#5f6975;">This code expires in ' . OTP_TTL_MINUTES . ' minutes. If you did not request this, you can ignore this email.</p>
        </div>
    ';

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_ENCRYPTION === 'tls'
            ? PHPMailer::ENCRYPTION_STARTTLS
            : PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($email);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = $plainText;

        return $mail->send();
    } catch (MailException $exception) {
        error_log('OTP email failed: ' . $exception->getMessage());
        return false;
    }
}

function flash(string $type, string $message): void
{
    start_app_session();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    start_app_session();
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return $flash;
}
