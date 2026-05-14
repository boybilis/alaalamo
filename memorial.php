<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

start_app_session();

$token = clean_input($_GET['t'] ?? $_POST['token'] ?? '');

if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404);
    exit('Memorial not found.');
}

function memorial_theme_color(array $memorial, string $key, string $fallback): string
{
    $color = (string) ($memorial[$key] ?? '');

    return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : $fallback;
}

function readable_text_color(string $hexColor): string
{
    $hex = ltrim($hexColor, '#');
    $red = hexdec(substr($hex, 0, 2));
    $green = hexdec(substr($hex, 2, 2));
    $blue = hexdec(substr($hex, 4, 2));
    $brightness = (($red * 299) + ($green * 587) + ($blue * 114)) / 1000;

    return $brightness >= 150 ? '#1f2933' : '#ffffff';
}

function memorial_theme_style(array $memorial): string
{
    $primary = memorial_theme_color($memorial, 'theme_primary', '#214c63');
    $secondary = memorial_theme_color($memorial, 'theme_secondary', '#eadcc8');
    $tertiary = memorial_theme_color($memorial, 'theme_tertiary', '#fbfaf7');
    $style = [
        '--memorial-primary: ' . $primary,
        '--memorial-secondary: ' . $secondary,
        '--memorial-tertiary: ' . $tertiary,
        '--memorial-primary-text: ' . readable_text_color($primary),
        '--memorial-secondary-text: ' . readable_text_color($secondary),
        '--memorial-tertiary-text: ' . readable_text_color($tertiary),
    ];

    return htmlspecialchars(implode('; ', $style) . ';', ENT_QUOTES, 'UTF-8');
}

function memorial_display_date(?string $date): string
{
    if (!$date) {
        return '';
    }

    $timestamp = strtotime($date);

    return $timestamp ? date('F d, Y', $timestamp) : '';
}

function memorial_date_range(array $memorial): string
{
    $birthDate = memorial_display_date($memorial['birth_date'] ?? null);
    $deathDate = memorial_display_date($memorial['death_date'] ?? null);

    if ($birthDate !== '' && $deathDate !== '') {
        return $birthDate . ' - ' . $deathDate;
    }

    return $birthDate !== '' ? $birthDate : $deathDate;
}

function memorial_coordinate_url(array $memorial): ?string
{
    $lat = $memorial['resting_lat'] ?? null;
    $lng = $memorial['resting_lng'] ?? null;

    if ($lat === null || $lng === null || !is_numeric((string) $lat) || !is_numeric((string) $lng)) {
        return null;
    }

    $latitude = (float) $lat;
    $longitude = (float) $lng;

    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        return null;
    }

    return 'https://www.google.com/maps/dir/?api=1&destination='
        . rawurlencode(number_format($latitude, 7, '.', '') . ',' . number_format($longitude, 7, '.', ''));
}

function spotify_embed_url(string $url): ?string
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }

    $host = strtolower((string) parse_url($url, PHP_URL_HOST));

    if (!str_ends_with($host, 'spotify.com')) {
        return null;
    }

    $pathParts = array_values(array_filter(explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/'))));
    $allowedTypes = ['track', 'album', 'playlist', 'episode', 'show'];

    if (count($pathParts) < 2 || !in_array($pathParts[0], $allowedTypes, true)) {
        return null;
    }

    return 'https://open.spotify.com/embed/' . rawurlencode($pathParts[0]) . '/' . rawurlencode($pathParts[1]);
}

function youtube_embed_url(string $url): ?string
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }

    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
    $query = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $videoId = null;
    $playlistId = null;

    if (in_array($host, ['youtu.be', 'www.youtu.be'], true)) {
        $videoId = explode('/', $path)[0] ?? null;
    } elseif (str_ends_with($host, 'youtube.com')) {
        if (($query['v'] ?? '') !== '') {
            $videoId = (string) $query['v'];
        } elseif (str_starts_with($path, 'shorts/')) {
            $videoId = substr($path, strlen('shorts/'));
        } elseif (str_starts_with($path, 'embed/')) {
            $videoId = substr($path, strlen('embed/'));
        } elseif (str_starts_with($path, 'live/')) {
            $videoId = substr($path, strlen('live/'));
        }
    }

    $videoId = $videoId ? preg_replace('/[^a-zA-Z0-9_-]/', '', $videoId) : null;
    $playlistId = !empty($query['list']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $query['list']) : null;

    if (!$videoId && !$playlistId) {
        return null;
    }

    $embedUrl = $videoId
        ? 'https://www.youtube.com/embed/' . $videoId
        : 'https://www.youtube.com/embed/videoseries';
    $embedQuery = [
        'autoplay' => '1',
        'rel' => '0',
        'origin' => rtrim(app_base_url(), '/'),
    ];

    if ($playlistId) {
        $embedQuery['list'] = $playlistId;
    }

    return $embedUrl . '?' . http_build_query($embedQuery);
}

function favorite_song_embed(string $url): ?array
{
    $youtubeUrl = youtube_embed_url($url);

    if ($youtubeUrl) {
        return ['type' => 'YouTube', 'url' => $youtubeUrl];
    }

    $spotifyUrl = spotify_embed_url($url);

    return $spotifyUrl ? ['type' => 'Spotify', 'url' => $spotifyUrl] : null;
}

function qr_plan_type(?array $qrGroup): string
{
    return (($qrGroup['plan_type'] ?? 'regular') === 'premium') ? 'premium' : 'regular';
}

function qr_plan_limits(?array $qrGroup): array
{
    $isPremium = qr_plan_type($qrGroup) === 'premium';

    return [
        'gallery_images' => $isPremium ? 20 : 6,
        'milestones' => $isPremium ? 5 : 2,
        'milestone_images' => $isPremium ? 6 : 2,
        'life_story' => $isPremium,
    ];
}

function cloudinary_optimized_image_url(string $imageUrl): string
{
    if ($imageUrl === '' || !str_contains($imageUrl, 'res.cloudinary.com') || !str_contains($imageUrl, '/upload/')) {
        return $imageUrl;
    }

    $transformation = 'f_auto,q_auto,c_limit,w_1600';

    if (str_contains($imageUrl, '/upload/' . $transformation . '/')) {
        return $imageUrl;
    }

    return str_replace('/upload/', '/upload/' . $transformation . '/', $imageUrl);
}

