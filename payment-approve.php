<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

$token = clean_input($_GET['token'] ?? '');

if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404);
    exit('Payment approval link is invalid.');
}

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT qg.*, u.email, u.given_name, u.last_name
     FROM qr_groups qg
     INNER JOIN users u ON u.id = qg.user_id
     WHERE qg.payment_approval_token = ?
     LIMIT 1'
);
$stmt->execute([$token]);
$qrGroup = $stmt->fetch();

if (!$qrGroup) {
    http_response_code(404);
    exit('Payment approval link was not found.');
}

$pdo->prepare(
    'UPDATE qr_groups
     SET payment_status = "paid", paid_at = COALESCE(paid_at, NOW())
     WHERE id = ?'
)->execute([(int) $qrGroup['id']]);

?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment Approved | AlaalaMo</title>
    <link rel="stylesheet" href="styles.css?v=<?= urlencode(defined('ASSET_VERSION') ? ASSET_VERSION : '20260515-01') ?>">
  </head>
  <body class="auth-page">
    <main class="auth-card">
      <a class="brand auth-brand" href="/">
        <span class="brand-mark" aria-hidden="true">A</span>
        <span class="brand-highlight">AlaalaMo</span>
      </a>
      <h1>Payment approved</h1>
      <p><?= htmlspecialchars(($qrGroup['given_name'] ?? '') . ' ' . ($qrGroup['last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?> can now access the AlaalaMo dashboard.</p>
      <p class="auth-alert auth-alert-success">Account activated successfully.</p>
    </main>
  </body>
</html>
