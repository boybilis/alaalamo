<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

require __DIR__ . '/config.php';

start_app_session();

if (empty($_SESSION['user_id'])) {
    redirect_to('/login.php');
}

if (!defined('PAYMENT_ADMIN_EMAIL')) {
    define('PAYMENT_ADMIN_EMAIL', MAIL_FROM);
}

if (!defined('GCASH_ACCOUNT_NAME')) {
    define('GCASH_ACCOUNT_NAME', 'AlaalaMo');
}

if (!defined('GCASH_ACCOUNT_NUMBER')) {
    define('GCASH_ACCOUNT_NUMBER', '');
}

function billing_plan_price(string $planType): int
{
    return $planType === 'premium' ? 999 : 599;
}

function billing_additional_memorial_price(string $planType): int
{
    return $planType === 'premium' ? 700 : 399;
}

function billing_plan_label(string $planType): string
{
    return $planType === 'premium' ? 'Premium' : 'Regular';
}

function billing_memorial_price(array $memorial, array $qrGroup, bool $isAdditional): int
{
    $planType = (($memorial['plan_type'] ?? $qrGroup['plan_type'] ?? 'regular') === 'premium') ? 'premium' : 'regular';

    return $isAdditional ? billing_additional_memorial_price($planType) : billing_plan_price($planType);
}

function validate_payment_proof_upload(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return 'Please attach your GCash payment screenshot before requesting activation.';
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return 'The payment screenshot could not be uploaded. Please try again.';
    }

    if (($file['size'] ?? 0) <= 0) {
        return 'The attached payment screenshot appears to be empty.';
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return 'The payment screenshot must be 5MB or smaller.';
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file((string) ($file['tmp_name'] ?? ''));
    $allowedTypes = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    if (!in_array($mimeType, $allowedTypes, true)) {
        return 'Please upload a JPG, PNG, or WebP screenshot for the payment proof.';
    }

    return null;
}

