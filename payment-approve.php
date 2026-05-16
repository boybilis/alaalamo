<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

if (!defined('EARLY_BIRD_REGULAR_UPGRADE_LIMIT')) {
    define('EARLY_BIRD_REGULAR_UPGRADE_LIMIT', 50);
}

$token = clean_input($_GET['token'] ?? '');

if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404);
    exit('Payment approval link is invalid.');
}

function generate_referral_voucher_code(PDO $pdo): string
{
    for ($i = 0; $i < 12; $i++) {
        $candidate = 'ALM-PREM-' . strtoupper(bin2hex(random_bytes(3)));
        $stmt = $pdo->prepare('SELECT id FROM referral_vouchers WHERE voucher_code = ? LIMIT 1');
        $stmt->execute([$candidate]);

        if (!$stmt->fetchColumn()) {
            return $candidate;
        }
    }

    return 'ALM-PREM-' . strtoupper(bin2hex(random_bytes(4)));
}

function send_referral_voucher_email(array $user, string $voucherCode): bool
{
    $autoloadPath = __DIR__ . '/vendor/autoload.php';

    if (!is_file($autoloadPath)) {
        error_log('PHPMailer is not installed. Run composer install before referral voucher emails.');
        return false;
    }

    require_once $autoloadPath;

    $subject = 'Your AlaalaMo Premium voucher is ready';
    $plainText = "Congratulations.\n\n"
        . "You have earned a free Premium voucher from the AlaalaMo referral promo.\n"
        . "Voucher code: {$voucherCode}\n\n"
        . "Log in to your dashboard, select an unpaid memorial, and use the Activate Account using Voucher option.";
    $html = '
        <div style="font-family: Arial, sans-serif; max-width: 620px; margin: 0 auto; color: #1f2933;">
            <h1 style="color:#214c63; margin-bottom: 10px;">You earned a free Premium voucher</h1>
            <p style="line-height:1.65;">Thank you for growing AlaalaMo. You completed the referral requirement and can now activate one memorial as Premium using the voucher below.</p>
            <p style="font-size:28px; font-weight:800; letter-spacing:2px; color:#214c63; background:#f5eee4; padding:18px 20px; border-radius:8px; text-align:center;">' . htmlspecialchars($voucherCode, ENT_QUOTES, 'UTF-8') . '</p>
            <p style="line-height:1.65;">Log in to your dashboard, select an unpaid memorial, then use <strong>Activate Account using Voucher</strong>.</p>
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
        $mail->addAddress((string) ($user['email'] ?? ''));
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = $plainText;

        return $mail->send();
    } catch (MailException $exception) {
        error_log('Referral voucher email failed: ' . $exception->getMessage());
        return false;
    }
}

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT m.*, u.email, u.given_name, u.last_name, u.referred_by_user_id, u.referral_paid_qualified_at, qg.id AS qr_group_id, qg.payment_status AS qr_group_payment_status
     FROM memorials m
     INNER JOIN users u ON u.id = m.user_id
     LEFT JOIN qr_groups qg ON qg.id = m.qr_group_id
     WHERE m.payment_approval_token = ?
     LIMIT 1'
);
$stmt->execute([$token]);
$memorial = $stmt->fetch();

if (!$memorial) {
    http_response_code(404);
    exit('Payment approval link was not found.');
}

$pdo->beginTransaction();

