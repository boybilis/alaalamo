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
    <link rel="stylesheet" href="styles.css?v=<?= urlencode(defined('ASSET_VERSION') ? ASSET_VERSION : '20260513-35') ?>">
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
    <link rel="stylesheet" href="styles.css?v=<?= urlencode(defined('ASSET_VERSION') ? ASSET_VERSION : '20260513-35') ?>">
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
            <p class="memorial-resting-place">
              <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
              <span><?= htmlspecialchars($memorial['resting_place'], ENT_QUOTES, 'UTF-8') ?></span>
            </p>
          <?php endif; ?>
          <div class="memorial-hero-actions d-grid gap-2 mt-3">
            <?php if ($planLimits['life_story'] && (!empty($memorial['autobiography_text']) || $milestones)): ?>
              <button class="btn btn-light btn-lg story-play-button" type="button">Play Life Story</button>
            <?php endif; ?>
            <?php if ($galleryImages): ?>
              <a class="btn btn-outline-light btn-lg" href="#gallery">View Gallery</a>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <?php if (!empty($memorial['short_description'])): ?>
        <section class="mobile-memorial-section">
          <article class="memorial-info-card">
            <header class="memorial-info-card-head">
              <h2>About</h2>
            </header>
            <div class="memorial-info-card-body">
              <p><?= nl2br(htmlspecialchars($memorial['short_description'], ENT_QUOTES, 'UTF-8')) ?></p>
            </div>
          </article>
        </section>
      <?php endif; ?>

      <?php if ($planLimits['life_story'] && !empty($memorial['autobiography_text'])): ?>
        <section class="mobile-memorial-section life-story-player">
          <h2>Life Story</h2>
          <p><?= nl2br(htmlspecialchars($memorial['autobiography_text'], ENT_QUOTES, 'UTF-8')) ?></p>
          <p class="field-note">Narration uses the visitor device voice. No audio file is stored.</p>
        </section>
      <?php endif; ?>

      <?php if ($galleryImages): ?>
        <section class="mobile-memorial-section" id="gallery">
          <h2>Gallery</h2>
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
        </section>
      <?php endif; ?>

      <?php if ($milestones): ?>
        <section class="mobile-memorial-section">
          <h2>Life Milestones</h2>
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
          <h2>Messages of Love</h2>
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
      const imageLightboxClose = document.querySelector('.image-lightbox-close');
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

      function runSlideshow(images) {
        clearInterval(slideTimer);
        if (!modalImages.length || !images.length) return;

        let index = 0;
        activeModalImage = 0;
        modalImages.forEach((image, imageIndex) => {
          image.classList.toggle('is-active', imageIndex === 0);
          image.src = imageIndex === 0 ? images[index] : '';
        });

        slideTimer = setInterval(() => {
          index = (index + 1) % images.length;
          const nextImage = modalImages[activeModalImage === 0 ? 1 : 0];
          const currentImage = modalImages[activeModalImage];
          nextImage.src = images[index];
          nextImage.classList.add('is-active');
          currentImage.classList.remove('is-active');
          activeModalImage = activeModalImage === 0 ? 1 : 0;
        }, 5200);
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
        runSlideshow(images);

        const utterance = new SpeechSynthesisUtterance(text);
        utterance.voice = preferredNarrationVoice || selectNarrationVoice();
        utterance.rate = 0.78;
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
      function closeImageLightbox() {
        imageLightbox?.classList.remove('is-open');
        imageLightbox?.setAttribute('aria-hidden', 'true');
        if (imageLightboxImage) {
          imageLightboxImage.src = '';
          imageLightboxImage.alt = '';
        }
      }

      document.addEventListener('click', (event) => {
        const image = event.target.closest('[data-lightbox-src]');

        if (!image) return;

        event.preventDefault();
        if (imageLightboxImage) {
          imageLightboxImage.src = image.dataset.lightboxSrc;
          imageLightboxImage.alt = image.dataset.lightboxAlt || image.alt || 'Memorial image';
        }
        imageLightbox?.classList.add('is-open');
        imageLightbox?.setAttribute('aria-hidden', 'false');
      });

      imageLightboxClose?.addEventListener('click', closeImageLightbox);
      document.querySelector('.image-lightbox-backdrop')?.addEventListener('click', closeImageLightbox);
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          closeImageLightbox();
        }
      });
      <?php if (isset($_GET['verify_message'])): ?>
        const messageModal = document.getElementById('messageLoveModal');
        if (messageModal && window.bootstrap) {
          bootstrap.Modal.getOrCreateInstance(messageModal).show();
        }
      <?php endif; ?>
      window.addEventListener('beforeunload', stopNarration);
    </script>
  </body>
</html>

