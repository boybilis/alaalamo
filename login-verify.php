<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

start_app_session();
$email = strtolower(clean_input($_GET['email'] ?? $_POST['email'] ?? ''));
$flash = get_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp = clean_input($_POST['otp'] ?? '');

    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND email_verified_at IS NOT NULL LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || $otp === '') {
        flash('error', 'Invalid email or OTP.');
        redirect_to('/login-verify.php?email=' . urlencode($email));
    }

    $stmt = db()->prepare(
        'SELECT * FROM email_otps
         WHERE user_id = ? AND purpose = "login" AND consumed_at IS NULL
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([(int) $user['id']]);
    $otpRecord = $stmt->fetch();

    if (!$otpRecord || new DateTimeImmutable($otpRecord['expires_at']) < new DateTimeImmutable()) {
        flash('error', 'Your login OTP has expired. Please request a new one.');
        redirect_to('/login.php');
    }

    if ((int) $otpRecord['attempts'] >= 5 || !password_verify($otp, $otpRecord['otp_hash'])) {
        db()->prepare('UPDATE email_otps SET attempts = attempts + 1 WHERE id = ?')
            ->execute([(int) $otpRecord['id']]);
        flash('error', 'The OTP you entered is incorrect.');
        redirect_to('/login-verify.php?email=' . urlencode($email));
    }

    db()->prepare('UPDATE email_otps SET consumed_at = NOW() WHERE id = ?')
        ->execute([(int) $otpRecord['id']]);

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_email'] = $user['email'];

    redirect_to('/dashboard.php');
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify Login | AlaalaMo</title>
    <link rel="stylesheet" href="styles.css?v=<?= urlencode(defined('ASSET_VERSION') ? ASSET_VERSION : '20260514-44') ?>">
  </head>
  <body class="auth-page">
    <main class="auth-card">
      <a class="brand auth-brand" href="/">
        <span class="brand-mark" aria-hidden="true">A</span>
        <span class="brand-highlight">AlaalaMo</span>
      </a>
      <h1>Enter login OTP</h1>
      <p>Enter the 6-digit OTP we sent to <?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>.</p>
      <?php if ($flash): ?>
        <p class="auth-alert auth-alert-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>">
          <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?>
        </p>
      <?php endif; ?>
      <form class="auth-form" method="post" action="login-verify.php">
        <input type="hidden" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>">
        <label>
          Login OTP
          <input type="text" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
        </label>
        <button class="button-primary" type="submit">Continue to Dashboard</button>
      </form>
      <a class="auth-link" href="/login.php">Request a new OTP</a>
    </main>
  </body>
</html>

