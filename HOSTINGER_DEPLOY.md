# Hostinger Deployment Notes

## 1. Create the database

Create a MySQL database and user in Hostinger hPanel, then import `database.sql`.

Update `config.php`:

```php
const DB_HOST = 'localhost';
const DB_NAME = 'your_hostinger_database_name';
const DB_USER = 'your_hostinger_database_user';
const DB_PASS = 'your_hostinger_database_password';
const APP_URL = 'https://yourdomain.com';
```

## 2. Create the sender email

In hPanel, create an email account such as:

```text
no-reply@yourdomain.com
```

Update `config.php`:

```php
const MAIL_FROM = 'no-reply@yourdomain.com';
const SMTP_USERNAME = 'no-reply@yourdomain.com';
const SMTP_PASSWORD = 'the email account password';
```

Hostinger SMTP defaults:

```text
SMTP host: smtp.hostinger.com
SSL port: 465
Encryption: ssl
```

If SSL has issues, use:

```php
const SMTP_PORT = 587;
const SMTP_ENCRYPTION = 'tls';
```

## 3. Install PHPMailer

On Hostinger, run Composer in the project folder:

```bash
composer install --no-dev --optimize-autoloader
```

This creates `vendor/autoload.php`, which the OTP mailer uses.

## 4. Test flow

1. Open `/`.
2. Register with last name, given name, and email.
3. Check email for OTP.
4. Verify email. OTPs expire in 2 minutes.
5. Log in with email OTP.
6. Confirm redirect to `dashboard.php`.
7. Fill up the memorial profile and milestones.
8. Save to generate the token-based QR preview.

## QR preview security

The memorial preview uses an unguessable token URL:

```text
memorial.php?t=<64-character-token>
```

The page is marked:

```html
<meta name="robots" content="noindex,nofollow">
```

This keeps it away from normal search indexing and prevents public browsing/listing.
Important: a QR code still resolves to a URL, so anyone who receives the QR URL can open it. Stronger access control would require an additional PIN, logged-in viewer, or expiring viewer token.

