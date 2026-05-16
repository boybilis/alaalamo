<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

if (!function_exists('normalize_referral_code')) {
    function normalize_referral_code(string $value): string
    {
        $value = strtoupper(trim($value));

        return preg_replace('/[^A-Z0-9\-]/', '', $value) ?? '';
    }
}

if (!function_exists('generate_unique_user_referral_code')) {
    function generate_unique_user_referral_code(PDO $pdo): string
    {
        for ($i = 0; $i < 12; $i++) {
            $candidate = 'ALM' . strtoupper(bin2hex(random_bytes(3)));
            $stmt = $pdo->prepare('SELECT id FROM users WHERE referral_code = ? LIMIT 1');
            $stmt->execute([$candidate]);

            if (!$stmt->fetchColumn()) {
                return $candidate;
            }
        }

        return 'ALM' . strtoupper(bin2hex(random_bytes(4)));
    }
}

if (!function_exists('ensure_user_referral_code')) {
    function ensure_user_referral_code(PDO $pdo, int $userId, ?string $existingCode = null): string
    {
        $existingCode = normalize_referral_code((string) $existingCode);

        if ($existingCode !== '') {
            return $existingCode;
        }

        $code = generate_unique_user_referral_code($pdo);
        $pdo->prepare('UPDATE users SET referral_code = ? WHERE id = ?')
            ->execute([$code, $userId]);

        return $code;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('/#signup');
}

$lastName = clean_input($_POST['last_name'] ?? '');
$givenName = clean_input($_POST['given_name'] ?? '');
$email = strtolower(clean_input($_POST['email'] ?? ''));
$referralCodeInput = normalize_referral_code((string) ($_POST['referral_code'] ?? ''));
$selectedPlan = clean_input($_POST['plan_type'] ?? 'regular');
$selectedPlan = $selectedPlan === 'premium' ? 'premium' : 'regular';

if ($lastName === '' || $givenName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('error', 'Please enter your last name, given name, and a valid email address.');
    redirect_to('/#signup');
}

$pdo = db();
$referrer = null;

if ($referralCodeInput !== '') {
    $stmt = $pdo->prepare('SELECT id, referral_code, given_name, last_name FROM users WHERE referral_code = ? LIMIT 1');
    $stmt->execute([$referralCodeInput]);
    $referrer = $stmt->fetch();

    if (!$referrer) {
        flash('error', 'The referral code you entered was not found. Please check it and try again.');
        redirect_to('/#signup');
    }
}

$stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();

if ($user && $user['email_verified_at'] !== null) {
    flash('info', 'This email is already verified. Please log in with your email OTP.');
    redirect_to('/login.php');
}

if ($user) {
    $userId = (int) $user['id'];
    $ownReferralCode = ensure_user_referral_code($pdo, $userId, $user['referral_code'] ?? null);

    if ($referrer && (int) $referrer['id'] === $userId) {
        flash('error', 'You cannot use your own referral code for this account.');
        redirect_to('/#signup');
    }

    $referredByUserId = !empty($user['referred_by_user_id']) ? (int) $user['referred_by_user_id'] : null;
    $referredByCode = !empty($user['referred_by_code']) ? (string) $user['referred_by_code'] : null;

    if ($referredByUserId === null && $referrer) {
        $referredByUserId = (int) $referrer['id'];
        $referredByCode = (string) $referrer['referral_code'];
    }

    $pdo->prepare('UPDATE users SET last_name = ?, given_name = ?, referral_code = ?, referred_by_user_id = ?, referred_by_code = ? WHERE id = ?')
        ->execute([$lastName, $givenName, $ownReferralCode, $referredByUserId, $referredByCode, $userId]);
} else {
    $referralCode = generate_unique_user_referral_code($pdo);
    $pdo->prepare(
        'INSERT INTO users (last_name, given_name, email, referral_code, referred_by_user_id, referred_by_code) VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        $lastName,
        $givenName,
        $email,
        $referralCode,
        $referrer ? (int) $referrer['id'] : null,
        $referrer ? (string) $referrer['referral_code'] : null,
    ]);
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