function send_memorial_message_otp(string $email, string $otp, string $lovedOneName): bool
{
    $autoloadPath = __DIR__ . '/vendor/autoload.php';

    if (!is_file($autoloadPath)) {
        error_log('PHPMailer is not installed for memorial message OTP.');
        return false;
    }

    require_once $autoloadPath;

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $subject = 'Your AlaalaMo message OTP';
    $safeName = htmlspecialchars($lovedOneName, ENT_QUOTES, 'UTF-8');
    $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');

    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_ENCRYPTION === 'tls'
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($email);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = '
            <div style="font-family: Arial, sans-serif; max-width: 560px; margin: 0 auto; color: #1f2933;">
                <h1 style="color:#214c63;">AlaalaMo message verification</h1>
                <p style="font-size:16px; line-height:1.6;">Use this OTP to submit your message of love for ' . $safeName . '.</p>
                <p style="font-size:34px; font-weight:800; letter-spacing:6px; color:#214c63; background:#f5eee4; padding:18px 22px; border-radius:8px; text-align:center;">' . $safeOtp . '</p>
                <p style="font-size:14px; color:#5f6975;">This code expires in 1 minute. Your message will appear only after family approval.</p>
            </div>
        ';
        $mail->AltBody = "Your AlaalaMo message OTP is {$otp}. It expires in 1 minute.";

        return $mail->send();
    } catch (Throwable $exception) {
        error_log('Memorial message OTP email failed: ' . $exception->getMessage());
        return false;
    }
}

function send_memorial_photo_otp(string $email, string $otp, string $lovedOneName): bool
{
    $autoloadPath = __DIR__ . '/vendor/autoload.php';

    if (!is_file($autoloadPath)) {
        error_log('PHPMailer is not installed for memorial photo OTP.');
        return false;
    }

    require_once $autoloadPath;

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $safeName = htmlspecialchars($lovedOneName, ENT_QUOTES, 'UTF-8');
    $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');

    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_ENCRYPTION === 'tls'
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($email);
        $mail->Subject = 'Your AlaalaMo photo sharing OTP';
        $mail->isHTML(true);
        $mail->Body = '
            <div style="font-family: Arial, sans-serif; max-width: 560px; margin: 0 auto; color: #1f2933;">
                <h1 style="color:#214c63; margin-bottom: 8px;">Share photos for ' . $safeName . '</h1>
                <p style="font-size:16px; line-height:1.6;">Use this code to submit your photos for family approval.</p>
                <p style="font-size:34px; font-weight:800; letter-spacing:6px; color:#214c63; background:#f5eee4; padding:18px 22px; border-radius:8px; text-align:center;">' . $safeOtp . '</p>
                <p style="font-size:14px; color:#5f6975;">This code expires in 1 minute.</p>
            </div>
        ';
        $mail->AltBody = "Your AlaalaMo photo sharing OTP is {$otp}. It expires in 1 minute.";

        return $mail->send();
    } catch (Throwable $exception) {
        error_log('Photo OTP email failed: ' . $exception->getMessage());
        return false;
    }
}

function uploaded_file_at_index(array $files, int $index): array
{
    return [
        'name' => $files['name'][$index] ?? '',
        'type' => $files['type'][$index] ?? '',
        'tmp_name' => $files['tmp_name'][$index] ?? '',
        'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
        'size' => $files['size'][$index] ?? 0,
    ];
}

