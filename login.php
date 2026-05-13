<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

$flash = get_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(clean_input($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Please enter a valid email address.');
        redirect_to('/login.php');
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND email_verified_at IS NOT NULL LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        flash('error', 'No verified account found for that email.');
        redirect_to('/login.php');
    }

    $otp = create_otp((int) $user['id'], 'login');

    if (!send_otp_email($email, $otp, 'login')) {
        flash('error', 'The login OTP could not be sent. Please check Hostinger SMTP and PHPMailer setup.');
        redirect_to('/login.php');
    }

    flash('success', 'We sent a login OTP to your email.');
    redirect_to('/login-verify.php?email=' . urlencode($email));
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | AlaalaMo</title>
    <link rel="stylesheet" href="styles.css?v=<?= urlencode(defined('ASSET_VERSION') ? ASSET_VERSION : '20260513-1') ?>">
  </head>
  <body class="auth-page">
    <main class="auth-card">
      <a class="brand auth-brand" href="/">
        <span class="brand-mark" aria-hidden="true">A</span>
        <span class="brand-highlight">AlaalaMo</span>
      </a>
      <h1>Login with email OTP</h1>
      <p>Enter your verified email address and we will send a login OTP.</p>
      <?php if ($flash): ?>
        <p class="auth-alert auth-alert-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>">
          <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?>
        </p>
      <?php endif; ?>
      <form class="auth-form" method="post" action="login.php">
        <label>
          Email address
          <input type="email" name="email" autocomplete="email" required>
        </label>
        <button class="button-primary" type="submit">Send Login OTP</button>
      </form>
      <a class="auth-link" href="/#signup">Create an account</a>
    </main>
  </body>
</html>

