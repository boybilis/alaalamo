<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

$email = strtolower(clean_input($_GET['email'] ?? $_POST['email'] ?? ''));
$flash = get_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp = clean_input($_POST['otp'] ?? '');

    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || $otp === '') {
        flash('error', 'Invalid email or OTP.');
        redirect_to('/verify.php?email=' . urlencode($email));
    }

    $stmt = db()->prepare(
        'SELECT * FROM email_otps
         WHERE user_id = ? AND purpose = "registration" AND consumed_at IS NULL
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([(int) $user['id']]);
    $otpRecord = $stmt->fetch();

    if (!$otpRecord || new DateTimeImmutable($otpRecord['expires_at']) < new DateTimeImmutable()) {
        flash('error', 'Your OTP has expired. Please register again to request a new OTP.');
        redirect_to('/verify.php?email=' . urlencode($email));
    }

    if ((int) $otpRecord['attempts'] >= 5 || !password_verify($otp, $otpRecord['otp_hash'])) {
        db()->prepare('UPDATE email_otps SET attempts = attempts + 1 WHERE id = ?')
            ->execute([(int) $otpRecord['id']]);
        flash('error', 'The OTP you entered is incorrect.');
        redirect_to('/verify.php?email=' . urlencode($email));
    }

    db()->prepare('UPDATE email_otps SET consumed_at = NOW() WHERE id = ?')
        ->execute([(int) $otpRecord['id']]);
    db()->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?')
        ->execute([(int) $user['id']]);

    flash('success', 'Your email is verified. You can now log in.');
    redirect_to('/login.php');
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify Email | AlaalaMo</title>
    <link rel="stylesheet" href="styles.css?v=<?= urlencode(defined('ASSET_VERSION') ? ASSET_VERSION : '20260513-34') ?>">
  </head>
  <body class="auth-page">
    <main class="auth-card">
      <a class="brand auth-brand" href="/">
        <span class="brand-mark" aria-hidden="true">A</span>
        <span class="brand-highlight">AlaalaMo</span>
      </a>
      <h1>Verify your email</h1>
      <p>Enter the 6-digit OTP we sent to <?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>.</p>
      <?php if ($flash): ?>
        <p class="auth-alert auth-alert-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>">
          <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?>
        </p>
      <?php endif; ?>
      <form class="auth-form" method="post" action="verify.php">
        <input type="hidden" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>">
        <label>
          Email OTP
          <input type="text" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
        </label>
        <button class="button-primary" type="submit">Verify Email</button>
      </form>
      <a class="auth-link" href="/#signup">Use another email</a>
    </main>
  </body>
</html>

