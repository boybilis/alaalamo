<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

start_app_session();

function ini_bytes(string $value): int
{
    $value = trim($value);
    $number = (float) $value;
    $unit = strtolower(substr($value, -1));

    if ($unit === 'g') {
        return (int) ($number * 1024 * 1024 * 1024);
    }

    if ($unit === 'm') {
        return (int) ($number * 1024 * 1024);
    }

    if ($unit === 'k') {
        return (int) ($number * 1024);
    }

    return (int) $number;
}

function clean_optional_url(string $value): ?string
{
    $url = trim($value);

    if ($url === '') {
        return null;
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

    return in_array($scheme, ['http', 'https'], true) ? $url : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $postMaxBytes = ini_bytes((string) ini_get('post_max_size'));

    if ($postMaxBytes > 0 && (int) $_SERVER['CONTENT_LENGTH'] > $postMaxBytes) {
        http_response_code(413);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'message' => 'Upload is too large for the server limit. Try one smaller photo or reduce the image size first.',
        ]);
        exit;
    }
}

if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', '');
}

if (!defined('GEMINI_TEXT_MODEL')) {
    define('GEMINI_TEXT_MODEL', 'gemini-2.5-flash');
}

if (!defined('CLOUDINARY_CLOUD_NAME')) {
    define('CLOUDINARY_CLOUD_NAME', '');
}

if (!defined('CLOUDINARY_API_KEY')) {
    define('CLOUDINARY_API_KEY', '');
}

if (!defined('CLOUDINARY_API_SECRET')) {
    define('CLOUDINARY_API_SECRET', '');
}

if (!function_exists('gemini_is_configured')) {
    function gemini_is_configured(): bool
    {
        return GEMINI_API_KEY !== '' && GEMINI_API_KEY !== 'replace-with-gemini-api-key';
    }
}

if (!function_exists('gemini_text_response')) {
    function gemini_text_response(string $instructions, string $input): ?string
    {
        if (!gemini_is_configured() || !function_exists('curl_init')) {
            return null;
        }

        $payload = json_encode([
            'system_instruction' => [
                'parts' => [
                    ['text' => $instructions],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $input],
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
            CURLOPT_TIMEOUT => 90,
        ]);

        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false || $status < 200 || $status >= 300) {
            error_log('Gemini text generation failed: HTTP ' . $status . ' ' . $error . ' ' . (string) $response);
            return null;
        }

        $data = json_decode($response, true);
        $parts = [];

        foreach (($data['candidates'][0]['content']['parts'] ?? []) as $part) {
            if (isset($part['text']) && is_string($part['text'])) {
                $parts[] = $part['text'];
            }
        }

        return $parts ? trim(implode("\n", $parts)) : null;
    }
}

if (empty($_SESSION['user_id'])) {
    redirect_to('/login.php');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([(int) $_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    redirect_to('/login.php');
}

$accessQrGroup = ensure_qr_group((int) $user['id']);

function uploaded_file_at(array $files, int $index): array
{
    return [
        'name' => $files['name'][$index] ?? '',
        'type' => $files['type'][$index] ?? '',
        'tmp_name' => $files['tmp_name'][$index] ?? '',
        'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
        'size' => $files['size'][$index] ?? 0,
    ];
}

function nested_uploaded_file_at(array $files, int $groupIndex, int $fileIndex): array
{
    return [
        'name' => $files['name'][$groupIndex][$fileIndex] ?? '',
        'type' => $files['type'][$groupIndex][$fileIndex] ?? '',
        'tmp_name' => $files['tmp_name'][$groupIndex][$fileIndex] ?? '',
        'error' => $files['error'][$groupIndex][$fileIndex] ?? UPLOAD_ERR_NO_FILE,
        'size' => $files['size'][$groupIndex][$fileIndex] ?? 0,
    ];
}

function upload_error_message(int $errorCode): string
{
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The image is larger than the server upload limit.',
        UPLOAD_ERR_PARTIAL => 'The image was only partially uploaded. Please try again.',
        UPLOAD_ERR_NO_FILE => 'No image was selected.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server upload folder is missing.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not save the uploaded image.',
        UPLOAD_ERR_EXTENSION => 'A server extension stopped the upload.',
        default => 'The image could not be uploaded.',
    };
}

function delete_uploaded_asset(?string $relativePath): void
{
    $path = str_replace('\\', '/', ltrim((string) $relativePath, '/'));

    if ($path === '' || !str_starts_with($path, 'uploads/') || str_contains($path, '..')) {
        return;
    }

    $uploadsRoot = realpath(__DIR__ . '/uploads');
    $target = realpath(__DIR__ . '/' . $path);

    if (!$uploadsRoot || !$target) {
        return;
    }

    $uploadsRoot = rtrim($uploadsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    if (str_starts_with($target, $uploadsRoot) && is_file($target)) {
        unlink($target);
    }
}

function cloudinary_is_configured(): bool
{
    return CLOUDINARY_CLOUD_NAME !== '' && CLOUDINARY_API_KEY !== '' && CLOUDINARY_API_SECRET !== '';
}

function upload_to_cloudinary(string $relativePath, string $folder): ?array
{
    if (!cloudinary_is_configured() || !function_exists('curl_init')) {
        return null;
    }

    $path = str_replace('\\', '/', ltrim($relativePath, '/'));

    if ($path === '' || !str_starts_with($path, 'uploads/') || str_contains($path, '..')) {
        return null;
    }

    $absolutePath = realpath(__DIR__ . '/' . $path);
    $uploadsRoot = realpath(__DIR__ . '/uploads');

    if (!$absolutePath || !$uploadsRoot || !str_starts_with($absolutePath, rtrim($uploadsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
        return null;
    }

    $timestamp = time();
    $paramsToSign = [
        'folder' => $folder,
        'timestamp' => $timestamp,
    ];
    ksort($paramsToSign);
    $signaturePairs = [];

    foreach ($paramsToSign as $key => $value) {
        $signaturePairs[] = $key . '=' . $value;
    }

    $signatureBase = implode('&', $signaturePairs) . CLOUDINARY_API_SECRET;
    $signature = sha1($signatureBase);
    $curl = curl_init('https://api.cloudinary.com/v1_1/' . rawurlencode(CLOUDINARY_CLOUD_NAME) . '/image/upload');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'file' => new CURLFile($absolutePath),
            'api_key' => CLOUDINARY_API_KEY,
            'timestamp' => (string) $timestamp,
            'folder' => $folder,
            'signature' => $signature,
        ],
        CURLOPT_TIMEOUT => 120,
    ]);

    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($response === false || $status < 200 || $status >= 300) {
        error_log('Cloudinary upload failed: HTTP ' . $status . ' ' . $error . ' ' . (string) $response);
        return null;
    }

    $data = json_decode($response, true);

    if (empty($data['secure_url'])) {
        error_log('Cloudinary upload missing secure_url: ' . (string) $response);
        return null;
    }

    return [
        'secure_url' => cloudinary_optimized_image_url((string) $data['secure_url']),
        'public_id' => (string) ($data['public_id'] ?? ''),
    ];
}

function cloudinary_optimized_image_url(string $secureUrl): string
{
    if ($secureUrl === '') {
        return $secureUrl;
    }

    $transformation = 'f_auto,q_auto,c_limit,w_1600';

    if (str_contains($secureUrl, '/upload/' . $transformation . '/')) {
        return $secureUrl;
    }

    return str_replace('/upload/', '/upload/' . $transformation . '/', $secureUrl);
}

function load_uploaded_image_resource(string $path, string $mimeType)
{
    if ($mimeType === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
        $image = @imagecreatefromjpeg($path);

        if ($image && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $orientation = (int) ($exif['Orientation'] ?? 0);

            if ($orientation === 3) {
                $image = imagerotate($image, 180, 0);
            } elseif ($orientation === 6) {
                $image = imagerotate($image, -90, 0);
            } elseif ($orientation === 8) {
                $image = imagerotate($image, 90, 0);
            }
        }

        return $image;
    }

    if ($mimeType === 'image/png' && function_exists('imagecreatefrompng')) {
        return @imagecreatefrompng($path);
    }

    if ($mimeType === 'image/webp' && function_exists('imagecreatefromwebp')) {
        return @imagecreatefromwebp($path);
    }

    return false;
}

function save_resized_jpeg($source, int $sourceWidth, int $sourceHeight, int $maxDimension, int $quality, string $target): bool
{
    $scale = min(1, $maxDimension / max($sourceWidth, $sourceHeight));
    $targetWidth = max(1, (int) round($sourceWidth * $scale));
    $targetHeight = max(1, (int) round($sourceHeight * $scale));
    $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

    if (!$canvas) {
        return false;
    }

    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);
    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
    $saved = imagejpeg($canvas, $target, $quality);
    imagedestroy($canvas);

    return $saved;
}

function store_mobile_optimized_image(array $file, string $subdirectory): ?string
{
    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($uploadError !== UPLOAD_ERR_OK) {
        error_log('Image upload failed before optimization: ' . upload_error_message($uploadError));
        return null;
    }

    if (($file['size'] ?? 0) > 3 * 1024 * 1024) {
        error_log('Image upload rejected because it is above 3MB.');
        return null;
    }

    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    if (!in_array($mimeType, $allowedTypes, true)) {
        error_log('Image upload rejected because MIME type is not allowed: ' . (string) $mimeType);
        return null;
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(120);
    }

    $dimensions = @getimagesize($file['tmp_name']);

    if (!$dimensions) {
        error_log('Image upload rejected because dimensions could not be read.');
        return null;
    }

    if (($file['size'] ?? 0) <= 1024 * 1024 && max((int) $dimensions[0], (int) $dimensions[1]) <= 1600) {
        return store_uploaded_image($file, $subdirectory);
    }

    $estimatedMemory = ((int) $dimensions[0] * (int) $dimensions[1] * 5) + (20 * 1024 * 1024);
    $memoryLimit = ini_bytes((string) ini_get('memory_limit'));

    if ($memoryLimit > 0 && $estimatedMemory > ($memoryLimit * 0.75)) {
        error_log('Image upload rejected because dimensions are too large for PHP memory: ' . (int) $dimensions[0] . 'x' . (int) $dimensions[1]);
        return null;
    }

    $relativeDirectory = 'uploads/' . trim($subdirectory, '/');
    $absoluteDirectory = __DIR__ . '/' . $relativeDirectory;
    ensure_upload_dir($absoluteDirectory);

    if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
        return store_uploaded_image($file, $subdirectory);
    }

    $source = load_uploaded_image_resource($file['tmp_name'], $mimeType);

    if (!$source) {
        return store_uploaded_image($file, $subdirectory);
    }

    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);

    if ($sourceWidth <= 0 || $sourceHeight <= 0) {
        imagedestroy($source);
        return null;
    }

    $targetBytes = 1024 * 1024;
    $filename = bin2hex(random_bytes(16)) . '.jpg';
    $target = $absoluteDirectory . '/' . $filename;
    $maxDimensions = [1600, 1400, 1200, 1000, 800, 640];
    $qualities = [82, 76, 70, 64, 58, 52];
    $saved = false;

    foreach ($maxDimensions as $maxDimension) {
        foreach ($qualities as $quality) {
            if (!save_resized_jpeg($source, $sourceWidth, $sourceHeight, $maxDimension, $quality, $target)) {
                continue;
            }

            $saved = true;

            if (filesize($target) <= $targetBytes) {
                break 2;
            }
        }
    }

    imagedestroy($source);

    if (!$saved || !is_file($target)) {
        return null;
    }

    return $relativeDirectory . '/' . $filename;
}

function clean_hex_color(string $value, string $fallback): string
{
    $color = trim($value);

    if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        return strtolower($color);
    }

    return $fallback;
}