function send_payment_review_email(array $user, array $qrGroup, array $memorial, bool $isAdditional, array $paymentProof): bool
{
    $autoloadPath = __DIR__ . '/vendor/autoload.php';

    if (!is_file($autoloadPath)) {
        error_log('PHPMailer is not installed. Run composer install before payment review emails.');
        return false;
    }

    require_once $autoloadPath;

    $planType = (($memorial['plan_type'] ?? $qrGroup['plan_type'] ?? 'regular') === 'premium') ? 'premium' : 'regular';
    $amount = billing_memorial_price($memorial, $qrGroup, $isAdditional);
    $approveUrl = rtrim(app_base_url(), '/') . '/payment-approve.php?token=' . urlencode((string) $memorial['payment_approval_token']);
    $subject = 'AlaalaMo payment review: ' . (string) $memorial['loved_one_name'];
    $plainText = "Payment review request\n\n"
        . "Client: " . ($user['given_name'] ?? '') . ' ' . ($user['last_name'] ?? '') . "\n"
        . "Email: " . ($user['email'] ?? '') . "\n"
        . "Memorial: " . ($memorial['loved_one_name'] ?? '') . "\n"
        . "Plan: " . billing_plan_label($planType) . "\n"
        . "Amount: PHP " . number_format($amount) . " / year\n\n"
        . "Approve payment: " . $approveUrl;
    $html = '
        <div style="font-family: Arial, sans-serif; max-width: 620px; margin: 0 auto; color: #1f2933;">
            <h1 style="color:#214c63;">AlaalaMo payment review</h1>
            <p><strong>Client:</strong> ' . htmlspecialchars(($user['given_name'] ?? '') . ' ' . ($user['last_name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>
            <p><strong>Email:</strong> ' . htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>
            <p><strong>Memorial:</strong> ' . htmlspecialchars((string) ($memorial['loved_one_name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>
            <p><strong>Plan:</strong> ' . htmlspecialchars(billing_plan_label($planType), ENT_QUOTES, 'UTF-8') . '</p>
            <p><strong>Amount:</strong> PHP ' . number_format($amount) . ' / year</p>
            <p style="margin-top:24px;">
                <a href="' . htmlspecialchars($approveUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:#1f6848;color:#fff;text-decoration:none;padding:14px 20px;border-radius:8px;font-weight:800;">Mark as Paid</a>
            </p>
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
        $mail->addAddress(PAYMENT_ADMIN_EMAIL);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = $plainText;
        $mail->addAttachment(
            (string) $paymentProof['tmp_name'],
            (string) ($paymentProof['name'] ?? ('payment-proof-' . (int) $memorial['id'] . '.jpg'))
        );

        return $mail->send();
    } catch (MailException $exception) {
        error_log('Payment review email failed: ' . $exception->getMessage());
        return false;
    }
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([(int) $_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    redirect_to('/login.php');
}

$qrGroup = ensure_qr_group((int) $user['id']);
$memorialId = (int) ($_GET['memorial_id'] ?? $_POST['memorial_id'] ?? 0);
$requestedPlanType = clean_input($_GET['requested_plan'] ?? $_POST['requested_plan'] ?? '');
$requestedPlanType = $requestedPlanType === 'premium' ? 'premium' : ($requestedPlanType === 'regular' ? 'regular' : '');

if ($memorialId <= 0) {
    $stmt = $pdo->prepare('SELECT id FROM memorials WHERE user_id = ? AND qr_group_id = ? ORDER BY id ASC LIMIT 1');
    $stmt->execute([(int) $user['id'], (int) $qrGroup['id']]);
    $memorialId = (int) $stmt->fetchColumn();
}

if ($memorialId <= 0) {
    flash('error', 'Please save a memorial first before requesting payment activation.');
    redirect_to('/dashboard.php');
}

$stmt = $pdo->prepare('SELECT * FROM memorials WHERE id = ? AND user_id = ? AND qr_group_id = ? LIMIT 1');
$stmt->execute([$memorialId, (int) $user['id'], (int) $qrGroup['id']]);
$memorial = $stmt->fetch();

if (!$memorial) {
    flash('error', 'Memorial not found.');
    redirect_to('/dashboard.php');
}

if (($memorial['payment_status'] ?? 'pending') !== 'paid' && $requestedPlanType !== '' && $requestedPlanType !== ($memorial['plan_type'] ?? 'regular')) {
    $pdo->prepare('UPDATE memorials SET plan_type = ? WHERE id = ?')->execute([$requestedPlanType, (int) $memorial['id']]);
    $stmt = $pdo->prepare('SELECT * FROM memorials WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $memorial['id']]);
    $memorial = $stmt->fetch();
}

if (($memorial['payment_status'] ?? 'pending') === 'paid') {
    redirect_to('/dashboard.php?memorial_id=' . (int) $memorial['id']);
}

$firstStmt = $pdo->prepare('SELECT MIN(id) FROM memorials WHERE qr_group_id = ?');
$firstStmt->execute([(int) $qrGroup['id']]);
$isAdditional = (int) $firstStmt->fetchColumn() !== (int) $memorial['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $paymentProof = $_FILES['payment_proof'] ?? [];
    $proofError = validate_payment_proof_upload($paymentProof);

    if ($proofError !== null) {
        flash('error', $proofError);
        redirect_to('/billing.php?memorial_id=' . (int) $memorial['id']);
    }

    $approvalToken = (string) ($memorial['payment_approval_token'] ?? '');

    if ($approvalToken === '') {
        $approvalToken = generate_token();
    }

    $memorial['payment_approval_token'] = $approvalToken;

    if (send_payment_review_email($user, $qrGroup, $memorial, $isAdditional, $paymentProof)) {
        $pdo->prepare(
            'UPDATE memorials
             SET payment_status = "review", payment_requested_at = NOW(), payment_approval_token = ?
             WHERE id = ?'
        )->execute([$approvalToken, (int) $memorial['id']]);
        flash('success', 'Payment review request sent. We will activate this memorial after confirmation.');
    } else {
        flash('error', 'Your request was saved, but the review email could not be sent. Please contact AlaalaMo support.');
    }

    redirect_to('/billing.php?memorial_id=' . (int) $memorial['id']);
}

$planType = (($memorial['plan_type'] ?? $qrGroup['plan_type'] ?? 'regular') === 'premium') ? 'premium' : 'regular';
$amount = billing_memorial_price($memorial, $qrGroup, $isAdditional);
$flash = get_flash();
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment | AlaalaMo</title>
    <link rel="stylesheet" href="styles.css?v=<?= urlencode((defined('ASSET_VERSION') ? ASSET_VERSION . '-' : '') . (string) (file_exists(__DIR__ . '/styles.css') ? filemtime(__DIR__ . '/styles.css') : time())) ?>">
  </head>
  <body class="auth-page">
    <main class="auth-card">
      <a class="brand auth-brand" href="/">
        <span class="brand-mark" aria-hidden="true">A</span>
        <span class="brand-highlight">AlaalaMo</span>
      </a>
      <h1>Complete your yearly subscription</h1>
      <p><?= htmlspecialchars((string) $memorial['loved_one_name'], ENT_QUOTES, 'UTF-8') ?>: PHP <?= number_format($amount) ?> / year.</p>
      <?php if ($flash): ?>
        <p class="auth-alert auth-alert-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>">
          <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?>
        </p>
      <?php endif; ?>
      <div class="payment-summary">
        <img class="payment-qr-image" src="assets/gcash-alaalamo.jfif" alt="AlaalaMo temporary GCash QR code">
        <p class="payment-amount"><strong>Amount to send:</strong> PHP <?= number_format($amount) ?></p>
        <p><strong>Plan:</strong> <?= htmlspecialchars(billing_plan_label($planType), ENT_QUOTES, 'UTF-8') ?><?= $isAdditional ? ' additional memorial' : '' ?></p>
        <p><strong>GCash name:</strong> <?= htmlspecialchars(GCASH_ACCOUNT_NAME, ENT_QUOTES, 'UTF-8') ?></p>
        <?php if (GCASH_ACCOUNT_NUMBER !== ''): ?>
          <p><strong>GCash number:</strong> <?= htmlspecialchars(GCASH_ACCOUNT_NUMBER, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <p><strong>Status:</strong> <?= htmlspecialchars(ucfirst((string) ($memorial['payment_status'] ?? 'pending')), ENT_QUOTES, 'UTF-8') ?></p>
      </div>
      <p class="field-note">After sending payment through GCash, click the button below so AlaalaMo can review and activate this memorial.</p>
      <form class="auth-form" method="post" enctype="multipart/form-data" action="billing.php">
        <input type="hidden" name="memorial_id" value="<?= (int) $memorial['id'] ?>">
        <input type="hidden" name="requested_plan" value="<?= htmlspecialchars((string) ($memorial['plan_type'] ?? 'regular'), ENT_QUOTES, 'UTF-8') ?>">
        <label>
          GCash payment screenshot
          <input type="file" name="payment_proof" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required>
          <span class="field-note">Attach a clear screenshot of your GCash reference before requesting activation.</span>
        </label>
        <button class="button-success" type="submit">I have paid. Request activation.</button>
      </form>
      <a class="auth-link" href="/dashboard.php?memorial_id=<?= (int) $memorial['id'] ?>">Back to dashboard</a>
      <a class="auth-link" href="/logout.php">Logout</a>
    </main>
  </body>
</html>