function safe_delete_temp_upload(?string $relativePath): void
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

    if (str_starts_with($target, rtrim($uploadsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) && is_file($target)) {
        unlink($target);
    }
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM qr_groups WHERE public_token = ? LIMIT 1');
$stmt->execute([$token]);
$qrGroup = $stmt->fetch();

$memorials = [];
$isGroupView = false;

if ($qrGroup) {
    $stmt = $pdo->prepare('SELECT * FROM memorials WHERE qr_group_id = ? AND status = "published" ORDER BY id ASC');
    $stmt->execute([(int) $qrGroup['id']]);
    $memorials = $stmt->fetchAll();
    $isGroupView = count($memorials) > 1;

    if (!$memorials) {
        http_response_code(404);
        exit('Memorial not found.');
    }

    $memorial = $memorials[0];
} else {
    $stmt = $pdo->prepare('SELECT * FROM memorials WHERE public_token = ? AND status = "published" LIMIT 1');
    $stmt->execute([$token]);
    $memorial = $stmt->fetch();

    if (!$memorial) {
        http_response_code(404);
        exit('Memorial not found.');
    }
}

$selectedId = (int) ($_GET['m'] ?? $_POST['memorial_id'] ?? 0);

if ($selectedId > 0 && $qrGroup) {
    foreach ($memorials as $candidate) {
        if ((int) $candidate['id'] === $selectedId) {
            $memorial = $candidate;
            $isGroupView = false;
            break;
        }
    }
}

$themeStyle = memorial_theme_style($memorial);
$planLimits = qr_plan_limits($qrGroup ?: null);
$restingMapsUrl = memorial_coordinate_url($memorial);
$favoriteSongUrl = trim((string) ($memorial['favorite_song_url'] ?? ''));

if ($favoriteSongUrl !== '' && !filter_var($favoriteSongUrl, FILTER_VALIDATE_URL)) {
    $favoriteSongUrl = '';
}

$favoriteSongEmbed = $favoriteSongUrl !== '' ? favorite_song_embed($favoriteSongUrl) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isGroupView) {
    $formAction = clean_input($_POST['form_action'] ?? '');
    $memorialIdInput = (int) ($_POST['memorial_id'] ?? 0);

    if ($memorialIdInput !== (int) $memorial['id']) {
        flash('error', 'The memorial message could not be submitted.');
        redirect_to('/memorial.php?t=' . urlencode($token));
    }

    if ($formAction === 'request_message_otp') {
        $senderName = clean_input($_POST['sender_name'] ?? '');
        $senderEmail = strtolower(clean_input($_POST['sender_email'] ?? ''));
        $messageText = clean_input($_POST['message'] ?? '');

        if ($senderName === '' || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL) || $messageText === '') {
            flash('error', 'Please enter your name, email, and message.');
            redirect_to('/memorial.php?t=' . urlencode($token) . '&m=' . (int) $memorial['id'] . '#messages');
        }

        $messageText = substr($messageText, 0, 700);
        $otp = generate_otp();
        $expiresAt = (new DateTimeImmutable('+1 minute'))->format('Y-m-d H:i:s');

        $pdo->prepare(
            'UPDATE memorial_message_otps
             SET consumed_at = NOW()
             WHERE memorial_id = ? AND sender_email = ? AND consumed_at IS NULL'
        )->execute([(int) $memorial['id'], $senderEmail]);

        $pdo->prepare(
            'INSERT INTO memorial_message_otps
             (memorial_id, sender_name, sender_email, message, otp_hash, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            (int) $memorial['id'],
            $senderName,
            $senderEmail,
            $messageText,
            password_hash($otp, PASSWORD_DEFAULT),
            $expiresAt,
        ]);

        if (!send_memorial_message_otp($senderEmail, $otp, (string) $memorial['loved_one_name'])) {
            flash('error', 'The OTP email could not be sent. Please try again later.');
            redirect_to('/memorial.php?t=' . urlencode($token) . '&m=' . (int) $memorial['id'] . '#messages');
        }

        flash('success', 'We sent a 1-minute OTP to your email. Enter it to submit your message for approval.');
        redirect_to('/memorial.php?t=' . urlencode($token) . '&m=' . (int) $memorial['id'] . '&verify_message=1&message_email=' . urlencode($senderEmail) . '#messages');
    }

    if ($formAction === 'verify_message_otp') {
        $senderEmail = strtolower(clean_input($_POST['sender_email'] ?? ''));
        $otp = clean_input($_POST['otp'] ?? '');

        $stmt = $pdo->prepare(
            'SELECT *
             FROM memorial_message_otps
             WHERE memorial_id = ? AND sender_email = ? AND consumed_at IS NULL
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([(int) $memorial['id'], $senderEmail]);
        $pendingOtp = $stmt->fetch();

        if (!$pendingOtp || (int) $pendingOtp['attempts'] >= 5 || strtotime((string) $pendingOtp['expires_at']) < time() || !password_verify($otp, (string) $pendingOtp['otp_hash'])) {
            if ($pendingOtp) {
                $pdo->prepare('UPDATE memorial_message_otps SET attempts = attempts + 1 WHERE id = ?')
                    ->execute([(int) $pendingOtp['id']]);
            }

            flash('error', 'Invalid or expired OTP. Please request a new one.');
            redirect_to('/memorial.php?t=' . urlencode($token) . '&m=' . (int) $memorial['id'] . '&verify_message=1&message_email=' . urlencode($senderEmail) . '#messages');
        }

        $pdo->prepare(
            'INSERT INTO memorial_messages (memorial_id, sender_name, sender_email, message)
             VALUES (?, ?, ?, ?)'
        )->execute([
            (int) $memorial['id'],
            $pendingOtp['sender_name'],
            $pendingOtp['sender_email'],
            $pendingOtp['message'],
        ]);

        $pdo->prepare('UPDATE memorial_message_otps SET consumed_at = NOW() WHERE id = ?')
            ->execute([(int) $pendingOtp['id']]);

        flash('success', 'Your message was submitted. It will appear after family approval.');
        redirect_to('/memorial.php?t=' . urlencode($token) . '&m=' . (int) $memorial['id'] . '#messages');
    }

    if ($formAction === 'request_photo_otp') {
        $senderName = clean_input($_POST['sender_name'] ?? '');
        $senderEmail = strtolower(clean_input($_POST['sender_email'] ?? ''));
        $caption = substr(clean_input($_POST['caption'] ?? ''), 0, 255);
        $storedPaths = [];

        if ($senderName === '' || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL) || $caption === '') {
            flash('error', 'Please enter your name, email, and a short photo caption.');
            redirect_to('/memorial.php?t=' . urlencode($token) . '&m=' . (int) $memorial['id'] . '#shared-photos');
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM memorial_community_photos WHERE memorial_id = ? AND sender_email = ?');
        $stmt->execute([(int) $memorial['id'], $senderEmail]);

        if ((int) $stmt->fetchColumn() > 0) {
            flash('error', 'This email has already shared a photo for this memorial.');
            redirect_to('/memorial.php?t=' . urlencode($token) . '&m=' . (int) $memorial['id'] . '#shared-photos');
        }

        if (empty($_FILES['shared_photos']['name']) || !is_array($_FILES['shared_photos']['name'])) {
            flash('error', 'Please choose one photo to share.');
            redirect_to('/memorial.php?t=' . urlencode($token) . '&m=' . (int) $memorial['id'] . '#shared-photos');
        }

        $imageCount = min(count($_FILES['shared_photos']['name']), 1);

        for ($i = 0; $i < $imageCount; $i++) {
            $path = store_uploaded_image(
                uploaded_file_at_index($_FILES['shared_photos'], $i),
                'community-pending/memorial-' . (int) $memorial['id']
            );

            if ($path) {
                $storedPaths[] = $path;
            }
        }

        if (!$storedPaths) {
            flash('error', 'No valid photos were uploaded. Please use JPG, PNG, or WebP images.');
            redirect_to('/memorial.php?t=' . urlencode($token) . '&m=' . (int) $memorial['id'] . '#shared-photos');
        }

        $otp = generate_otp();
        $expiresAt = (new DateTimeImmutable('+1 minute'))->format('Y-m-d H:i:s');

        $pdo->prepare(
            'UPDATE memorial_photo_otps
             SET consumed_at = NOW()
             WHERE memorial_id = ? AND sender_email = ? AND consumed_at IS NULL'
        )->execute([(int) $memorial['id'], $senderEmail]);

        $pdo->prepare(
            'INSERT INTO memorial_photo_otps
             (memorial_id, sender_name, sender_email, caption, temp_image_paths, otp_hash, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (int) $memorial['id'],
            $senderName,
            $senderEmail,
            $caption,
            json_encode($storedPaths, JSON_UNESCAPED_SLASHES),
            password_hash($otp, PASSWORD_DEFAULT),
            $expiresAt,
        ]);

        if (!send_memorial_photo_otp($senderEmail, $otp, (string) $memorial['loved_one_name'])) {
            foreach ($storedPaths as $storedPath) {
                safe_delete_temp_upload($storedPath);
            }

            flash('error', 'The OTP email could not be sent. Please try again later.');
            redirect_to('/memorial.php?t=' . urlencode($token) . '&m=' . (int) $memorial['id'] . '#shared-photos');
        }

        flash('success', 'We sent a 1-minute OTP to your email. Enter it to submit your photos for approval.');
        redirect_to('/memorial.php?t=' . urlencode($token) . '&m=' . (int) $memorial['id'] . '&verify_photo=1&photo_email=' . urlencode($senderEmail) . '#shared-photos');
    }

    if ($formAction === 'verify_photo_otp') {
        $senderEmail = strtolower(clean_input($_POST['sender_email'] ?? ''));
        $otp = clean_input($_POST['otp'] ?? '');
        $stmt = $pdo->prepare(
            'SELECT *
             FROM memorial_photo_otps
             WHERE memorial_id = ? AND sender_email = ? AND consumed_at IS NULL
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([(int) $memorial['id'], $senderEmail]);
        $pendingOtp = $stmt->fetch();

        if (!$pendingOtp || (int) $pendingOtp['attempts'] >= 5 || strtotime((string) $pendingOtp['expires_at']) < time() || !password_verify($otp, (string) $pendingOtp['otp_hash'])) {
            if ($pendingOtp) {
                $pdo->prepare('UPDATE memorial_photo_otps SET attempts = attempts + 1 WHERE id = ?')
                    ->execute([(int) $pendingOtp['id']]);
            }

            flash('error', 'Invalid or expired OTP. Please request a new one.');
            redirect_to('/memorial.php?t=' . urlencode($token) . '&m=' . (int) $memorial['id'] . '&verify_photo=1&photo_email=' . urlencode($senderEmail) . '#shared-photos');
        }

        $paths = json_decode((string) $pendingOtp['temp_image_paths'], true);
        $paths = is_array($paths) ? array_slice($paths, 0, 1) : [];

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM memorial_community_photos WHERE memorial_id = ? AND sender_email = ?');
        $stmt->execute([(int) $memorial['id'], (string) $pendingOtp['sender_email']]);

        if ((int) $stmt->fetchColumn() > 0) {
            foreach ($paths as $path) {
                safe_delete_temp_upload($path);
            }

            $pdo->prepare('UPDATE memorial_photo_otps SET consumed_at = NOW() WHERE id = ?')
                ->execute([(int) $pendingOtp['id']]);

            flash('error', 'This email has already shared a photo for this memorial.');
            redirect_to('/memorial.php?t=' . urlencode($token) . '&m=' . (int) $memorial['id'] . '#shared-photos');
        }

        foreach ($paths as $path) {
            $pdo->prepare(
                'INSERT INTO memorial_community_photos (memorial_id, sender_name, sender_email, caption, temp_image_path)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([
                (int) $memorial['id'],
                $pendingOtp['sender_name'],
                $pendingOtp['sender_email'],
                $pendingOtp['caption'],
                $path,
            ]);
        }

        $pdo->prepare('UPDATE memorial_photo_otps SET consumed_at = NOW() WHERE id = ?')
            ->execute([(int) $pendingOtp['id']]);

        flash('success', 'Your photos were submitted. They will appear after family approval.');
        redirect_to('/memorial.php?t=' . urlencode($token) . '&m=' . (int) $memorial['id'] . '#shared-photos');
    }
}