function clean_coordinate(string $value, float $min, float $max): ?string
{
    $coordinate = trim($value);

    if ($coordinate === '' || !is_numeric($coordinate)) {
        return null;
    }

    $number = (float) $coordinate;

    if ($number < $min || $number > $max) {
        return null;
    }

    return number_format($number, 7, '.', '');
}

function qr_plan_type(array $qrGroup): string
{
    return ($qrGroup['plan_type'] ?? 'regular') === 'premium' ? 'premium' : 'regular';
}

function memorial_plan_type(?array $memorial, ?array $qrGroup = null): string
{
    if ($memorial && isset($memorial['plan_type'])) {
        return $memorial['plan_type'] === 'premium' ? 'premium' : 'regular';
    }

    return $qrGroup ? qr_plan_type($qrGroup) : 'regular';
}

function plan_limits_for_type(string $planType): array
{
    $isPremium = $planType === 'premium';

    return [
        'profile_images' => 5,
        'gallery_images' => $isPremium ? 20 : 6,
        'milestones' => $isPremium ? 5 : 2,
        'milestone_images' => $isPremium ? 6 : 2,
        'milestone_characters' => $isPremium ? 500 : 250,
        'life_story' => $isPremium,
    ];
}

function memorial_plan_limits(?array $memorial, ?array $qrGroup = null): array
{
    return plan_limits_for_type(memorial_plan_type($memorial, $qrGroup));
}

function requested_plan_type(string $value): string
{
    return $value === 'premium' ? 'premium' : 'regular';
}

function effective_memorial_plan_type(array $memorial, ?array $qrGroup, ?string $requestedPlanType = null): string
{
    if (($memorial['payment_status'] ?? 'pending') === 'paid') {
        return memorial_plan_type($memorial, $qrGroup);
    }

    if ($requestedPlanType !== null && $requestedPlanType !== '') {
        return requested_plan_type($requestedPlanType);
    }

    return memorial_plan_type($memorial, $qrGroup);
}

function additional_memorial_price_for_type(string $planType): int
{
    return $planType === 'premium' ? 700 : 399;
}

function memorial_expiration_at(?array $memorial): ?DateTimeImmutable
{
    $paidAt = trim((string) ($memorial['paid_at'] ?? ''));

    if ($paidAt === '') {
        return null;
    }

    try {
        return (new DateTimeImmutable($paidAt))->modify('+1 year');
    } catch (Throwable $exception) {
        return null;
    }
}

function memorial_expiration_label(?array $memorial): ?string
{
    $expiresAt = memorial_expiration_at($memorial);

    if (!$expiresAt) {
        return null;
    }

    return $expiresAt->format('F d, Y');
}

function memorial_expiration_countdown(?array $memorial): ?string
{
    $expiresAt = memorial_expiration_at($memorial);

    if (!$expiresAt) {
        return null;
    }

    $today = new DateTimeImmutable('today');
    $days = (int) $today->diff($expiresAt)->format('%r%a');

    if ($days < 0) {
        return 'Expired ' . abs($days) . ' day' . (abs($days) === 1 ? '' : 's') . ' ago';
    }

    if ($days === 0) {
        return 'Expires today';
    }

    return $days . ' day' . ($days === 1 ? '' : 's') . ' left';
}

function is_ajax_request(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || ($_POST['ajax'] ?? '') === '1';
}