try {
    $firstMemorialStmt = $pdo->prepare('SELECT MIN(id) FROM memorials WHERE qr_group_id = ?');
    $firstMemorialStmt->execute([(int) $memorial['qr_group_id']]);
    $mainMemorialId = (int) $firstMemorialStmt->fetchColumn();
    $isMainMemorial = $mainMemorialId > 0 && $mainMemorialId === (int) $memorial['id'];

    $pdo->prepare(
        'UPDATE memorials
         SET payment_status = "paid", paid_at = COALESCE(paid_at, NOW())
         WHERE id = ?'
    )->execute([(int) $memorial['id']]);

    if (
        $isMainMemorial
        && ($memorial['plan_type'] ?? 'regular') === 'regular'
        && empty($memorial['early_bird_upgraded_at'])
    ) {
        $earlyBirdCountStmt = $pdo->query('SELECT COUNT(*) FROM memorials WHERE early_bird_upgraded_at IS NOT NULL');
        $earlyBirdAwardedCount = (int) $earlyBirdCountStmt->fetchColumn();

        if ($earlyBirdAwardedCount < EARLY_BIRD_REGULAR_UPGRADE_LIMIT) {
            $pdo->prepare(
                'UPDATE memorials
                 SET plan_type = "premium", early_bird_upgraded_at = NOW(), early_bird_notice_shown_at = NULL
                 WHERE id = ?'
            )->execute([(int) $memorial['id']]);
            $memorial['plan_type'] = 'premium';
            $memorial['early_bird_upgraded_at'] = date('Y-m-d H:i:s');
        }
    }

    if (!empty($memorial['qr_group_id'])) {
        $pdo->prepare(
            'UPDATE qr_groups
             SET payment_status = "paid", paid_at = COALESCE(paid_at, NOW())
             WHERE id = ? AND payment_status <> "paid"'
        )->execute([(int) $memorial['qr_group_id']]);
    }

    if (!empty($memorial['referred_by_user_id']) && empty($memorial['referral_paid_qualified_at'])) {
        $qualificationStmt = $pdo->prepare(
            'UPDATE users
             SET referral_paid_qualified_at = COALESCE(referral_paid_qualified_at, NOW())
             WHERE id = ? AND referred_by_user_id IS NOT NULL AND referral_paid_qualified_at IS NULL'
        );
        $qualificationStmt->execute([(int) $memorial['user_id']]);

        $referrerId = (int) $memorial['referred_by_user_id'];
        $qualifiedCountStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM users WHERE referred_by_user_id = ? AND referral_paid_qualified_at IS NOT NULL'
        );
        $qualifiedCountStmt->execute([$referrerId]);
        $qualifiedCount = (int) $qualifiedCountStmt->fetchColumn();

        $issuedCountStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM referral_vouchers WHERE user_id = ? AND reward_type = "premium_memorial"'
        );
        $issuedCountStmt->execute([$referrerId]);
        $issuedCount = (int) $issuedCountStmt->fetchColumn();
        $deservedCount = intdiv($qualifiedCount, 5);

        if ($deservedCount > $issuedCount) {
            $referrerStmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $referrerStmt->execute([$referrerId]);
            $referrer = $referrerStmt->fetch();

            for ($i = $issuedCount; $i < $deservedCount; $i++) {
                $voucherCode = generate_referral_voucher_code($pdo);
                $emailedAt = null;

                if ($referrer && !empty($referrer['email'])) {
                    $emailedAt = send_referral_voucher_email($referrer, $voucherCode) ? date('Y-m-d H:i:s') : null;
                }

                $pdo->prepare(
                    'INSERT INTO referral_vouchers (user_id, voucher_code, reward_type, earned_for_referral_count, emailed_at)
                     VALUES (?, ?, "premium_memorial", ?, ?)'
                )->execute([$referrerId, $voucherCode, ($i + 1) * 5, $emailedAt]);
            }
        }
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    throw $exception;
}

?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment Approved | AlaalaMo</title>
    <link rel="stylesheet" href="styles.css?v=<?= urlencode((defined('ASSET_VERSION') ? ASSET_VERSION . '-' : '') . (string) (file_exists(__DIR__ . '/styles.css') ? filemtime(__DIR__ . '/styles.css') : time())) ?>">
  </head>
  <body class="auth-page">
    <main class="auth-card">
      <a class="brand auth-brand" href="/">
        <span class="brand-mark" aria-hidden="true"><img class="brand-mark-image" src="assets/alaalamo-logo-mark.png?v=<?= urlencode((string) (file_exists(__DIR__ . '/assets/alaalamo-logo-mark.png') ? filemtime(__DIR__ . '/assets/alaalamo-logo-mark.png') : time())) ?>" alt=""></span>
        <span class="brand-highlight">AlaalaMo</span>
      </a>
      <h1>Payment approved</h1>
      <p><?= htmlspecialchars(($memorial['given_name'] ?? '') . ' ' . ($memorial['last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?> can now publish <?= htmlspecialchars((string) $memorial['loved_one_name'], ENT_QUOTES, 'UTF-8') ?>.</p>
      <p class="auth-alert auth-alert-success">Memorial activated successfully.</p>
    </main>
  </body>
</html>