if ($isGroupView): ?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Family Memorials | AlaalaMo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css?v=<?= urlencode(defined('ASSET_VERSION') ? ASSET_VERSION : '20260514-53') ?>">
  </head>
  <body class="memorial-preview-page" style="<?= $themeStyle ?>">
    <main class="mobile-memorial mobile-memorial-group">
      <section class="mobile-memorial-header">
        <a class="mobile-memorial-brand" href="https://alaalamo.site" target="_blank" rel="noopener" aria-label="AlaalaMo home">
          <span class="brand-mark" aria-hidden="true">A</span>
          <span>AlaalaMo</span>
        </a>
        <p class="section-eyebrow">Family tribute</p>
        <h1>Memorials in this QR</h1>
        <p>Select a loved one to view their memorial page.</p>
      </section>
      <section class="mobile-memorial-section">
        <div class="memorial-card-list">
          <?php foreach ($memorials as $item): ?>
            <?php
              $imageStmt = $pdo->prepare(
                  'SELECT image_path
                   FROM memorial_images
                   WHERE memorial_id = ? AND image_type = "profile"
                   ORDER BY id ASC
                   LIMIT 1'
              );
              $imageStmt->execute([(int) $item['id']]);
              $image = $imageStmt->fetchColumn();
              if (!$image) {
                  $imageStmt = $pdo->prepare(
                      'SELECT image_path
                       FROM memorial_images
                       WHERE memorial_id = ? AND image_type = "gallery"
                       ORDER BY id ASC
                       LIMIT 1'
                  );
                  $imageStmt->execute([(int) $item['id']]);
                  $image = $imageStmt->fetchColumn();
              }
              $itemUrl = 'memorial.php?t=' . urlencode($token) . '&m=' . (int) $item['id'];
            ?>
            <a class="memorial-select-card" href="<?= htmlspecialchars($itemUrl, ENT_QUOTES, 'UTF-8') ?>">
              <?php if ($image): ?>
                <img
                  src="<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8') ?>"
                  alt="<?= htmlspecialchars($item['loved_one_name'], ENT_QUOTES, 'UTF-8') ?>"
                  data-lightbox-src="<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8') ?>"
                  data-lightbox-alt="<?= htmlspecialchars($item['loved_one_name'], ENT_QUOTES, 'UTF-8') ?>"
                >
              <?php endif; ?>
              <span>
                <strong><?= htmlspecialchars($item['loved_one_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                <small><?= htmlspecialchars(memorial_date_range($item), ENT_QUOTES, 'UTF-8') ?></small>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      </section>
      <footer class="mobile-memorial-footer">
        <span>All Rights Reserved @ 2026</span>
        <a href="https://alaalamo.site" target="_blank" rel="noopener">AlaalaMo</a>
        <span>Memories made easier to revisit.</span>
      </footer>
    </main>
  </body>
</html>
<?php exit; endif;

$stmt = $pdo->prepare('SELECT * FROM memorial_images WHERE memorial_id = ? AND image_type = "profile" ORDER BY id ASC LIMIT 5');
$stmt->execute([(int) $memorial['id']]);
$profileImages = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT * FROM memorial_images WHERE memorial_id = ? AND image_type = "gallery" ORDER BY id ASC LIMIT ' . (int) $planLimits['gallery_images']);
$stmt->execute([(int) $memorial['id']]);
$galleryImages = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT *
     FROM memorial_community_photos
     WHERE memorial_id = ? AND status = "approved" AND image_url IS NOT NULL
     ORDER BY approved_at DESC, id DESC'
);
$stmt->execute([(int) $memorial['id']]);
$communityPhotos = $stmt->fetchAll();

$heroImages = $profileImages ?: $galleryImages;

$stmt = $pdo->prepare('SELECT * FROM milestones WHERE memorial_id = ? ORDER BY sort_order ASC, id ASC LIMIT ' . (int) $planLimits['milestones']);
$stmt->execute([(int) $memorial['id']]);
$milestones = $stmt->fetchAll();

$milestoneImages = [];
if ($milestones) {
    $imageStmt = $pdo->prepare(
        'SELECT mi.*
         FROM milestone_images mi
         WHERE mi.milestone_id = ?
         ORDER BY mi.id ASC
         LIMIT ' . (int) $planLimits['milestone_images']
    );

    foreach ($milestones as $milestone) {
        $imageStmt->execute([(int) $milestone['id']]);
        $milestoneImages[(int) $milestone['id']] = $imageStmt->fetchAll();
    }
}

$stmt = $pdo->prepare(
    'SELECT *
     FROM memorial_messages
     WHERE memorial_id = ? AND status = "approved"
     ORDER BY approved_at DESC, id DESC'
);
$stmt->execute([(int) $memorial['id']]);
$approvedMessages = $stmt->fetchAll();
$messageFlash = get_flash();

?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= htmlspecialchars($memorial['loved_one_name'], ENT_QUOTES, 'UTF-8') ?> | AlaalaMo Memorial</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="styles.css?v=<?= urlencode(defined('ASSET_VERSION') ? ASSET_VERSION : '20260514-53') ?>">
  </head>
  <body class="memorial-preview-page" style="<?= $themeStyle ?>">
    <main class="mobile-memorial mx-auto" style="<?= $themeStyle ?>">
      <section class="mobile-memorial-cover d-flex align-items-end">
        <?php if ($heroImages): ?>
          <div class="profile-cover-slideshow" aria-hidden="true">
            <?php foreach ($heroImages as $imageIndex => $image): ?>
              <img
                class="<?= $imageIndex === 0 ? 'is-active' : '' ?>"
                src="<?= htmlspecialchars($image['image_path'], ENT_QUOTES, 'UTF-8') ?>"
                alt=""
              >
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <div class="mobile-memorial-cover-content w-100">
          <p class="section-eyebrow">In loving memory</p>
          <h1><?= htmlspecialchars($memorial['loved_one_name'], ENT_QUOTES, 'UTF-8') ?></h1>
          <p class="memorial-dates">
            <?= htmlspecialchars(memorial_date_range($memorial), ENT_QUOTES, 'UTF-8') ?>
          </p>
          <hr class="memorial-date-rule">
          <?php if (!empty($memorial['memorial_quote'])): ?>
            <blockquote><?= nl2br(htmlspecialchars($memorial['memorial_quote'], ENT_QUOTES, 'UTF-8')) ?></blockquote>
          <?php endif; ?>
          <?php if (!empty($memorial['resting_place'])): ?>
            <?php if ($restingMapsUrl): ?>
              <a class="memorial-resting-place" href="<?= htmlspecialchars($restingMapsUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                <span><?= htmlspecialchars($memorial['resting_place'], ENT_QUOTES, 'UTF-8') ?></span>
              </a>
            <?php else: ?>
              <p class="memorial-resting-place">
                <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                <span><?= htmlspecialchars($memorial['resting_place'], ENT_QUOTES, 'UTF-8') ?></span>
              </p>
            <?php endif; ?>
          <?php endif; ?>
          <div class="memorial-hero-actions d-grid gap-2 mt-3">
            <?php if ($planLimits['life_story'] && (!empty($memorial['autobiography_text']) || $milestones)): ?>
              <button class="btn btn-light btn-lg story-play-button" type="button">Play Life Story</button>
            <?php endif; ?>
            <?php if (($galleryImages || $communityPhotos) || $favoriteSongUrl !== ''): ?>
              <div class="row g-2 memorial-hero-secondary-actions">
                <?php if ($galleryImages || $communityPhotos): ?>
                  <div class="<?= $favoriteSongUrl !== '' ? 'col-6' : 'col-12' ?>">
                    <a class="btn btn-outline-light btn-lg w-100" href="#gallery">
                      <i class="fa-solid fa-images" aria-hidden="true"></i>
                      <span>View Gallery</span>
                    </a>
                  </div>
                <?php endif; ?>
                <?php if ($favoriteSongUrl !== ''): ?>
                  <div class="<?= ($galleryImages || $communityPhotos) ? 'col-6' : 'col-12' ?>">
                    <a class="btn btn-outline-light btn-lg w-100" href="#favorite-song" data-favorite-song-trigger>
                      <i class="fa-solid fa-music" aria-hidden="true"></i>
                      <span>Play Song</span>
                    </a>
                  </div>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <?php if (!empty($memorial['short_description'])): ?>
        <section class="mobile-memorial-section">
          <h2><i class="fa-solid fa-book-open section-title-icon" aria-hidden="true"></i>About</h2>
          <article class="memorial-info-card">
            <div class="memorial-info-card-body">
              <p><?= nl2br(htmlspecialchars($memorial['short_description'], ENT_QUOTES, 'UTF-8')) ?></p>
            </div>
          </article>
        </section>
      <?php endif; ?>

      <?php if ($planLimits['life_story'] && !empty($memorial['autobiography_text'])): ?>
        <section class="mobile-memorial-section life-story-player">
          <h2><i class="fa-solid fa-volume-high section-title-icon" aria-hidden="true"></i>Life Story</h2>
          <p><?= nl2br(htmlspecialchars($memorial['autobiography_text'], ENT_QUOTES, 'UTF-8')) ?></p>
          <p class="field-note">Narration uses the visitor device voice. No audio file is stored.</p>
        </section>
      <?php endif; ?>

      <?php if ($galleryImages || $communityPhotos): ?>
        <section class="mobile-memorial-section" id="gallery">
          <h2><i class="fa-solid fa-images section-title-icon" aria-hidden="true"></i>Gallery</h2>
          <?php if ($galleryImages): ?>
            <h3 class="gallery-subtitle">Photos from the family</h3>
            <div class="preview-gallery row g-2">
              <?php foreach ($galleryImages as $image): ?>
                <div class="col-6">
                  <img
                    class="img-fluid"
                    src="<?= htmlspecialchars($image['image_path'], ENT_QUOTES, 'UTF-8') ?>"
                    alt="Memorial photo"
                    data-lightbox-src="<?= htmlspecialchars($image['image_path'], ENT_QUOTES, 'UTF-8') ?>"
                    data-lightbox-alt="Memorial photo"
                  >
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if ($communityPhotos): ?>
            <h3 class="gallery-subtitle">Photos shared by friends</h3>
            <div class="preview-gallery row g-2">
              <?php foreach ($communityPhotos as $image): ?>
                <?php $sharedImageUrl = cloudinary_optimized_image_url((string) $image['image_url']); ?>
                <div class="col-6">
                  <figure class="community-photo-card">
                    <img
                      class="img-fluid"
                      src="<?= htmlspecialchars($sharedImageUrl, ENT_QUOTES, 'UTF-8') ?>"
                      alt="Shared memorial photo"
                      data-lightbox-src="<?= htmlspecialchars($sharedImageUrl, ENT_QUOTES, 'UTF-8') ?>"
                      data-lightbox-alt="Shared memorial photo"
                      data-lightbox-caption="<?= htmlspecialchars((string) ($image['caption'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                      data-lightbox-credit="<?= htmlspecialchars((string) ($image['sender_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    >
                    <?php if (!empty($image['caption'])): ?>
                      <figcaption><?= htmlspecialchars($image['caption'], ENT_QUOTES, 'UTF-8') ?></figcaption>
                    <?php endif; ?>
                  </figure>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>
      <section class="mobile-memorial-section shared-photos-section" id="shared-photos">
        <div class="messages-love-head">
          <h2><i class="fa-solid fa-camera-retro section-title-icon" aria-hidden="true"></i>Shared Photos</h2>
          <button class="btn btn-light btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#photoShareModal">
            Share a Photo
          </button>
        </div>
        <?php if ($messageFlash): ?>
          <p class="auth-alert auth-alert-<?= htmlspecialchars($messageFlash['type'], ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($messageFlash['message'], ENT_QUOTES, 'UTF-8') ?>
          </p>
        <?php endif; ?>
        <p class="field-note">If you have a photo with our loved one, please share it with us. Photos appear only after family approval.</p>
      </section>

      <?php if ($milestones): ?>
        <section class="mobile-memorial-section">
          <h2><i class="fa-solid fa-timeline section-title-icon" aria-hidden="true"></i>Life Milestones</h2>
          <?php foreach ($milestones as $milestone): ?>
            <?php $imagesForMilestone = $milestoneImages[(int) $milestone['id']] ?? []; ?>
            <article
              class="preview-milestone"
              data-narration="<?= htmlspecialchars($milestone['ai_narration_text'] ?: $milestone['description'], ENT_QUOTES, 'UTF-8') ?>"
              data-images="<?= htmlspecialchars(json_encode(array_column($imagesForMilestone, 'image_path'), JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>"
            >
              <header class="preview-milestone-head">
                <span><?= htmlspecialchars($milestone['milestone_date'], ENT_QUOTES, 'UTF-8') ?></span>
                <h3><?= htmlspecialchars($milestone['title'], ENT_QUOTES, 'UTF-8') ?></h3>
              </header>
              <div class="preview-milestone-body">
                <p><?= nl2br(htmlspecialchars($milestone['ai_narration_text'] ?: $milestone['description'], ENT_QUOTES, 'UTF-8')) ?></p>
              </div>
              <?php if ($imagesForMilestone): ?>
                <footer class="preview-milestone-footer">
                  <div class="milestone-image-grid">
                    <?php foreach ($imagesForMilestone as $image): ?>
                      <img
                        src="<?= htmlspecialchars($image['image_path'], ENT_QUOTES, 'UTF-8') ?>"
                        alt="Milestone photo"
                        data-lightbox-src="<?= htmlspecialchars($image['image_path'], ENT_QUOTES, 'UTF-8') ?>"
                        data-lightbox-alt="Milestone photo"
                      >
                    <?php endforeach; ?>
                  </div>
                </footer>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>
      <section class="mobile-memorial-section" id="messages">
        <div class="messages-love-head">
          <h2><i class="fa-solid fa-heart section-title-icon" aria-hidden="true"></i>Messages of Love</h2>
          <button class="btn btn-light btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#messageLoveModal">
            Leave a Message
          </button>
        </div>
        <?php if ($messageFlash): ?>
          <p class="auth-alert auth-alert-<?= htmlspecialchars($messageFlash['type'], ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($messageFlash['message'], ENT_QUOTES, 'UTF-8') ?>
          </p>
        <?php endif; ?>
        <div class="messages-love-carousel">
          <?php if ($approvedMessages): ?>
            <div class="messages-love-track">
              <?php foreach ($approvedMessages as $loveMessage): ?>
                <article class="message-love-card">
                  <p>&ldquo;<?= nl2br(htmlspecialchars($loveMessage['message'], ENT_QUOTES, 'UTF-8')) ?>&rdquo;</p>
                  <strong><?= htmlspecialchars($loveMessage['sender_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                </article>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="field-note">No approved messages yet. Be the first to leave one for the family to review.</p>
          <?php endif; ?>
        </div>
      </section>
      <?php if ($favoriteSongUrl !== ''): ?>
        <section class="mobile-memorial-section favorite-song-section" id="favorite-song" data-favorite-song-section hidden>
          <h2><i class="fa-solid fa-music section-title-icon" aria-hidden="true"></i>Favorite Song</h2>
          <?php if ($favoriteSongEmbed): ?>
            <iframe
              class="favorite-song-embed <?= $favoriteSongEmbed['type'] === 'YouTube' ? 'favorite-song-embed-youtube' : '' ?>"
              src="<?= htmlspecialchars($favoriteSongEmbed['url'], ENT_QUOTES, 'UTF-8') ?>"
              title="Favorite song <?= htmlspecialchars($favoriteSongEmbed['type'], ENT_QUOTES, 'UTF-8') ?> player"
              frameborder="0"
              allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
              referrerpolicy="strict-origin-when-cross-origin"
              allowfullscreen
            ></iframe>
          <?php else: ?>
            <a class="favorite-song-link" href="<?= htmlspecialchars($favoriteSongUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
              <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
              Open favorite song
            </a>
          <?php endif; ?>
        </section>
      <?php endif; ?>
      <footer class="mobile-memorial-footer">
        <span>All Rights Reserved @ 2026</span>
        <a href="https://alaalamo.site" target="_blank" rel="noopener">AlaalaMo</a>
        <span>Memories made easier to revisit.</span>
      </footer>
    </main>
    <div class="story-modal" aria-hidden="true">
      <div class="story-modal-backdrop"></div>
      <section class="story-modal-panel" role="dialog" aria-modal="true" aria-label="Life story narration">
        <button class="story-modal-close" type="button" aria-label="Close life story">&times;</button>
        <div class="story-modal-media">
          <img class="story-modal-image story-modal-image-a is-active" src="" alt="">
          <img class="story-modal-image story-modal-image-b" src="" alt="">
        </div>
        <div class="story-modal-copy">
          <h2 class="story-modal-title">Life Story</h2>
          <p class="story-modal-text"></p>
        </div>
      </section>
    </div>
    <div class="image-lightbox" aria-hidden="true">
      <div class="image-lightbox-backdrop"></div>
      <section class="image-lightbox-panel" role="dialog" aria-modal="true" aria-label="Image preview">
        <button class="image-lightbox-close" type="button" aria-label="Close image preview">&times;</button>
        <img class="image-lightbox-img" src="" alt="">
        <div class="image-lightbox-copy" hidden>
          <p class="image-lightbox-caption"></p>
          <p class="image-lightbox-credit"></p>
        </div>
        <p class="image-lightbox-hint">Swipe left or right to browse images.</p>
      </section>
    </div>
    <div class="modal fade" id="messageLoveModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content message-love-modal">
          <div class="modal-header">
            <h2 class="modal-title"><?= isset($_GET['verify_message']) ? 'Enter OTP' : 'Leave a Message' ?></h2>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <?php if (isset($_GET['verify_message'])): ?>
              <form method="post" action="memorial.php?t=<?= urlencode($token) ?>&m=<?= (int) $memorial['id'] ?>#messages">
                <input type="hidden" name="form_action" value="verify_message_otp">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="memorial_id" value="<?= (int) $memorial['id'] ?>">
                <label>
                  Email address
                  <input class="form-control" type="email" name="sender_email" value="<?= htmlspecialchars($_GET['message_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                </label>
                <label class="mt-3">
                  OTP
                  <input class="form-control" type="text" name="otp" inputmode="numeric" maxlength="6" required>
                </label>
                <button class="btn btn-primary w-100 mt-3" type="submit">Submit Message for Approval</button>
              </form>
            <?php else: ?>
              <form method="post" action="memorial.php?t=<?= urlencode($token) ?>&m=<?= (int) $memorial['id'] ?>#messages">
                <input type="hidden" name="form_action" value="request_message_otp">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="memorial_id" value="<?= (int) $memorial['id'] ?>">
                <label>
                  Your name
                  <input class="form-control" type="text" name="sender_name" maxlength="120" required>
                </label>
                <label class="mt-3">
                  Email address
                  <input class="form-control" type="email" name="sender_email" required>
                </label>
                <label class="mt-3">
                  Message
                  <textarea class="form-control" name="message" rows="4" maxlength="700" required></textarea>
                </label>
                <p class="field-note mt-2">We will send a 1-minute OTP before submitting. Messages appear only after family approval.</p>
                <button class="btn btn-primary w-100 mt-3" type="submit">Send OTP</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <div class="modal fade" id="photoShareModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content message-love-modal">
          <div class="modal-header">
            <h2 class="modal-title"><?= isset($_GET['verify_photo']) ? 'Enter Photo OTP' : 'Share Photos' ?></h2>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <?php if (isset($_GET['verify_photo'])): ?>
              <form method="post" action="memorial.php?t=<?= urlencode($token) ?>&m=<?= (int) $memorial['id'] ?>#shared-photos">
                <input type="hidden" name="form_action" value="verify_photo_otp">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="memorial_id" value="<?= (int) $memorial['id'] ?>">
                <label>
                  Email address
                  <input class="form-control" type="email" name="sender_email" value="<?= htmlspecialchars($_GET['photo_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                </label>
                <label class="mt-3">
                  OTP
                  <input class="form-control" type="text" name="otp" inputmode="numeric" maxlength="6" required>
                </label>
                <button class="btn btn-primary w-100 mt-3" type="submit">Submit Photos for Approval</button>
              </form>
            <?php else: ?>
              <form method="post" enctype="multipart/form-data" action="memorial.php?t=<?= urlencode($token) ?>&m=<?= (int) $memorial['id'] ?>#shared-photos">
                <input type="hidden" name="form_action" value="request_photo_otp">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="memorial_id" value="<?= (int) $memorial['id'] ?>">
                <label>
                  Your name
                  <input class="form-control" type="text" name="sender_name" maxlength="120" required>
                </label>
                <label class="mt-3">
                  Email address
                  <input class="form-control" type="email" name="sender_email" required>
                </label>
                <label class="mt-3">
                  Photo caption
                  <textarea class="form-control" name="caption" rows="3" maxlength="255" placeholder="When was this taken? What event or activity was this?" required></textarea>
                </label>
                <label class="mt-3">
                  Photos
                  <input class="form-control" type="file" name="shared_photos[]" accept="image/jpeg,image/png,image/webp" required>
                </label>
                <p class="field-note mt-2">One photo per email for this memorial, so more people can share. We will send a 1-minute OTP before submitting. Photos appear only after family approval.</p>
                <button class="btn btn-primary w-100 mt-3" type="submit">Send OTP</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/js/bootstrap.bundle.min.js"></script>
    <script>
      const playButton = document.querySelector('.story-play-button');
      const profileCoverImages = Array.from(document.querySelectorAll('.profile-cover-slideshow img'));
      const milestones = Array.from(document.querySelectorAll('.preview-milestone'));
      const modal = document.querySelector('.story-modal');
      const modalImages = Array.from(document.querySelectorAll('.story-modal-image'));
      const modalTitle = document.querySelector('.story-modal-title');
      const modalText = document.querySelector('.story-modal-text');
      const modalClose = document.querySelector('.story-modal-close');
      const imageLightbox = document.querySelector('.image-lightbox');
      const imageLightboxImage = document.querySelector('.image-lightbox-img');
      const imageLightboxCopy = document.querySelector('.image-lightbox-copy');
      const imageLightboxCaption = document.querySelector('.image-lightbox-caption');
      const imageLightboxCredit = document.querySelector('.image-lightbox-credit');
      const imageLightboxClose = document.querySelector('.image-lightbox-close');
      const lightboxImages = Array.from(document.querySelectorAll('[data-lightbox-src]'));
      const favoriteSongTrigger = document.querySelector('[data-favorite-song-trigger]');
      const favoriteSongSection = document.querySelector('[data-favorite-song-section]');
      let activeLightboxIndex = -1;
      let lightboxTouchStartX = 0;
      let slideTimer = null;
      let profileCoverTimer = null;
      let activeModalImage = 0;
      let preferredNarrationVoice = null;

      if (profileCoverImages.length > 1) {
        let profileIndex = 0;
        profileCoverTimer = setInterval(() => {
          profileCoverImages[profileIndex].classList.remove('is-active');
          profileIndex = (profileIndex + 1) % profileCoverImages.length;
          profileCoverImages[profileIndex].classList.add('is-active');
        }, 8200);
      }

      function stopNarration() {
        window.speechSynthesis?.cancel();
        clearInterval(slideTimer);
        modal?.classList.remove('is-open');
        modal?.setAttribute('aria-hidden', 'true');
        document.querySelectorAll('.preview-milestone.is-playing').forEach((item) => {
          item.classList.remove('is-playing');
        });
      }

      function runSlideshow(images, narrationText = '') {
        clearInterval(slideTimer);
        if (!modalImages.length || !images.length) return;

        let index = 0;
        const slideCount = Math.min(images.length, 6);
        const estimatedWords = narrationText.trim().split(/\s+/).filter(Boolean).length || 50;
        const estimatedNarrationMs = Math.max(9000, Math.min(18000, estimatedWords * 300));
        const slideDelay = Math.max(1400, Math.floor(estimatedNarrationMs / Math.max(slideCount, 1)));
        activeModalImage = 0;
        modalImages.forEach((image, imageIndex) => {
          image.classList.toggle('is-active', imageIndex === 0);
          image.src = imageIndex === 0 ? images[index] : '';
        });

        slideTimer = setInterval(() => {
          index = (index + 1) % slideCount;
          const nextImage = modalImages[activeModalImage === 0 ? 1 : 0];
          const currentImage = modalImages[activeModalImage];
          nextImage.src = images[index];
          nextImage.classList.add('is-active');
          currentImage.classList.remove('is-active');
          activeModalImage = activeModalImage === 0 ? 1 : 0;
        }, slideDelay);
      }

      function scoreNarrationVoice(voice) {
        const name = `${voice.name} ${voice.voiceURI}`.toLowerCase();
        let score = 0;

        if (voice.lang && voice.lang.toLowerCase().startsWith('en')) score += 12;
        if (voice.localService) score += 1;
        if (name.includes('male')) score += 24;
        if (name.includes('david')) score += 20;
        if (name.includes('daniel')) score += 20;
        if (name.includes('mark')) score += 18;
        if (name.includes('george')) score += 18;
        if (name.includes('fred')) score += 14;
        if (name.includes('google uk english male')) score += 30;
        if (name.includes('google us english')) score += 10;
        if (name.includes('microsoft')) score += 8;
        if (name.includes('natural')) score += 8;
        if (name.includes('online')) score += 6;
        if (name.includes('female')) score -= 24;
        if (name.includes('zira')) score -= 14;
        if (name.includes('susan')) score -= 14;
        if (name.includes('samantha')) score -= 14;
        if (name.includes('victoria')) score -= 14;

        return score;
      }

      function selectNarrationVoice() {
        const voices = window.speechSynthesis?.getVoices?.() || [];

        if (!voices.length) {
          return null;
        }

        preferredNarrationVoice = voices
          .slice()
          .sort((first, second) => scoreNarrationVoice(second) - scoreNarrationVoice(first))[0] || null;

        return preferredNarrationVoice;
      }

      if ('speechSynthesis' in window) {
        selectNarrationVoice();
        window.speechSynthesis.onvoiceschanged = selectNarrationVoice;
      }

      function speakMilestone(index) {
        if (index >= milestones.length) {
          stopNarration();
          return;
        }

        const milestone = milestones[index];
        const text = milestone.dataset.narration || '';
        const title = milestone.querySelector('h3')?.textContent || 'Life Story';
        let images = [];
        try {
          images = JSON.parse(milestone.dataset.images || '[]');
        } catch (error) {
          images = [];
        }
        if (!text.trim()) {
          speakMilestone(index + 1);
          return;
        }

        document.querySelectorAll('.preview-milestone.is-playing').forEach((item) => {
          item.classList.remove('is-playing');
        });
        milestone.classList.add('is-playing');
        modal?.classList.add('is-open');
        modal?.setAttribute('aria-hidden', 'false');
        if (modalTitle) modalTitle.textContent = title;
        if (modalText) modalText.textContent = text;
        runSlideshow(images, text);

        const utterance = new SpeechSynthesisUtterance(text);
        utterance.voice = preferredNarrationVoice || selectNarrationVoice();
        utterance.rate = 0.92;
        utterance.pitch = 0.72;
        utterance.volume = 1;
        utterance.onend = () => speakMilestone(index + 1);
        window.speechSynthesis.speak(utterance);
      }

      playButton?.addEventListener('click', () => {
        if (!('speechSynthesis' in window)) {
          alert('Narration is not supported on this browser.');
          return;
        }
        stopNarration();
        speakMilestone(0);
      });

      modalClose?.addEventListener('click', stopNarration);
      document.querySelector('.story-modal-backdrop')?.addEventListener('click', stopNarration);

      favoriteSongTrigger?.addEventListener('click', (event) => {
        event.preventDefault();
        favoriteSongSection?.removeAttribute('hidden');
        favoriteSongSection?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });

      function closeImageLightbox() {
        imageLightbox?.classList.remove('is-open');
        imageLightbox?.setAttribute('aria-hidden', 'true');
        if (imageLightboxImage) {
          imageLightboxImage.src = '';
          imageLightboxImage.alt = '';
        }
        if (imageLightboxCopy) {
          imageLightboxCopy.hidden = true;
        }
        if (imageLightboxCaption) {
          imageLightboxCaption.textContent = '';
        }
        if (imageLightboxCredit) {
          imageLightboxCredit.textContent = '';
        }
        activeLightboxIndex = -1;
      }

      function showLightboxImage(index) {
        if (!lightboxImages.length) return;
        activeLightboxIndex = (index + lightboxImages.length) % lightboxImages.length;
        const image = lightboxImages[activeLightboxIndex];
        if (imageLightboxImage) {
          imageLightboxImage.src = image.dataset.lightboxSrc;
          imageLightboxImage.alt = image.dataset.lightboxAlt || image.alt || 'Memorial image';
        }
        const caption = image.dataset.lightboxCaption || '';
        const credit = image.dataset.lightboxCredit || '';
        if (imageLightboxCaption) imageLightboxCaption.textContent = caption;
        if (imageLightboxCredit) imageLightboxCredit.textContent = credit ? `Shared by ${credit}` : '';
        if (imageLightboxCopy) imageLightboxCopy.hidden = !caption && !credit;
      }

      document.addEventListener('click', (event) => {
        const image = event.target.closest('[data-lightbox-src]');

        if (!image) return;

        event.preventDefault();
        showLightboxImage(lightboxImages.indexOf(image));
        imageLightbox?.classList.add('is-open');
        imageLightbox?.setAttribute('aria-hidden', 'false');
      });

      imageLightbox?.addEventListener('touchstart', (event) => {
        lightboxTouchStartX = event.touches[0]?.clientX || 0;
      }, { passive: true });
      imageLightbox?.addEventListener('touchend', (event) => {
        const endX = event.changedTouches[0]?.clientX || 0;
        const deltaX = endX - lightboxTouchStartX;

        if (Math.abs(deltaX) < 45 || lightboxImages.length < 2) return;
        showLightboxImage(deltaX < 0 ? activeLightboxIndex + 1 : activeLightboxIndex - 1);
      }, { passive: true });
      imageLightboxClose?.addEventListener('click', closeImageLightbox);
      document.querySelector('.image-lightbox-backdrop')?.addEventListener('click', closeImageLightbox);
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          closeImageLightbox();
        } else if (imageLightbox?.classList.contains('is-open') && event.key === 'ArrowLeft') {
          showLightboxImage(activeLightboxIndex - 1);
        } else if (imageLightbox?.classList.contains('is-open') && event.key === 'ArrowRight') {
          showLightboxImage(activeLightboxIndex + 1);
        }
      });
      <?php if (isset($_GET['verify_message'])): ?>
        const messageModal = document.getElementById('messageLoveModal');
        if (messageModal && window.bootstrap) {
          bootstrap.Modal.getOrCreateInstance(messageModal).show();
        }
        if (window.history?.replaceState) {
          const cleanUrl = new URL(window.location.href);
          cleanUrl.searchParams.delete('verify_message');
          cleanUrl.searchParams.delete('message_email');
          window.history.replaceState({}, '', cleanUrl.pathname + cleanUrl.search + '#messages');
        }
      <?php endif; ?>
      <?php if (isset($_GET['verify_photo'])): ?>
        const photoModal = document.getElementById('photoShareModal');
        if (photoModal && window.bootstrap) {
          bootstrap.Modal.getOrCreateInstance(photoModal).show();
        }
        if (window.history?.replaceState) {
          const cleanPhotoUrl = new URL(window.location.href);
          cleanPhotoUrl.searchParams.delete('verify_photo');
          cleanPhotoUrl.searchParams.delete('photo_email');
          window.history.replaceState({}, '', cleanPhotoUrl.pathname + cleanPhotoUrl.search + '#shared-photos');
        }
      <?php endif; ?>
      window.addEventListener('beforeunload', stopNarration);
    </script>
  </body>
</html>