function json_response(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function image_preview_html(array $image, string $type): string
{
    $path = htmlspecialchars((string) $image['image_path'], ENT_QUOTES, 'UTF-8');
    $id = (int) $image['id'];
    $label = $type === 'profile' ? 'Profile image preview' : ($type === 'gallery' ? 'Gallery image preview' : 'Milestone image preview');

    return '<div class="image-preview-item">'
        . '<img src="' . $path . '" alt="' . $label . '">'
        . '<button class="image-delete-link" type="button" data-image-delete="' . $type . ':' . $id . '">Delete</button>'
        . '</div>';
}

function extract_ai_story_payload(string $text): ?array
{
    $clean = trim($text);
    $clean = preg_replace('/^```json\s*/i', '', $clean) ?? $clean;
    $clean = preg_replace('/^```\s*/', '', $clean) ?? $clean;
    $clean = preg_replace('/\s*```$/', '', $clean) ?? $clean;
    $decoded = json_decode($clean, true);

    if (is_array($decoded)) {
        return $decoded;
    }

    if (preg_match('/\{.*\}/s', $clean, $matches)) {
        $decoded = json_decode($matches[0], true);
        return is_array($decoded) ? $decoded : null;
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = clean_input($_POST['form_action'] ?? 'save_memorial');
    $memorialIdInput = (int) ($_POST['memorial_id'] ?? 0);
    $qrGroup = ensure_qr_group((int) $user['id']);
    $planLimits = memorial_plan_limits(null, $qrGroup);
    $isAjax = is_ajax_request();

    if ($formAction === 'approve_message' || $formAction === 'delete_message') {
        $messageId = (int) ($_POST['message_id'] ?? 0);

        $stmt = $pdo->prepare(
            'SELECT mm.*, me.id AS memorial_id
             FROM memorial_messages mm
             INNER JOIN memorials me ON me.id = mm.memorial_id
             WHERE mm.id = ? AND me.user_id = ? AND me.qr_group_id = ?
             LIMIT 1'
        );
        $stmt->execute([$messageId, (int) $user['id'], (int) $qrGroup['id']]);
        $messageForAction = $stmt->fetch();

        if (!$messageForAction) {
            flash('error', 'Message not found.');
            redirect_to('/dashboard.php?memorial_id=' . $memorialIdInput);
        }

        if ($formAction === 'approve_message') {
            $pdo->prepare('UPDATE memorial_messages SET status = "approved", approved_at = NOW() WHERE id = ?')
                ->execute([$messageId]);
            flash('success', 'Message approved and published.');
        } else {
            $pdo->prepare('DELETE FROM memorial_messages WHERE id = ?')
                ->execute([$messageId]);
            flash('success', 'Message deleted.');
        }

        redirect_to('/dashboard.php?memorial_id=' . (int) $messageForAction['memorial_id'] . '#messages-admin');
    }

    if ($formAction === 'approve_community_photo' || $formAction === 'delete_community_photo') {
        $photoId = (int) ($_POST['photo_id'] ?? 0);
        $stmt = $pdo->prepare(
            'SELECT cp.*, me.id AS memorial_id
             FROM memorial_community_photos cp
             INNER JOIN memorials me ON me.id = cp.memorial_id
             WHERE cp.id = ? AND me.user_id = ? AND me.qr_group_id = ?
             LIMIT 1'
        );
        $stmt->execute([$photoId, (int) $user['id'], (int) $qrGroup['id']]);
        $photoForAction = $stmt->fetch();

        if (!$photoForAction) {
            flash('error', 'Shared photo not found.');
            redirect_to('/dashboard.php?memorial_id=' . $memorialIdInput . '#community-photos-admin');
        }

        if ($formAction === 'approve_community_photo') {
            if (!$photoForAction['image_url']) {
                $folder = 'alaalamo/memorial-' . (int) $photoForAction['memorial_id'] . '/community';
                $cloudinary = upload_to_cloudinary((string) $photoForAction['temp_image_path'], $folder);

                if (!$cloudinary) {
                    flash('error', 'Cloudinary upload failed. Check Cloudinary config and Hostinger logs.');
                    redirect_to('/dashboard.php?memorial_id=' . (int) $photoForAction['memorial_id'] . '#community-photos-admin');
                }

                $pdo->prepare(
                    'UPDATE memorial_community_photos
                     SET image_url = ?, cloudinary_public_id = ?, temp_image_path = NULL, status = "approved", approved_at = NOW()
                     WHERE id = ?'
                )->execute([$cloudinary['secure_url'], $cloudinary['public_id'], $photoId]);
                delete_uploaded_asset($photoForAction['temp_image_path'] ?? null);
            } else {
                $pdo->prepare('UPDATE memorial_community_photos SET status = "approved", approved_at = NOW() WHERE id = ?')
                    ->execute([$photoId]);
            }

            flash('success', 'Shared photo approved and published.');
        } else {
            delete_uploaded_asset($photoForAction['temp_image_path'] ?? null);
            $pdo->prepare('DELETE FROM memorial_community_photos WHERE id = ?')->execute([$photoId]);
            flash('success', 'Shared photo deleted.');
        }

        redirect_to('/dashboard.php?memorial_id=' . (int) $photoForAction['memorial_id'] . '#community-photos-admin');
    }

    if ($formAction === 'generate_milestone_ai') {
        if (!$planLimits['life_story']) {
            json_response(['ok' => false, 'message' => 'Enhanced biography is available only on premium plans.'], 403);
        }

        if (!gemini_is_configured()) {
            json_response(['ok' => false, 'message' => 'Gemini API key is not configured yet in config.php.'], 422);
        }

        $milestoneIdInput = (int) ($_POST['milestone_id'] ?? 0);
        $title = clean_input($_POST['title'] ?? '');
        $milestoneDate = clean_input($_POST['milestone_date'] ?? '');
        $description = clean_input($_POST['description'] ?? '');

        if ($milestoneIdInput <= 0 || $memorialIdInput <= 0) {
            json_response(['ok' => false, 'message' => 'Save this milestone first before generating enhanced text.'], 422);
        }

        $stmt = $pdo->prepare('SELECT * FROM memorials WHERE id = ? AND user_id = ? AND qr_group_id = ? LIMIT 1');
        $stmt->execute([$memorialIdInput, (int) $user['id'], (int) $qrGroup['id']]);
        $memorialForAi = $stmt->fetch();

        if (!$memorialForAi) {
            json_response(['ok' => false, 'message' => 'Memorial profile was not found.'], 404);
        }

        $stmt = $pdo->prepare('SELECT * FROM milestones WHERE id = ? AND memorial_id = ? LIMIT 1');
        $stmt->execute([$milestoneIdInput, (int) $memorialForAi['id']]);
        $milestoneForAi = $stmt->fetch();

        if (!$milestoneForAi) {
            json_response(['ok' => false, 'message' => 'Milestone was not found. Save it first, then try again.'], 404);
        }

        if ($title === '') {
            $title = clean_input((string) ($milestoneForAi['title'] ?? ''));
        }

        if ($milestoneDate === '') {
            $milestoneDate = clean_input((string) ($milestoneForAi['milestone_date'] ?? ''));
        }

        if ($description === '') {
            $description = clean_input((string) ($milestoneForAi['description'] ?? ''));
        }

        if ($title === '' || $description === '') {
            json_response(['ok' => false, 'message' => 'Add a title and description before generating enhanced text.'], 422);
        }

        $instructions = 'You write simple, heartfelt, respectful memorial prose for Filipino families. Do not invent facts. Use only the provided details. Avoid flowery, overly dramatic, or complicated language. Make every sentence easy to understand, sincere, and emotionally meaningful.';
        $prompt = implode("\n", [
            'Loved one: ' . ($memorialForAi['loved_one_name'] ?? ''),
            'Birth date: ' . ($memorialForAi['birth_date'] ?? ''),
            'Death date: ' . ($memorialForAi['death_date'] ?? ''),
            'Resting place: ' . ($memorialForAi['resting_place'] ?? ''),
            'Memorial description: ' . ($memorialForAi['short_description'] ?? ''),
            '',
            'Milestone title: ' . $title,
            'Milestone date or period: ' . $milestoneDate,
            'Milestone details: ' . $description,
            '',
            'Rewrite this single milestone as an enhanced biography narration. Write in third person, using the loved one\'s name when natural. Keep it solemn, warm, and natural. Do not invent facts. Do not mention that this was generated by AI. Return plain text only. Maximum 50 words, so the narration can finish while up to 6 photos gently fade through the slideshow.',
        ]);
        $generatedText = gemini_text_response(
            $instructions,
            $prompt
        );
        $generatedText = $generatedText ? trim($generatedText) : '';

        if ($generatedText === '') {
            json_response(['ok' => false, 'message' => 'Enhanced text could not be generated. Check Gemini key, quota, cURL support, and Hostinger error logs.'], 422);
        }

        json_response([
            'ok' => true,
            'message' => 'Enhanced biography generated. Review it before saving.',
            'text' => $generatedText,
        ]);
    }

    if ($formAction === 'save_milestone_ai') {
        if (!$planLimits['life_story']) {
            json_response(['ok' => false, 'message' => 'Enhanced biography is available only on premium plans.'], 403);
        }

        $milestoneIdInput = (int) ($_POST['milestone_id'] ?? 0);
        $generatedText = trim((string) ($_POST['generated_text'] ?? ''));

        if ($milestoneIdInput <= 0 || $memorialIdInput <= 0 || $generatedText === '') {
            json_response(['ok' => false, 'message' => 'Generated text is required before saving.'], 422);
        }

        $stmt = $pdo->prepare(
            'SELECT m.*
             FROM milestones m
             INNER JOIN memorials me ON me.id = m.memorial_id
             WHERE m.id = ? AND m.memorial_id = ? AND me.user_id = ? AND me.qr_group_id = ?
             LIMIT 1'
        );
        $stmt->execute([$milestoneIdInput, $memorialIdInput, (int) $user['id'], (int) $qrGroup['id']]);

        if (!$stmt->fetch()) {
            json_response(['ok' => false, 'message' => 'Milestone was not found.'], 404);
        }

        $pdo->prepare(
            'UPDATE milestones
             SET ai_narration_text = ?, narration_audio_path = NULL, narration_generated_at = NOW()
             WHERE id = ? AND memorial_id = ?'
        )->execute([$generatedText, $milestoneIdInput, $memorialIdInput]);

        json_response([
            'ok' => true,
            'message' => 'Enhanced biography saved for this milestone.',
            'text' => $generatedText,
        ]);
    }

    if ($formAction === 'delete_image') {
        $imageDelete = clean_input($_POST['image_delete'] ?? '');
        [$imageType, $imageIdText] = array_pad(explode(':', $imageDelete, 2), 2, '');
        $imageId = (int) $imageIdText;

        if (($imageType === 'profile' || $imageType === 'gallery') && $imageId > 0) {
            $stmt = $pdo->prepare(
                'SELECT mi.*
                 FROM memorial_images mi
                 INNER JOIN memorials me ON me.id = mi.memorial_id
                 WHERE mi.id = ? AND mi.image_type = ? AND me.user_id = ? AND me.qr_group_id = ?
                 LIMIT 1'
            );
            $stmt->execute([$imageId, $imageType, (int) $user['id'], (int) $qrGroup['id']]);
            $image = $stmt->fetch();

            if ($image) {
                $pdo->prepare('DELETE FROM memorial_images WHERE id = ?')->execute([$imageId]);
                delete_uploaded_asset($image['image_path'] ?? null);
                $deleteLabel = $imageType === 'profile' ? 'Profile image' : 'Gallery image';

                if ($isAjax) {
                    json_response(['ok' => true, 'message' => $deleteLabel . ' deleted.']);
                }

                flash('success', $deleteLabel . ' deleted.');
                redirect_to('/dashboard.php?memorial_id=' . (int) $image['memorial_id']);
            }
        }

        if ($imageType === 'milestone' && $imageId > 0) {
            $stmt = $pdo->prepare(
                'SELECT mii.*, m.memorial_id
                 FROM milestone_images mii
                 INNER JOIN milestones m ON m.id = mii.milestone_id
                 INNER JOIN memorials me ON me.id = m.memorial_id
                 WHERE mii.id = ? AND me.user_id = ? AND me.qr_group_id = ?
                 LIMIT 1'
            );
            $stmt->execute([$imageId, (int) $user['id'], (int) $qrGroup['id']]);
            $image = $stmt->fetch();

            if ($image) {
                $pdo->prepare('DELETE FROM milestone_images WHERE id = ?')->execute([$imageId]);
                delete_uploaded_asset($image['image_path'] ?? null);
                if ($isAjax) {
                    json_response(['ok' => true, 'message' => 'Milestone image deleted.']);
                }

                flash('success', 'Milestone image deleted.');
                redirect_to('/dashboard.php?memorial_id=' . (int) $image['memorial_id']);
            }
        }

        if ($isAjax) {
            json_response(['ok' => false, 'message' => 'The image could not be deleted.'], 422);
        }

        flash('error', 'The image could not be deleted.');
        redirect_to('/dashboard.php?memorial_id=' . $memorialIdInput);
    }

    if ($formAction === 'save_milestone') {
        $milestoneIdInput = (int) ($_POST['milestone_id'] ?? 0);
        $sortOrderInput = (int) ($_POST['sort_order'] ?? 0);
        $title = clean_input($_POST['title'] ?? '');
        $milestoneDate = clean_input($_POST['milestone_date'] ?? '');
        $description = clean_input($_POST['description'] ?? '');

        if (!$isAjax) {
            redirect_to('/dashboard.php?memorial_id=' . $memorialIdInput);
        }

        if ($memorialIdInput <= 0) {
            json_response(['ok' => false, 'message' => 'Save the memorial profile first before saving milestones.'], 422);
        }

        if ($title === '') {
            json_response(['ok' => false, 'message' => 'Milestone title is required.'], 422);
        }

        $stmt = $pdo->prepare('SELECT * FROM memorials WHERE id = ? AND user_id = ? AND qr_group_id = ? LIMIT 1');
        $stmt->execute([$memorialIdInput, (int) $user['id'], (int) $qrGroup['id']]);
        $targetMemorial = $stmt->fetch();

        if (!$targetMemorial) {
            json_response(['ok' => false, 'message' => 'Memorial not found.'], 404);
        }

        $planLimits = memorial_plan_limits($targetMemorial, $qrGroup);
        $sortOrder = max(0, min($planLimits['milestones'] - 1, $sortOrderInput));

        if (strlen($description) > $planLimits['milestone_characters']) {
            json_response([
                'ok' => false,
                'message' => 'Milestone description is limited to ' . $planLimits['milestone_characters'] . ' characters for this plan.',
            ], 422);
        }

        if ($milestoneIdInput > 0) {
            $stmt = $pdo->prepare('SELECT * FROM milestones WHERE id = ? AND memorial_id = ? LIMIT 1');
            $stmt->execute([$milestoneIdInput, $memorialIdInput]);

            if (!$stmt->fetch()) {
                json_response(['ok' => false, 'message' => 'Milestone not found.'], 404);
            }

            $pdo->prepare(
                'UPDATE milestones
                 SET title = ?, milestone_date = ?, description = ?, sort_order = ?
                 WHERE id = ? AND memorial_id = ?'
            )->execute([$title, $milestoneDate, $description, $sortOrder, $milestoneIdInput, $memorialIdInput]);
            $savedMilestoneId = $milestoneIdInput;
        } else {
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM milestones WHERE memorial_id = ?');
            $countStmt->execute([$memorialIdInput]);

            if ((int) $countStmt->fetchColumn() >= $planLimits['milestones']) {
                json_response(['ok' => false, 'message' => 'Maximum milestones reached.'], 422);
            }

            $pdo->prepare(
                'INSERT INTO milestones (memorial_id, title, milestone_date, description, sort_order)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$memorialIdInput, $title, $milestoneDate, $description, $sortOrder]);
            $savedMilestoneId = (int) $pdo->lastInsertId();
        }

        json_response([
            'ok' => true,
            'message' => 'Milestone saved.',
            'milestone_id' => $savedMilestoneId,
        ]);
    }

    if ($formAction === 'delete_milestone') {
        $milestoneIdInput = (int) ($_POST['milestone_id'] ?? 0);

        if (!$isAjax) {
            redirect_to('/dashboard.php?memorial_id=' . $memorialIdInput);
        }

        $stmt = $pdo->prepare(
            'SELECT m.*, me.plan_type AS memorial_plan_type
             FROM milestones m
             INNER JOIN memorials me ON me.id = m.memorial_id
             WHERE m.id = ? AND me.user_id = ? AND me.qr_group_id = ?
             LIMIT 1'
        );
        $stmt->execute([$milestoneIdInput, (int) $user['id'], (int) $qrGroup['id']]);
        $targetMilestone = $stmt->fetch();

        if (!$targetMilestone) {
            json_response(['ok' => false, 'message' => 'Milestone not found.'], 404);
        }

        $stmt = $pdo->prepare('SELECT * FROM milestone_images WHERE milestone_id = ?');
        $stmt->execute([(int) $targetMilestone['id']]);
        $imagesToDelete = $stmt->fetchAll();

        $pdo->beginTransaction();

        try {
            $pdo->prepare('DELETE FROM milestone_images WHERE milestone_id = ?')->execute([(int) $targetMilestone['id']]);
            $pdo->prepare('DELETE FROM milestones WHERE id = ?')->execute([(int) $targetMilestone['id']]);
            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            error_log('Milestone delete failed: ' . $exception->getMessage());
            json_response(['ok' => false, 'message' => 'Milestone could not be deleted.'], 500);
        }

        foreach ($imagesToDelete as $imageToDelete) {
            delete_uploaded_asset($imageToDelete['image_path'] ?? null);
        }

        json_response(['ok' => true, 'message' => 'Milestone deleted.']);
    }

    if ($formAction === 'upload_milestone_images') {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $milestoneIdInput = (int) ($_POST['milestone_id'] ?? 0);
        $requestedPlanType = requested_plan_type((string) ($_POST['plan_type'] ?? 'regular'));

        $stmt = $pdo->prepare(
            'SELECT m.*, me.plan_type AS memorial_plan_type, me.payment_status AS memorial_payment_status
             FROM milestones m
             INNER JOIN memorials me ON me.id = m.memorial_id
             WHERE m.id = ? AND me.user_id = ? AND me.qr_group_id = ?
             LIMIT 1'
        );
        $stmt->execute([$milestoneIdInput, (int) $user['id'], (int) $qrGroup['id']]);
        $targetMilestone = $stmt->fetch();

        if (!$targetMilestone) {
            if ($isAjax) {
                json_response(['ok' => false, 'message' => 'Please save the milestone before uploading images.'], 422);
            }

            flash('error', 'Please save the milestone before uploading images.');
            redirect_to('/dashboard.php?memorial_id=' . $memorialIdInput);
        }

        $effectivePlanType = effective_memorial_plan_type([
            'plan_type' => $targetMilestone['memorial_plan_type'] ?? 'regular',
            'payment_status' => $targetMilestone['memorial_payment_status'] ?? 'pending',
        ], $qrGroup, $requestedPlanType);
        $planLimits = plan_limits_for_type($effectivePlanType);
        $existingCountStmt = $pdo->prepare('SELECT COUNT(*) FROM milestone_images WHERE milestone_id = ?');
        $existingCountStmt->execute([(int) $targetMilestone['id']]);
        $existingCount = (int) $existingCountStmt->fetchColumn();
        $remainingSlots = max(0, $planLimits['milestone_images'] - $existingCount);

        if ($remainingSlots === 0) {
            if ($isAjax) {
                json_response(['ok' => false, 'message' => 'This milestone already has the maximum number of images.'], 422);
            }

            flash('error', 'This milestone already has the maximum number of images.');
            redirect_to('/dashboard.php?memorial_id=' . (int) $targetMilestone['memorial_id']);
        }

        $uploaded = 0;
        $uploadedHtml = [];
        $uploadErrorMessage = 'No valid milestone images were uploaded.';
        if (!empty($_FILES['milestone_images']['name'])) {
            $imageCount = min(count($_FILES['milestone_images']['name']), $remainingSlots);

            for ($i = 0; $i < $imageCount; $i++) {
                $uploadFile = uploaded_file_at($_FILES['milestone_images'], $i);

                if (($uploadFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    $uploadErrorMessage = upload_error_message((int) $uploadFile['error']);
                    continue;
                }

                $path = store_mobile_optimized_image(
                    $uploadFile,
                    'users/' . (int) $user['id'] . '/memorials/' . (int) $targetMilestone['memorial_id'] . '/milestones/' . (int) $targetMilestone['id']
                );

                if ($path) {
                    $pdo->prepare('INSERT INTO milestone_images (milestone_id, image_path) VALUES (?, ?)')
                        ->execute([(int) $targetMilestone['id'], $path]);
                    $uploadedHtml[] = image_preview_html([
                        'id' => (int) $pdo->lastInsertId(),
                        'image_path' => $path,
                    ], 'milestone');
                    $uploaded++;
                } else {
                    $uploadErrorMessage = 'The image could not be processed. Try a JPG photo under 3MB.';
                }

                unset($uploadFile, $path);

                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        }

        if ($isAjax) {
            json_response([
                'ok' => $uploaded > 0,
                'message' => $uploaded > 0 ? 'Milestone images uploaded.' : $uploadErrorMessage,
                'html' => implode('', $uploadedHtml),
                'count' => $existingCount + $uploaded,
                'limit' => $planLimits['milestone_images'],
            ], $uploaded > 0 ? 200 : 422);
        }

        flash($uploaded > 0 ? 'success' : 'error', $uploaded > 0 ? 'Milestone images uploaded.' : $uploadErrorMessage);
        redirect_to('/dashboard.php?memorial_id=' . (int) $targetMilestone['memorial_id']);
    }

    if ($formAction === 'upload_profile_images') {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $requestedPlanType = requested_plan_type((string) ($_POST['plan_type'] ?? 'regular'));

        $stmt = $pdo->prepare(
            'SELECT * FROM memorials WHERE id = ? AND user_id = ? AND qr_group_id = ? LIMIT 1'
        );
        $stmt->execute([$memorialIdInput, (int) $user['id'], (int) $qrGroup['id']]);
        $targetMemorial = $stmt->fetch();

        if (!$targetMemorial) {
            json_response(['ok' => false, 'message' => 'Save the memorial details first before uploading profile photos.'], 422);
        }

        $planLimits = plan_limits_for_type(effective_memorial_plan_type($targetMemorial, $qrGroup, $requestedPlanType));
        $existingCountStmt = $pdo->prepare('SELECT COUNT(*) FROM memorial_images WHERE memorial_id = ? AND image_type = "profile"');
        $existingCountStmt->execute([(int) $targetMemorial['id']]);
        $existingCount = (int) $existingCountStmt->fetchColumn();
        $remainingSlots = max(0, $planLimits['profile_images'] - $existingCount);

        if ($remainingSlots === 0) {
            json_response(['ok' => false, 'message' => 'This memorial already has the maximum number of profile photos.'], 422);
        }

        $uploaded = 0;
        $uploadedHtml = [];
        $uploadErrorMessage = 'No valid profile photos were uploaded.';

        if (!empty($_FILES['profile_images']['name'])) {
            $imageCount = min(count($_FILES['profile_images']['name']), $remainingSlots);

            for ($i = 0; $i < $imageCount; $i++) {
                $uploadFile = uploaded_file_at($_FILES['profile_images'], $i);

                if (($uploadFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    $uploadErrorMessage = upload_error_message((int) $uploadFile['error']);
                    continue;
                }

                $path = store_mobile_optimized_image(
                    $uploadFile,
                    'users/' . (int) $user['id'] . '/memorials/' . (int) $targetMemorial['id'] . '/profile'
                );

                if ($path) {
                    $pdo->prepare('INSERT INTO memorial_images (memorial_id, image_type, image_path) VALUES (?, "profile", ?)')
                        ->execute([(int) $targetMemorial['id'], $path]);
                    $uploadedHtml[] = image_preview_html([
                        'id' => (int) $pdo->lastInsertId(),
                        'image_path' => $path,
                    ], 'profile');
                    $uploaded++;
                } else {
                    $uploadErrorMessage = 'The profile photo could not be processed. Try a JPG photo under 3MB.';
                }

                unset($uploadFile, $path);

                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        }

        json_response([
            'ok' => $uploaded > 0,
            'message' => $uploaded > 0 ? 'Profile photos uploaded.' : $uploadErrorMessage,
            'html' => implode('', $uploadedHtml),
            'count' => $existingCount + $uploaded,
            'limit' => $planLimits['profile_images'],
        ], $uploaded > 0 ? 200 : 422);
    }

    $lovedOneName = clean_input($_POST['loved_one_name'] ?? '');
    $birthDate = clean_input($_POST['birth_date'] ?? '');
    $deathDate = clean_input($_POST['death_date'] ?? '');
    $restingPlace = clean_input($_POST['resting_place'] ?? '');
    $restingLat = clean_coordinate((string) ($_POST['resting_lat'] ?? ''), -90, 90);
    $restingLng = clean_coordinate((string) ($_POST['resting_lng'] ?? ''), -180, 180);
    $memorialQuote = clean_input($_POST['memorial_quote'] ?? '');
    $favoriteSongUrl = clean_optional_url((string) ($_POST['favorite_song_url'] ?? ''));
    $shortDescription = clean_input($_POST['short_description'] ?? '');
    $themePrimary = clean_hex_color($_POST['theme_primary'] ?? '', '#214c63');
    $themeSecondary = clean_hex_color($_POST['theme_secondary'] ?? '', '#eadcc8');
    $themeTertiary = clean_hex_color($_POST['theme_tertiary'] ?? '', '#fbfaf7');
    $selectedPlanType = clean_input($_POST['plan_type'] ?? 'regular');
    $selectedPlanType = $selectedPlanType === 'premium' ? 'premium' : 'regular';

    if ($lovedOneName === '') {
        flash('error', 'Please enter the name of the loved one for the memorial profile.');
        redirect_to('/dashboard.php');
    }

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare('SELECT * FROM memorials WHERE id = ? AND user_id = ? AND qr_group_id = ? LIMIT 1');
        $stmt->execute([$memorialIdInput, (int) $user['id'], (int) $qrGroup['id']]);
        $memorial = $stmt->fetch();

        if ($memorial) {
            $memorialId = (int) $memorial['id'];
            $token = $memorial['public_token'];
            $effectivePlanType = (($memorial['payment_status'] ?? 'pending') === 'paid')
                ? memorial_plan_type($memorial, $qrGroup)
                : $selectedPlanType;
            $planLimits = plan_limits_for_type($effectivePlanType);
            $pdo->prepare(
                'UPDATE memorials
                 SET plan_type = ?, loved_one_name = ?, birth_date = ?, death_date = ?, resting_place = ?, resting_lat = ?, resting_lng = ?, memorial_quote = ?, favorite_song_url = ?, short_description = ?, theme_primary = ?, theme_secondary = ?, theme_tertiary = ?, status = "published"
                 WHERE id = ?'
            )->execute([
                $effectivePlanType,
                $lovedOneName,
                $birthDate !== '' ? $birthDate : null,
                $deathDate !== '' ? $deathDate : null,
                $restingPlace !== '' ? $restingPlace : null,
                $restingLat,
                $restingLng,
                $memorialQuote !== '' ? $memorialQuote : null,
                $favoriteSongUrl,
                $shortDescription !== '' ? $shortDescription : null,
                $themePrimary,
                $themeSecondary,
                $themeTertiary,
                $memorialId,
            ]);
        } else {
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM memorials WHERE qr_group_id = ?');
            $countStmt->execute([(int) $qrGroup['id']]);
            $memorialCount = (int) $countStmt->fetchColumn();

            if ($memorialCount >= MAX_MEMORIALS_PER_QR) {
                throw new RuntimeException('Maximum memorials reached for this QR group.');
            }

            $token = generate_token();
            $effectivePlanType = $selectedPlanType;
            $planLimits = plan_limits_for_type($effectivePlanType);
            $pdo->prepare(
                'INSERT INTO memorials
                 (user_id, qr_group_id, plan_type, public_token, loved_one_name, birth_date, death_date, resting_place, resting_lat, resting_lng, memorial_quote, favorite_song_url, short_description, theme_primary, theme_secondary, theme_tertiary, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "published")'
            )->execute([
                (int) $user['id'],
                (int) $qrGroup['id'],
                $effectivePlanType,
                $token,
                $lovedOneName,
                $birthDate !== '' ? $birthDate : null,
                $deathDate !== '' ? $deathDate : null,
                $restingPlace !== '' ? $restingPlace : null,
                $restingLat,
                $restingLng,
                $memorialQuote !== '' ? $memorialQuote : null,
                $favoriteSongUrl,
                $shortDescription !== '' ? $shortDescription : null,
                $themePrimary,
                $themeSecondary,
                $themeTertiary,
            ]);
            $memorialId = (int) $pdo->lastInsertId();
        }

        if (!empty($_FILES['gallery_images']['name'])) {
            $existingCountStmt = $pdo->prepare('SELECT COUNT(*) FROM memorial_images WHERE memorial_id = ? AND image_type = "gallery"');
            $existingCountStmt->execute([$memorialId]);
            $existingCount = (int) $existingCountStmt->fetchColumn();
            $remainingSlots = max(0, $planLimits['gallery_images'] - $existingCount);
            $galleryUploadCount = min(count($_FILES['gallery_images']['name']), $remainingSlots);

            for ($i = 0; $i < $galleryUploadCount; $i++) {
                $path = store_mobile_optimized_image(uploaded_file_at($_FILES['gallery_images'], $i), 'users/' . (int) $user['id'] . '/memorials/' . $memorialId . '/gallery');

                if ($path) {
                    $pdo->prepare('INSERT INTO memorial_images (memorial_id, image_type, image_path) VALUES (?, "gallery", ?)')
                        ->execute([$memorialId, $path]);
                }
            }
        }

        $milestoneIds = $_POST['milestone_id'] ?? [];
        $titles = $_POST['milestone_title'] ?? [];
        $dates = $_POST['milestone_date'] ?? [];
        $descriptions = $_POST['milestone_description'] ?? [];
        $milestoneCount = min(count($titles), $planLimits['milestones']);

        for ($i = 0; $i < $milestoneCount; $i++) {
            $milestoneIdInput = (int) ($milestoneIds[$i] ?? 0);
            $title = clean_input($titles[$i] ?? '');
            $description = substr(clean_input($descriptions[$i] ?? ''), 0, $planLimits['milestone_characters']);

            if ($title === '') {
                continue;
            }

            if ($milestoneIdInput > 0) {
                $pdo->prepare(
                    'UPDATE milestones
                     SET title = ?, milestone_date = ?, description = ?, sort_order = ?
                     WHERE id = ? AND memorial_id = ?'
                )->execute([
                    $title,
                    clean_input($dates[$i] ?? ''),
                    $description,
                    $i,
                    $milestoneIdInput,
                    $memorialId,
                ]);
            } else {
                $pdo->prepare(
                    'INSERT INTO milestones (memorial_id, title, milestone_date, description, sort_order)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([
                    $memorialId,
                    $title,
                    clean_input($dates[$i] ?? ''),
                    $description,
                    $i,
                ]);
            }
        }

        $pdo->commit();
        flash('success', 'Memorial details saved. Your QR preview link remains the same.');
        redirect_to('/dashboard.php?memorial_id=' . $memorialId);
    } catch (Throwable $exception) {
        $pdo->rollBack();
        error_log('Memorial save failed: ' . $exception->getMessage());
        flash('error', 'The memorial could not be saved. Please try again.');
        redirect_to('/dashboard.php');
    }
}

$qrGroup = $accessQrGroup;
$planType = qr_plan_type($qrGroup);
$planLimits = memorial_plan_limits(null, $qrGroup);
$stmt = $pdo->prepare('SELECT * FROM memorials WHERE user_id = ? AND qr_group_id = ? ORDER BY id ASC');
$stmt->execute([(int) $user['id'], (int) $qrGroup['id']]);
$memorials = $stmt->fetchAll();

$selectedMemorialId = (int) ($_GET['memorial_id'] ?? 0);
$memorial = null;

foreach ($memorials as $candidate) {
    if ($selectedMemorialId > 0 && (int) $candidate['id'] === $selectedMemorialId) {
        $memorial = $candidate;
        break;
    }
}

if (!$memorial && $memorials) {
    $memorial = $memorials[0];
}

if (isset($_GET['new']) && count($memorials) < MAX_MEMORIALS_PER_QR) {
    $memorial = null;
}

$planType = memorial_plan_type($memorial, $qrGroup);
$planLimits = memorial_plan_limits($memorial, $qrGroup);
$firstMemorialId = $memorials ? (int) $memorials[0]['id'] : 0;
$isCreatingAdditionalMemorial = !$memorial && count($memorials) > 0;
$isCurrentMemorialAdditional = $isCreatingAdditionalMemorial || ($memorial && $firstMemorialId > 0 && (int) $memorial['id'] !== $firstMemorialId);

$milestones = [];
$milestoneImages = [];
$profileImages = [];
$galleryImages = [];
$memorialMessages = [];
$communityPhotos = [];
if ($memorial) {
    $stmt = $pdo->prepare(
        'SELECT mi.*
         FROM memorial_images mi
         INNER JOIN memorials me ON me.id = mi.memorial_id
         WHERE mi.memorial_id = ? AND mi.image_type = "profile" AND me.user_id = ? AND me.qr_group_id = ?
         ORDER BY mi.id ASC'
    );
    $stmt->execute([(int) $memorial['id'], (int) $user['id'], (int) $qrGroup['id']]);
    $profileImages = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT mi.*
         FROM memorial_images mi
         INNER JOIN memorials me ON me.id = mi.memorial_id
         WHERE mi.memorial_id = ? AND mi.image_type = "gallery" AND me.user_id = ? AND me.qr_group_id = ?
         ORDER BY mi.id ASC'
    );
    $stmt->execute([(int) $memorial['id'], (int) $user['id'], (int) $qrGroup['id']]);
    $galleryImages = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM milestones WHERE memorial_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([(int) $memorial['id']]);
    $milestones = $stmt->fetchAll();

    if ($milestones) {
        $imageStmt = $pdo->prepare(
            'SELECT mii.*
             FROM milestone_images mii
             INNER JOIN milestones m ON m.id = mii.milestone_id
             INNER JOIN memorials me ON me.id = m.memorial_id
             WHERE mii.milestone_id = ? AND me.user_id = ? AND me.qr_group_id = ?
             ORDER BY mii.id ASC'
        );

        foreach ($milestones as $loadedMilestone) {
            $imageStmt->execute([(int) $loadedMilestone['id'], (int) $user['id'], (int) $qrGroup['id']]);
            $milestoneImages[(int) $loadedMilestone['id']] = $imageStmt->fetchAll();
        }
    }

    $stmt = $pdo->prepare(
        'SELECT mm.*
         FROM memorial_messages mm
         INNER JOIN memorials me ON me.id = mm.memorial_id
         WHERE mm.memorial_id = ? AND me.user_id = ? AND me.qr_group_id = ?
         ORDER BY mm.status ASC, mm.created_at DESC'
    );
    $stmt->execute([(int) $memorial['id'], (int) $user['id'], (int) $qrGroup['id']]);
    $memorialMessages = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT cp.*
         FROM memorial_community_photos cp
         INNER JOIN memorials me ON me.id = cp.memorial_id
         WHERE cp.memorial_id = ? AND me.user_id = ? AND me.qr_group_id = ?
         ORDER BY cp.created_at DESC, cp.id DESC'
    );
    $stmt->execute([(int) $memorial['id'], (int) $user['id'], (int) $qrGroup['id']]);
    $communityPhotos = $stmt->fetchAll();
}

$flash = get_flash();
$paidMemorials = array_values(array_filter(
    $memorials,
    static fn (array $item): bool => (($item['payment_status'] ?? 'pending') === 'paid')
));
$hasLiveMemorials = count($paidMemorials) > 0;
$isCurrentMemorialPaid = $memorial && (($memorial['payment_status'] ?? 'pending') === 'paid');
$currentMemorialExpiry = memorial_expiration_label($memorial);
$currentMemorialCountdown = memorial_expiration_countdown($memorial);
$publicUrl = rtrim(app_base_url(), '/') . '/memorial.php?t=' . urlencode($qrGroup['public_token']);
$previewUrl = $publicUrl . '&preview=1';
$qrUrl = $hasLiveMemorials ? 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($publicUrl) : '';
$regularAdditionalPrice = additional_memorial_price_for_type('regular');
$premiumAdditionalPrice = additional_memorial_price_for_type('premium');
$additionalCost = 0;
foreach (array_slice($memorials, 1) as $additionalMemorial) {
    $additionalCost += additional_memorial_price_for_type(memorial_plan_type($additionalMemorial, $qrGroup));
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | AlaalaMo</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=<?= urlencode((defined('ASSET_VERSION') ? ASSET_VERSION . '-' : '') . (string) (file_exists(__DIR__ . '/styles.css') ? filemtime(__DIR__ . '/styles.css') : time())) ?>">
  </head>
  <body class="dashboard-page">
    <header class="dashboard-header">
      <a class="brand auth-brand" href="/">
        <span class="brand-mark" aria-hidden="true"><img class="brand-mark-image" src="assets/alaalamo-logo-mark.png?v=<?= urlencode((string) (file_exists(__DIR__ . '/assets/alaalamo-logo-mark.png') ? filemtime(__DIR__ . '/assets/alaalamo-logo-mark.png') : time())) ?>" alt=""></span>
        <span class="brand-highlight">AlaalaMo</span>
      </a>
      <a class="auth-link" href="/logout.php">Logout</a>
    </header>

    <main class="dashboard-layout">
      <section class="dashboard-panel">
        <p class="section-eyebrow">Memorial dashboard</p>
        <h1>Welcome, <?= htmlspecialchars($user['given_name'], ENT_QUOTES, 'UTF-8') ?>.</h1>
        <p>
          Fill up the memorial profile first, then add up to <?= $planLimits['milestones'] ?> life milestones.
          Each milestone can include up to <?= $planLimits['milestone_images'] ?> images. When saved, AlaalaMo
          generates one private QR that can hold up to <?= MAX_MEMORIALS_PER_QR ?> memorial pages.
        </p>
        <p class="field-note">
          Current plan: <?= htmlspecialchars(ucfirst($planType), ENT_QUOTES, 'UTF-8') ?>.
          Gallery limit: <?= $planLimits['gallery_images'] ?> images.
          Milestone text limit: <?= $planLimits['milestone_characters'] ?> characters.
        </p>

        <?php if ($flash): ?>
          <p class="auth-alert auth-alert-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?>
          </p>
        <?php endif; ?>

        <div class="dashboard-guide">
          <h2>How to fill this up</h2>
          <ol>
            <li>Start with the correct full name and important dates.</li>
            <li>Add a short description that family members will recognize.</li>
            <li>Upload clear photos connected to the memorial profile.</li>
            <li>Add milestones in life order, such as childhood, family, work, achievements, and legacy.</li>
            <li>Save, scan the QR, and review the family card list or individual memorial on a mobile device.</li>
          </ol>
        </div>
      </section>

      <aside class="qr-panel">
        <h2>Family QR preview</h2>
        <?php if ($hasLiveMemorials && $qrUrl): ?>
          <a class="qr-preview-link" href="<?= htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8') ?>" target="alaalamo_preview" rel="noopener">
            <img src="<?= htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Memorial QR code">
          </a>
          <div class="qr-panel-actions">
            <a class="button-primary" href="<?= htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8') ?>" target="alaalamo_preview" rel="noopener">Open Live Memorial</a>
            <a class="button-info" href="<?= htmlspecialchars($previewUrl, ENT_QUOTES, 'UTF-8') ?>" target="alaalamo_preview" rel="noopener">Open Private Preview</a>
          </div>
          <p><?= count($paidMemorials) ?> paid of <?= count($memorials) ?> prepared memorials in this QR. Additional memorials are PHP <?= number_format($regularAdditionalPrice) ?> for Standard or PHP <?= number_format($premiumAdditionalPrice) ?> for Premium.</p>
          <?php if ($additionalCost > 0): ?>
            <p>Additional memorial total: PHP <?= number_format($additionalCost) ?> per year.</p>
          <?php endif; ?>
          <?php if ($memorial && $isCurrentMemorialPaid && $currentMemorialExpiry): ?>
            <div class="subscription-expiry <?= $currentMemorialCountdown && str_starts_with($currentMemorialCountdown, 'Expired') ? 'is-expired' : '' ?>">
              <strong>Subscription expires on <?= htmlspecialchars($currentMemorialExpiry, ENT_QUOTES, 'UTF-8') ?></strong>
              <?php if ($currentMemorialCountdown): ?>
                <span><?= htmlspecialchars($currentMemorialCountdown, ENT_QUOTES, 'UTF-8') ?></span>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <?php if ($memorial && !$isCurrentMemorialPaid): ?>
            <a class="button-success billing-link" href="/billing.php?memorial_id=<?= (int) $memorial['id'] ?>" data-billing-link>Activate this memorial</a>
          <?php endif; ?>
        <?php else: ?>
          <p>Your private preview is ready while payment is pending. The public QR code will be generated after activation.</p>
          <div class="qr-panel-actions">
            <a class="button-info" href="<?= htmlspecialchars($previewUrl, ENT_QUOTES, 'UTF-8') ?>" target="alaalamo_preview" rel="noopener">Open Private Preview</a>
          </div>
          <?php if ($memorial && $isCurrentMemorialPaid && $currentMemorialExpiry): ?>
            <div class="subscription-expiry <?= $currentMemorialCountdown && str_starts_with($currentMemorialCountdown, 'Expired') ? 'is-expired' : '' ?>">
              <strong>Subscription expires on <?= htmlspecialchars($currentMemorialExpiry, ENT_QUOTES, 'UTF-8') ?></strong>
              <?php if ($currentMemorialCountdown): ?>
                <span><?= htmlspecialchars($currentMemorialCountdown, ENT_QUOTES, 'UTF-8') ?></span>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <?php if ($memorial): ?>
            <a class="button-success billing-link" href="/billing.php?memorial_id=<?= (int) $memorial['id'] ?>" data-billing-link>Complete payment</a>
          <?php endif; ?>
          <p><?= count($memorials) ?> of <?= MAX_MEMORIALS_PER_QR ?> memorials prepared for this QR.</p>
        <?php endif; ?>
      </aside>

      <form class="memorial-form" method="post" action="dashboard.php" enctype="multipart/form-data">
        <section class="form-section">
          <h2>Memorials in this QR</h2>
          <p>
            One QR can include up to <?= MAX_MEMORIALS_PER_QR ?> memorial pages.
            Additional memorials can be Standard at PHP <?= number_format($regularAdditionalPrice) ?> or Premium at PHP <?= number_format($premiumAdditionalPrice) ?> per year.
          </p>
          <?php if ($memorials): ?>
            <div class="dashboard-memorial-list">
              <?php foreach ($memorials as $item): ?>
                <a class="<?= $memorial && (int) $memorial['id'] === (int) $item['id'] ? 'is-active' : '' ?>" href="/dashboard.php?memorial_id=<?= (int) $item['id'] ?>">
                  <?= htmlspecialchars($item['loved_one_name'], ENT_QUOTES, 'UTF-8') ?>
                </a>
              <?php endforeach; ?>
              <?php if (count($memorials) < MAX_MEMORIALS_PER_QR): ?>
                <a href="/dashboard.php?new=1">+ Add memorial</a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </section>

        <section class="form-section">
          <h2>Memorial Profile</h2>
          <input type="hidden" name="memorial_id" value="<?= isset($_GET['new']) ? 0 : (int) ($memorial['id'] ?? 0) ?>">
          <div class="form-grid">
            <label>
              Memorial plan
              <select name="plan_type" <?= $isCurrentMemorialPaid ? 'disabled' : '' ?>>
                <option value="regular" <?= $planType === 'regular' ? 'selected' : '' ?>>Standard - PHP <?= number_format($isCurrentMemorialAdditional ? $regularAdditionalPrice : 599) ?> / year</option>
                <option value="premium" <?= $planType === 'premium' ? 'selected' : '' ?>>Premium - PHP <?= number_format($isCurrentMemorialAdditional ? $premiumAdditionalPrice : 999) ?> / year</option>
              </select>
              <?php if ($isCurrentMemorialPaid): ?>
                <span class="field-note">Paid memorial plans are locked. Create a new memorial if you need a different plan.</span>
              <?php else: ?>
                <span class="field-note">Choose the plan for this memorial before payment activation.</span>
              <?php endif; ?>
            </label>
            <label>
              Loved one's full name
              <input type="text" name="loved_one_name" value="<?= htmlspecialchars($memorial['loved_one_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
            </label>
            <label>
              Resting place
              <input type="text" name="resting_place" value="<?= htmlspecialchars($memorial['resting_place'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </label>
            <div class="form-full location-pin-panel">
              <div>
                <h3>Cemetery map pin</h3>
                <p>Use this while you are at the cemetery so visitors can open the address in phone maps.</p>
              </div>
              <div class="location-pin-grid">
                <label>
                  Latitude
                  <input type="text" name="resting_lat" value="<?= htmlspecialchars((string) ($memorial['resting_lat'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" readonly data-resting-lat>
                </label>
                <label>
                  Longitude
                  <input type="text" name="resting_lng" value="<?= htmlspecialchars((string) ($memorial['resting_lng'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" readonly data-resting-lng>
                </label>
                <button class="button-secondary" type="button" data-get-location>
                  <i class="fa-solid fa-location-crosshairs" aria-hidden="true"></i>
                  Get exact pin
                </button>
              </div>
              <span class="field-note" data-location-status>
                Your phone will ask permission before AlaalaMo saves the exact location.
              </span>
            </div>
            <label>
              Birth date
              <input type="date" name="birth_date" value="<?= htmlspecialchars($memorial['birth_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </label>
            <label>
              Death date
              <input type="date" name="death_date" value="<?= htmlspecialchars($memorial['death_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </label>
            <label class="form-full">
              Quote or dedication from loved ones
              <textarea name="memorial_quote" rows="3" placeholder="Example: Your love remains our light, and your memory walks with us every day."><?= htmlspecialchars($memorial['memorial_quote'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            </label>
            <label class="form-full">
              Favorite song URL
              <input type="url" name="favorite_song_url" value="<?= htmlspecialchars($memorial['favorite_song_url'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Paste Spotify, YouTube, or Apple Music link">
              <span class="field-note">This appears as a Play Favorite Song button on the memorial hero.</span>
            </label>
            <label class="form-full">
              Short description
              <textarea name="short_description" rows="4" placeholder="A gentle introduction to who they were."><?= htmlspecialchars($memorial['short_description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            </label>
            <div class="upload-action-row form-full">
              <label>
                Profile photos
                <input type="file" name="profile_images[]" data-profile-images accept="image/jpeg,image/png,image/webp" multiple <?= $memorial ? '' : 'disabled' ?>>
                <span class="field-note" data-profile-note>
                  <?= $memorial ? count($profileImages) . ' of ' . $planLimits['profile_images'] . ' profile photos used. Upload JPG, PNG, or WebP photos under 3MB.' : 'Save the memorial details first, then upload profile photos.' ?>
                </span>
              </label>
              <button class="button-secondary upload-inline-button" type="button" data-upload-profile <?= $memorial ? '' : 'disabled' ?>>Upload Profile Photos</button>
            </div>
            <div class="image-preview-list form-full" data-profile-preview>
              <?php if ($profileImages): ?>
                <?php foreach ($profileImages as $image): ?>
                  <div class="image-preview-item">
                    <img src="<?= htmlspecialchars($image['image_path'], ENT_QUOTES, 'UTF-8') ?>" alt="Profile image preview">
                    <button class="image-delete-link" type="button" data-image-delete="profile:<?= (int) $image['id'] ?>">Delete</button>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <p>No profile photos yet.</p>
              <?php endif; ?>
            </div>
            <span class="milestone-ajax-status form-full" data-profile-status></span>
            <div class="theme-picker form-full">
              <div>
                <h3>Memorial color theme</h3>
                <p>Choose the colors used on the QR memorial page.</p>
              </div>
              <label>
                Primary
                <input type="color" name="theme_primary" value="<?= htmlspecialchars($memorial['theme_primary'] ?? '#214c63', ENT_QUOTES, 'UTF-8') ?>">
                <span class="field-note">Main background and headings</span>
              </label>
              <label>
                Secondary
                <input type="color" name="theme_secondary" value="<?= htmlspecialchars($memorial['theme_secondary'] ?? '#eadcc8', ENT_QUOTES, 'UTF-8') ?>">
                <span class="field-note">Buttons and quote line</span>
              </label>
              <label>
                Tertiary
                <input type="color" name="theme_tertiary" value="<?= htmlspecialchars($memorial['theme_tertiary'] ?? '#fbfaf7', ENT_QUOTES, 'UTF-8') ?>">
                <span class="field-note">Page background</span>
              </label>
            </div>
            <button class="button-success form-submit" type="submit">Save Memorial Details</button>
          </div>
        </section>

        <section class="form-section">
          <h2>Life Milestones</h2>
          <p>
            Add up to <?= $planLimits['milestones'] ?> milestones. Each one can have up to
            <?= $planLimits['milestone_images'] ?> images and <?= $planLimits['milestone_characters'] ?> characters.
          </p>

          <?php for ($i = 0; $i < $planLimits['milestones']; $i++): ?>
            <?php $milestone = $milestones[$i] ?? null; ?>
            <?php $imagesForMilestone = $milestone ? ($milestoneImages[(int) $milestone['id']] ?? []) : []; ?>
            <div class="milestone-box" data-milestone-box>
              <h3>Milestone <?= $i + 1 ?></h3>
              <div class="form-grid">
                <input type="hidden" name="milestone_id[]" data-milestone-id value="<?= (int) ($milestone['id'] ?? 0) ?>">
                <input type="hidden" data-sort-order value="<?= $i ?>">
                <label>
                  Title
                  <input type="text" name="milestone_title[]" data-milestone-title value="<?= htmlspecialchars($milestone['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Example: Built a family, Started a business">
                </label>
                <label>
                  Date or period
                  <input type="text" name="milestone_date[]" data-milestone-date value="<?= htmlspecialchars($milestone['milestone_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Example: 1975, Childhood, 1990s">
                </label>
                <label class="form-full">
                  Description
                  <textarea name="milestone_description[]" data-milestone-description rows="3" maxlength="<?= $planLimits['milestone_characters'] ?>" placeholder="What happened in this chapter of their life?"><?= htmlspecialchars($milestone['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                  <span class="field-note">Maximum <?= $planLimits['milestone_characters'] ?> characters.</span>
                </label>
                <?php if ($planLimits['life_story']): ?>
                  <div class="milestone-ai-panel form-full">
                    <div>
                      <strong>Enhanced biography</strong>
                      <span data-ai-saved-text>
                        <?= !empty($milestone['ai_narration_text']) ? htmlspecialchars($milestone['ai_narration_text'], ENT_QUOTES, 'UTF-8') : 'No enhanced text saved yet.' ?>
                      </span>
                    </div>
                    <button class="button-primary milestone-ai-button" type="button" data-generate-milestone-ai <?= ($milestone && clean_input((string) ($milestone['description'] ?? '')) !== '') ? '' : 'disabled' ?>>
                      Generate Enhanced Biography
                    </button>
                  </div>
                <?php endif; ?>
                <div class="upload-action-row form-full">
                  <label>
                    Milestone images
                    <?php if ($milestone): ?>
                      <input type="file" name="milestone_images[]" data-milestone-images accept="image/jpeg,image/png,image/webp" multiple>
                      <span class="field-note">Maximum <?= $planLimits['milestone_images'] ?> images for this milestone. Upload JPG, PNG, or WebP photos under 3MB.</span>
                    <?php else: ?>
                      <input type="file" name="milestone_images[]" data-milestone-images accept="image/jpeg,image/png,image/webp" multiple disabled>
                      <span class="field-note">Save this milestone first, then upload images for it.</span>
                    <?php endif; ?>
                  </label>
                  <button class="button-secondary milestone-upload-button upload-inline-button" type="button" data-upload-milestone <?= $milestone ? '' : 'disabled' ?>>Upload Images for This Milestone</button>
                </div>
                <div class="image-preview-list form-full" data-milestone-preview>
                  <?php if ($imagesForMilestone): ?>
                    <?php foreach ($imagesForMilestone as $image): ?>
                      <div class="image-preview-item">
                        <img src="<?= htmlspecialchars($image['image_path'], ENT_QUOTES, 'UTF-8') ?>" alt="Milestone image preview">
                        <button class="image-delete-link" type="button" data-image-delete="milestone:<?= (int) $image['id'] ?>">Delete</button>
                      </div>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <p>No milestone images yet.</p>
                  <?php endif; ?>
                </div>
                <div class="milestone-ajax-actions form-full">
                  <button class="button-secondary milestone-save-button" type="button" data-save-milestone>Save This Milestone</button>
                  <button class="image-delete-link milestone-delete-button" type="button" data-delete-milestone <?= $milestone ? '' : 'disabled' ?>>Delete This Milestone</button>
                  <span class="milestone-ajax-status" data-milestone-status></span>
                </div>
              </div>
            </div>
          <?php endfor; ?>
        </section>

        <section class="form-section">
          <h2>Gallery</h2>
          <p>
            Upload stacked photos for the memorial gallery.
            <?= $planType === 'premium' ? 'Premium supports up to 20 gallery images.' : 'Regular supports up to 6 gallery images.' ?>
          </p>
          <div class="form-grid">
            <label class="form-full">
              Gallery images
              <input type="file" name="gallery_images[]" accept="image/jpeg,image/png,image/webp" multiple>
              <span class="field-note"><?= count($galleryImages) ?> of <?= $planLimits['gallery_images'] ?> gallery images used. Upload JPG, PNG, or WebP photos under 3MB.</span>
            </label>
            <div class="image-preview-list form-full">
              <?php if ($galleryImages): ?>
                <?php foreach ($galleryImages as $image): ?>
                  <div class="image-preview-item">
                    <img src="<?= htmlspecialchars($image['image_path'], ENT_QUOTES, 'UTF-8') ?>" alt="Gallery image preview">
                    <button class="image-delete-link" type="button" data-image-delete="gallery:<?= (int) $image['id'] ?>">Delete</button>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <p>No gallery images yet.</p>
              <?php endif; ?>
            </div>
          </div>
        </section>
      </form>

      <?php if ($memorial): ?>
        <section class="memorial-form" id="community-photos-admin">
          <div class="form-section">
            <h2>Shared Photos from Visitors</h2>
            <p>Approve photos shared by family and friends before they appear on the public memorial page. Approved photos are moved to Cloudinary.</p>
            <?php if (!cloudinary_is_configured()): ?>
              <p class="auth-alert auth-alert-error">Cloudinary is not configured yet in config.php. Approval of shared photos will fail until credentials are added.</p>
            <?php endif; ?>
            <div class="admin-message-list">
              <?php if ($communityPhotos): ?>
                <?php foreach ($communityPhotos as $photo): ?>
                  <?php $photoSrc = $photo['image_url'] ?: $photo['temp_image_path']; ?>
                  <article class="admin-message-card">
                    <div>
                      <span class="message-status message-status-<?= htmlspecialchars($photo['status'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars(ucfirst($photo['status']), ENT_QUOTES, 'UTF-8') ?>
                      </span>
                      <h3><?= htmlspecialchars($photo['sender_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                      <small><?= htmlspecialchars($photo['sender_email'], ENT_QUOTES, 'UTF-8') ?></small>
                      <?php if (!empty($photo['caption'])): ?>
                        <p><?= htmlspecialchars($photo['caption'], ENT_QUOTES, 'UTF-8') ?></p>
                      <?php endif; ?>
                      <?php if ($photoSrc): ?>
                        <div class="image-preview-item community-photo-preview">
                          <img src="<?= htmlspecialchars($photoSrc, ENT_QUOTES, 'UTF-8') ?>" alt="Shared photo preview">
                        </div>
                      <?php endif; ?>
                    </div>
                    <div class="admin-message-actions">
                      <?php if ($photo['status'] !== 'approved'): ?>
                        <form method="post" action="dashboard.php">
                          <input type="hidden" name="form_action" value="approve_community_photo">
                          <input type="hidden" name="memorial_id" value="<?= (int) $memorial['id'] ?>">
                          <input type="hidden" name="photo_id" value="<?= (int) $photo['id'] ?>">
                          <button class="button-primary" type="submit">Approve</button>
                        </form>
                      <?php endif; ?>
                      <form method="post" action="dashboard.php">
                        <input type="hidden" name="form_action" value="delete_community_photo">
                        <input type="hidden" name="memorial_id" value="<?= (int) $memorial['id'] ?>">
                        <input type="hidden" name="photo_id" value="<?= (int) $photo['id'] ?>">
                        <button class="image-delete-link" type="submit">Delete</button>
                      </form>
                    </div>
                  </article>
                <?php endforeach; ?>
              <?php else: ?>
                <p class="field-note">No visitor photos submitted yet.</p>
              <?php endif; ?>
            </div>
          </div>
        </section>

        <section class="memorial-form" id="messages-admin">
          <div class="form-section">
            <h2>Messages of Love</h2>
            <p>Approve messages before they appear on the public memorial page.</p>
            <div class="admin-message-list">
              <?php if ($memorialMessages): ?>
                <?php foreach ($memorialMessages as $loveMessage): ?>
                  <article class="admin-message-card">
                    <div>
                      <span class="message-status message-status-<?= htmlspecialchars($loveMessage['status'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars(ucfirst($loveMessage['status']), ENT_QUOTES, 'UTF-8') ?>
                      </span>
                      <h3><?= htmlspecialchars($loveMessage['sender_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                      <p><?= nl2br(htmlspecialchars($loveMessage['message'], ENT_QUOTES, 'UTF-8')) ?></p>
                      <small><?= htmlspecialchars($loveMessage['sender_email'], ENT_QUOTES, 'UTF-8') ?></small>
                    </div>
                    <div class="admin-message-actions">
                      <?php if ($loveMessage['status'] !== 'approved'): ?>
                        <form method="post" action="dashboard.php">
                          <input type="hidden" name="form_action" value="approve_message">
                          <input type="hidden" name="memorial_id" value="<?= (int) $memorial['id'] ?>">
                          <input type="hidden" name="message_id" value="<?= (int) $loveMessage['id'] ?>">
                          <button class="button-primary" type="submit">Approve</button>
                        </form>
                      <?php endif; ?>
                      <form method="post" action="dashboard.php">
                        <input type="hidden" name="form_action" value="delete_message">
                        <input type="hidden" name="memorial_id" value="<?= (int) $memorial['id'] ?>">
                        <input type="hidden" name="message_id" value="<?= (int) $loveMessage['id'] ?>">
                        <button class="image-delete-link" type="submit">Delete</button>
                      </form>
                    </div>
                  </article>
                <?php endforeach; ?>
              <?php else: ?>
                <p class="field-note">No messages submitted yet.</p>
              <?php endif; ?>
            </div>
          </div>
        </section>
      <?php endif; ?>

      <div class="ai-result-modal" data-ai-modal aria-hidden="true">
        <div class="ai-result-backdrop" data-ai-modal-close></div>
        <div class="ai-result-panel" role="dialog" aria-modal="true" aria-labelledby="aiResultTitle">
          <button class="ai-result-close" type="button" data-ai-modal-close aria-label="Close">&times;</button>
          <h2 id="aiResultTitle">Enhanced Biography</h2>
          <p class="field-note">Review the generated text. You can edit it before saving it to this milestone.</p>
          <textarea data-ai-generated-text rows="10"></textarea>
          <div class="ai-result-actions">
            <button class="button-secondary" type="button" data-ai-modal-close>Cancel</button>
            <button class="button-primary" type="button" data-save-milestone-ai>Save to Milestone</button>
          </div>
          <span class="milestone-ajax-status" data-ai-modal-status></span>
        </div>
      </div>
    </main>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script>
      $(function () {
        const memorialId = <?= (int) ($memorial['id'] ?? 0) ?>;
        const profileImageLimit = <?= (int) $planLimits['profile_images'] ?>;

        function setStatus($box, message, type) {
          const $status = $box.find('[data-milestone-status]');
          $status.removeClass('is-success is-error').addClass(type === 'error' ? 'is-error' : 'is-success').text(message);
        }

        function setInlineStatus($status, message, type) {
          $status.removeClass('is-success is-error').addClass(type === 'error' ? 'is-error' : 'is-success').text(message);
        }

        function ajaxErrorMessage(xhr, fallback) {
          if (xhr.responseJSON && xhr.responseJSON.message) {
            return xhr.responseJSON.message;
          }

          if (xhr.status === 413) {
            return 'Upload is too large for the server. Use a JPG photo under 3MB.';
          }

          if (xhr.responseText) {
            const plainText = xhr.responseText.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
            if (plainText) {
              return plainText.substring(0, 180);
            }
          }

          return fallback;
        }

        function validateImageFiles(files, maxFiles, label) {
          const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
          const maxBytes = 3 * 1024 * 1024;
          const imageLabel = label || 'this milestone';

          if (!files.length) {
            return 'Choose at least one image first.';
          }

          if (files.length > maxFiles) {
            return 'You can upload up to ' + maxFiles + ' image' + (maxFiles === 1 ? '' : 's') + ' at a time for ' + imageLabel + '.';
          }

          for (const file of files) {
            if (!allowedTypes.includes(file.type)) {
              return file.name + ' is not supported. Please use JPG, PNG, or WebP.';
            }

            if (file.size > maxBytes) {
              return file.name + ' is larger than 3MB. Please choose a smaller photo.';
            }
          }

          return '';
        }

        function currentPlanType() {
          const value = $('select[name="plan_type"]').val();
          return value === 'premium' ? 'premium' : 'regular';
        }

        function currentProfileImageLimit() {
          return 5;
        }

        function currentMilestoneImageLimit() {
          return currentPlanType() === 'premium' ? 6 : 2;
        }

        function refreshPlanAwareNotes() {
          const milestoneLimit = currentMilestoneImageLimit();

          $('[data-milestone-box]').each(function () {
            const $box = $(this);
            const $input = $box.find('[data-milestone-images]');
            const $note = $input.siblings('.field-note');

            if (!$input.length || !$note.length) {
              return;
            }

            if ($input.is(':disabled')) {
              $note.text('Save this milestone first, then upload images for it.');
              return;
            }

            const currentText = ($note.text() || '').trim();
            if (currentText.indexOf('milestone images used') !== -1) {
              $note.text(currentText.replace(/of\s+\d+\s+milestone images used/i, 'of ' + milestoneLimit + ' milestone images used'));
            } else {
              $note.text('Maximum ' + milestoneLimit + ' images for this milestone. Upload JPG, PNG, or WebP photos under 3MB.');
            }
          });
        }

        function milestoneCanGenerate($box) {
          return $box.find('[data-milestone-id]').val() !== '0' && $box.find('[data-milestone-description]').val().trim() !== '';
        }

        function syncMilestoneEnhancementButton($box) {
          $box.find('[data-generate-milestone-ai]').prop('disabled', !milestoneCanGenerate($box));
        }

        let activeAiMilestoneBox = null;
        const $aiModal = $('[data-ai-modal]');
        const $aiText = $('[data-ai-generated-text]');
        const $aiStatus = $('[data-ai-modal-status]');
        const $aiSaveButton = $('[data-save-milestone-ai]');

        function openEnhancedTextModal($box, text) {
          activeAiMilestoneBox = $box;
          $aiText.val(text || '');
          $aiStatus.removeClass('is-success is-error').text('');
          $aiModal.addClass('is-open').attr('aria-hidden', 'false');
          $aiText.trigger('focus');
        }

        function closeAiModal() {
          $aiModal.removeClass('is-open').attr('aria-hidden', 'true');
          $aiText.val('');
          $aiStatus.removeClass('is-success is-error').text('');
          activeAiMilestoneBox = null;
          $aiSaveButton.prop('disabled', false);
        }

        $('[data-ai-modal-close]').on('click', closeAiModal);

        $(document).on('keydown', function (event) {
          if (event.key === 'Escape' && $aiModal.hasClass('is-open')) {
            closeAiModal();
          }
        });

        $('[data-milestone-box]').each(function () {
          syncMilestoneEnhancementButton($(this));
        });

        $('[data-milestone-description]').on('input', function () {
          syncMilestoneEnhancementButton($(this).closest('[data-milestone-box]'));
        });

        $('[data-get-location]').on('click', function () {
          const $button = $(this);
          const $status = $('[data-location-status]');

          if (!navigator.geolocation) {
            $status.text('This phone or browser does not support exact location capture.');
            return;
          }

          $button.prop('disabled', true);
          $status.text('Checking phone location permission...');

          navigator.geolocation.getCurrentPosition(function (position) {
            $('[data-resting-lat]').val(position.coords.latitude.toFixed(7));
            $('[data-resting-lng]').val(position.coords.longitude.toFixed(7));
            $status.text('Exact pin captured. Click Save Memorial Details to store it.');
            $button.prop('disabled', false);
          }, function (error) {
            const messages = {
              1: 'Location permission was denied. Please allow location access and try again.',
              2: 'Your phone could not detect the exact location right now.',
              3: 'Location detection took too long. Please try again in an open area.'
            };

            $status.text(messages[error.code] || 'Location could not be captured.');
            $button.prop('disabled', false);
          }, {
            enableHighAccuracy: true,
            timeout: 15000,
            maximumAge: 0
          });
        });

        $('[data-save-milestone]').on('click', function () {
          const $button = $(this);
          const $box = $button.closest('[data-milestone-box]');

          $button.prop('disabled', true);
          setStatus($box, 'Saving...', 'success');

          $.ajax({
            url: 'dashboard.php',
            method: 'POST',
            dataType: 'json',
            data: {
              ajax: '1',
              form_action: 'save_milestone',
              memorial_id: memorialId,
              milestone_id: $box.find('[data-milestone-id]').val(),
              sort_order: $box.find('[data-sort-order]').val(),
              title: $box.find('[data-milestone-title]').val(),
              milestone_date: $box.find('[data-milestone-date]').val(),
              description: $box.find('[data-milestone-description]').val()
            }
          }).done(function (response) {
            $box.find('[data-milestone-id]').val(response.milestone_id);
            $box.find('[data-milestone-images], [data-upload-milestone], [data-delete-milestone]').prop('disabled', false);
            syncMilestoneEnhancementButton($box);
            $box.find('[data-milestone-images]').siblings('.field-note').text('Maximum <?= $planLimits['milestone_images'] ?> images for this milestone. Upload JPG, PNG, or WebP photos under 3MB.');
            setStatus($box, response.message || 'Milestone saved.', 'success');
          }).fail(function (xhr) {
            setStatus($box, ajaxErrorMessage(xhr, 'Milestone could not be saved.'), 'error');
          }).always(function () {
            $button.prop('disabled', false);
          });
        });

        $('[data-generate-milestone-ai]').on('click', function () {
          const $button = $(this);
          const $box = $button.closest('[data-milestone-box]');
          const milestoneId = $box.find('[data-milestone-id]').val();

          if (!milestoneId || milestoneId === '0') {
            setStatus($box, 'Save this milestone first.', 'error');
            return;
          }

          if ($box.find('[data-milestone-description]').val().trim() === '') {
            setStatus($box, 'Add and save a milestone description first.', 'error');
            syncMilestoneEnhancementButton($box);
            return;
          }

          $button.prop('disabled', true);
          setStatus($box, 'Generating enhanced biography...', 'success');

          $.ajax({
            url: 'dashboard.php',
            method: 'POST',
            dataType: 'json',
            data: {
              ajax: '1',
              form_action: 'generate_milestone_ai',
              memorial_id: memorialId,
              milestone_id: milestoneId,
              title: $box.find('[data-milestone-title]').val(),
              milestone_date: $box.find('[data-milestone-date]').val(),
              description: $box.find('[data-milestone-description]').val()
            }
          }).done(function (response) {
            setStatus($box, response.message || 'Enhanced biography generated.', 'success');
            openEnhancedTextModal($box, response.text || '');
          }).fail(function (xhr) {
            setStatus($box, ajaxErrorMessage(xhr, 'Enhanced biography could not be generated.'), 'error');
          }).always(function () {
            $button.prop('disabled', false);
          });
        });

        $aiSaveButton.on('click', function () {
          if (!activeAiMilestoneBox) {
            return;
          }

          const $box = activeAiMilestoneBox;
          const milestoneId = $box.find('[data-milestone-id]').val();
          const text = $aiText.val().trim();

          if (!text) {
            $aiStatus.removeClass('is-success').addClass('is-error').text('Generated text cannot be empty.');
            return;
          }

          $aiSaveButton.prop('disabled', true);
          $aiStatus.removeClass('is-error').addClass('is-success').text('Saving...');

          $.ajax({
            url: 'dashboard.php',
            method: 'POST',
            dataType: 'json',
            data: {
              ajax: '1',
              form_action: 'save_milestone_ai',
              memorial_id: memorialId,
              milestone_id: milestoneId,
              generated_text: text
            }
          }).done(function (response) {
            $box.find('[data-ai-saved-text]').text(response.text || text);
            setStatus($box, response.message || 'Enhanced biography saved.', 'success');
            closeAiModal();
          }).fail(function (xhr) {
            $aiStatus.removeClass('is-success').addClass('is-error').text(ajaxErrorMessage(xhr, 'Enhanced biography could not be saved.'));
            $aiSaveButton.prop('disabled', false);
          });
        });

        $('[data-upload-profile]').on('click', function () {
          const $button = $(this);
          const input = $('[data-profile-images]')[0];
          const files = Array.from(input.files || []);
          const $preview = $('[data-profile-preview]');
          const $status = $('[data-profile-status]');
          const $note = $('[data-profile-note]');
          const profileImageLimit = currentProfileImageLimit();

          if (!memorialId) {
            setInlineStatus($status, 'Save the memorial details first before uploading profile photos.', 'error');
            return;
          }

          const validationMessage = validateImageFiles(files, profileImageLimit, 'profile photos');

          if (validationMessage) {
            setInlineStatus($status, validationMessage, 'error');
            return;
          }

          $button.prop('disabled', true);
          setInlineStatus($status, 'Uploading profile photos...', 'success');

          const formData = new FormData();
          formData.append('ajax', '1');
          formData.append('form_action', 'upload_profile_images');
          formData.append('memorial_id', memorialId);
          formData.append('plan_type', currentPlanType());

          files.forEach(function (file) {
            formData.append('profile_images[]', file);
          });

          $.ajax({
            url: 'dashboard.php',
            method: 'POST',
            dataType: 'json',
            data: formData,
            processData: false,
            contentType: false,
            timeout: 90000
          }).done(function (response) {
            $preview.find('p').remove();
            $preview.append(response.html || '');

            if (response.count !== undefined && response.limit !== undefined) {
              $note.text(response.count + ' of ' + response.limit + ' profile photos used. Upload JPG, PNG, or WebP photos under 3MB.');
            }

            $(input).val('');
            setInlineStatus($status, response.message || 'Profile photos uploaded.', 'success');
          }).fail(function (xhr, textStatus) {
            const timeoutMessage = 'Upload timed out. Try uploading one smaller JPG photo.';
            setInlineStatus($status, textStatus === 'timeout' ? timeoutMessage : ajaxErrorMessage(xhr, 'Profile photos could not be uploaded. Try JPG photos under 3MB.'), 'error');
          }).always(function () {
            $button.prop('disabled', false);
          });
        });

        $('[data-upload-milestone]').on('click', function () {
          const $button = $(this);
          const $box = $button.closest('[data-milestone-box]');
          const milestoneId = $box.find('[data-milestone-id]').val();
          const input = $box.find('[data-milestone-images]')[0];
          const files = Array.from(input.files || []);
          const $note = $box.find('[data-milestone-images]').siblings('.field-note');
          const milestoneImageLimit = currentMilestoneImageLimit();

          if (!milestoneId || milestoneId === '0') {
            setStatus($box, 'Save this milestone first.', 'error');
            return;
          }

          const validationMessage = validateImageFiles(files, milestoneImageLimit);

          if (validationMessage) {
            setStatus($box, validationMessage, 'error');
            return;
          }

          $button.prop('disabled', true);
          setStatus($box, 'Uploading ' + files.length + ' image' + (files.length === 1 ? '' : 's') + '...', 'success');

          const formData = new FormData();
          formData.append('ajax', '1');
          formData.append('form_action', 'upload_milestone_images');
          formData.append('memorial_id', memorialId);
          formData.append('milestone_id', milestoneId);
          formData.append('plan_type', currentPlanType());

          files.forEach(function (file) {
            formData.append('milestone_images[]', file);
          });

          $.ajax({
            url: 'dashboard.php',
            method: 'POST',
            dataType: 'json',
            data: formData,
            processData: false,
            contentType: false,
            timeout: 90000
          }).done(function (response) {
            const $preview = $box.find('[data-milestone-preview]');
            $preview.find('p').remove();
            $preview.append(response.html || '');

            if (response.count !== undefined && response.limit !== undefined) {
              $note.text(response.count + ' of ' + response.limit + ' milestone images used. Upload JPG, PNG, or WebP photos under 3MB.');
            }

            $(input).val('');
            setStatus($box, response.message || 'Milestone images uploaded.', 'success');
          }).fail(function (xhr, textStatus) {
            const timeoutMessage = 'Upload timed out. Try uploading smaller JPG photos.';
            setStatus($box, textStatus === 'timeout' ? timeoutMessage : ajaxErrorMessage(xhr, 'Milestone images could not be uploaded. Try JPG photos under 3MB.'), 'error');
          }).always(function () {
            $button.prop('disabled', false);
          });
        });

        $('[data-delete-milestone]').on('click', function () {
          const $button = $(this);
          const $box = $button.closest('[data-milestone-box]');
          const milestoneId = $box.find('[data-milestone-id]').val();

          if (!milestoneId || milestoneId === '0') {
            setStatus($box, 'This milestone is not saved yet.', 'error');
            return;
          }

          if (!confirm('Delete this milestone and its images?')) {
            return;
          }

          $button.prop('disabled', true);
          setStatus($box, 'Deleting...', 'success');

          $.ajax({
            url: 'dashboard.php',
            method: 'POST',
            dataType: 'json',
            data: {
              ajax: '1',
              form_action: 'delete_milestone',
              memorial_id: memorialId,
              milestone_id: milestoneId
            }
          }).done(function (response) {
            $box.find('[data-milestone-id]').val('0');
            $box.find('[data-milestone-title], [data-milestone-date], [data-milestone-description]').val('');
            $box.find('[data-milestone-images]').val('').prop('disabled', true);
            $box.find('[data-upload-milestone], [data-delete-milestone], [data-generate-milestone-ai]').prop('disabled', true);
            $box.find('[data-ai-saved-text]').text('No enhanced text saved yet.');
            $box.find('[data-milestone-preview]').html('<p>No milestone images yet.</p>');
            $box.find('.field-note').text('Save this milestone first, then upload images for it.');
            setStatus($box, response.message || 'Milestone deleted.', 'success');
          }).fail(function (xhr) {
            $button.prop('disabled', false);
            setStatus($box, ajaxErrorMessage(xhr, 'Milestone could not be deleted.'), 'error');
          });
        });

        $(document).on('click', '[data-image-delete]', function () {
          const $button = $(this);
          const $item = $button.closest('.image-preview-item');
          const $box = $button.closest('[data-milestone-box]');

          $button.prop('disabled', true).text('Deleting...');

          $.ajax({
            url: 'dashboard.php',
            method: 'POST',
            dataType: 'json',
            data: {
              ajax: '1',
              form_action: 'delete_image',
              memorial_id: memorialId,
              image_delete: $button.data('image-delete')
            }
          }).done(function (response) {
            $item.remove();

            if ($box.length) {
              const $preview = $box.find('[data-milestone-preview]');

              if (!$preview.find('.image-preview-item').length) {
                $preview.html('<p>No milestone images yet.</p>');
              }

              setStatus($box, response.message || 'Image deleted.', 'success');
            }
          }).fail(function (xhr) {
            $button.prop('disabled', false).text('Delete');

            if ($box.length) {
              setStatus($box, ajaxErrorMessage(xhr, 'Image could not be deleted.'), 'error');
            } else {
              alert(ajaxErrorMessage(xhr, 'Image could not be deleted.'));
            }
          });
        });

        $('[data-billing-link]').on('click', function () {
          const planType = $('select[name="plan_type"]').val();
          const href = $(this).attr('href') || '';

          if (!href || !planType) {
            return;
          }

          const separator = href.indexOf('?') === -1 ? '?' : '&';
          const cleanHref = href.replace(/([?&])requested_plan=[^&]*/g, '').replace(/[?&]$/, '');
          $(this).attr('href', cleanHref + separator + 'requested_plan=' + encodeURIComponent(planType));
        });

        $('select[name="plan_type"]').on('change', function () {
          refreshPlanAwareNotes();
        });

        refreshPlanAwareNotes();
      });
    </script>
  </body>
</html>

