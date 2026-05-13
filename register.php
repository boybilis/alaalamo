<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('/#signup');
}

$lastName = clean_input($_POST['last_name'] ?? '');
$givenName = clean_input($_POST['given_name'] ?? '');
$email = strtolower(clean_input($_POST['email'] ?? ''));
$selectedPlan = clean_input($_POST['plan_type'] ?? 'regular');
$selectedPlan = $selectedPlan === 'premium' ? 'premium' : 'regular';

if ($lastName === '' || $givenName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('error', 'Please enter your last name, given name, and a valid email address.');
    redirect_to('/#signup');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();

if ($user && $user['email_verified_at'] !== null) {
    flash('info', 'This email is already verified. Please log in with your email OTP.');
    redirect_to('/login.php');
}

if ($user) {
    $pdo->prepare('UPDATE users SET last_name = ?, given_name = ? WHERE id = ?')
        ->execute([$lastName, $givenName, $user['id']]);
    $userId = (int) $user['id'];
} else {
    $pdo->prepare('INSERT INTO users (last_name, given_name, email) VALUES (?, ?, ?)')
        ->execute([$lastName, $givenName, $email]);
    $userId = (int) $pdo->lastInsertId();
}

$stmt = $pdo->prepare('SELECT id FROM qr_groups WHERE user_id = ? ORDER BY id ASC LIMIT 1');
$stmt->execute([$userId]);
$qrGroupId = $stmt->fetchColumn();

if ($qrGroupId) {
    $pdo->prepare('UPDATE qr_groups SET plan_type = ? WHERE id = ?')
        ->execute([$selectedPlan, (int) $qrGroupId]);
} else {
    $pdo->prepare('INSERT INTO qr_groups (user_id, public_token, plan_type) VALUES (?, ?, ?)')
        ->execute([$userId, generate_token(), $selectedPlan]);
}

$otp = create_otp($userId, 'registration');

if (!send_otp_email($email, $otp, 'registration')) {
    flash('error', 'We saved your registration, but the OTP email could not be sent. Please check Hostinger SMTP and PHPMailer setup.');
    redirect_to('/verify.php?email=' . urlencode($email));
}

flash('success', 'We sent a verification OTP to your email.');
redirect_to('/verify.php?email=' . urlencode($email));

