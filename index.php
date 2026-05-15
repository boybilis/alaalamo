<?php
require_once __DIR__ . '/config.php';

$pageTitle = 'AlaalaMo | Digital Memorial Space';
$pageDescription = "A QR memorial to preserve the faces, stories, and memories that should not fade with time.";
$navItems = [
    ['label' => 'What it does', 'href' => '#what-it-does'],
    ['label' => 'QR Demo', 'href' => '#qr-demo'],
    ['label' => 'Features', 'href' => '#features'],
    ['label' => 'Pricing', 'href' => '#pricing'],
    ['label' => 'Register', 'href' => '#signup'],
];
$introItems = [
    [
        'title' => 'Beyond the stone',
        'icon' => 'fa-solid fa-monument',
        'body' => 'A name marks where they rest. A memorial lets the next generation know who they were.',
    ],
    [
        'title' => 'Faces they can remember',
        'icon' => 'fa-solid fa-images',
        'body' => 'Share real photos, life details, and stories so Lolo, Lola, and loved ones are seen, not only named.',
    ],
    [
        'title' => 'Access by QR only',
        'icon' => 'fa-solid fa-qrcode',
        'body' => 'Each tribute opens through its QR code, private from public search and easy for family to revisit.',
    ],
];
$memorialFeatures = [
    [
        'title' => 'Memorial profile',
        'label' => 'Identity',
        'icon' => 'fa-solid fa-id-card-clip',
        'body' => 'Show their name, photo, dates, resting place, and the details family members need to remember them clearly.',
    ],
    [
        'title' => 'Photos and gallery',
        'label' => 'Images',
        'icon' => 'fa-solid fa-images',
        'body' => 'Let visitors see real faces and moments, from a few chosen photos to a fuller gallery for richer memorials.',
    ],
    [
        'title' => 'Life milestones',
        'label' => 'Timeline',
        'icon' => 'fa-solid fa-timeline',
        'body' => 'Capture important chapters like childhood, family, work, service, achievements, and the moments they were known for.',
    ],
    [
        'title' => 'Tribute messages',
        'label' => 'Family voices',
        'icon' => 'fa-solid fa-message',
        'body' => 'Create space for family and friends to leave memories, prayers, and short messages that can be revisited anytime.',
    ],
    [
        'title' => 'Guided story form',
        'label' => 'Easy setup',
        'icon' => 'fa-solid fa-pen-nib',
        'body' => 'Use a gentle step-by-step form so families can provide details without feeling overwhelmed.',
    ],
    [
        'title' => 'Private QR access',
        'label' => 'QR only',
        'icon' => 'fa-solid fa-lock',
        'body' => 'Open the memorial through its QR code, keeping it easy to share in person and away from public web search.',
    ],
];
$pricingPlans = [
    [
        'name' => 'Regular',
        'price' => 'PHP 599',
        'term' => 'per year',
        'label' => 'Starter',
        'description' => 'For families who want a simple QR memorial with the essential details.',
        'features' => [
            'QR-access memorial page',
            'Memorial details and service information',
            'A small set of selected photos',
            'Additional standard memorials at PHP 399 each',
            'Private from public web search',
        ],
    ],
    [
        'name' => 'Premium',
        'price' => 'PHP 999',
        'term' => 'per year',
        'label' => 'More memories',
        'description' => 'For families who want to preserve a fuller story through photos, milestones, and guided details.',
        'features' => [
            'Everything in Regular',
            'Gallery with up to 20 images',
            'Guided milestones from the form',
            'Biography shaped from the memories provided',
            'Additional premium memorials at PHP 700 each',
        ],
        'featured' => true,
    ],
];

function demo_svg_data_uri(string $variant): string
{
    $svg = '';

    if ($variant === 'portrait') {
        $svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 720 900">
  <defs>
    <linearGradient id="bg" x1="0" x2="1" y1="0" y2="1">
      <stop offset="0%" stop-color="#f4ede2"/>
      <stop offset="100%" stop-color="#d7e6f0"/>
    </linearGradient>
    <linearGradient id="shirt" x1="0" x2="1" y1="0" y2="1">
      <stop offset="0%" stop-color="#355f8f"/>
      <stop offset="100%" stop-color="#173f68"/>
    </linearGradient>
  </defs>
  <rect width="720" height="900" fill="url(#bg)"/>
  <circle cx="360" cy="260" r="110" fill="#3b322f"/>
  <circle cx="360" cy="300" r="118" fill="#d7b49d"/>
  <rect x="232" y="428" width="256" height="330" rx="124" fill="url(#shirt)"/>
  <rect x="170" y="500" width="380" height="250" rx="54" fill="url(#shirt)"/>
  <rect x="298" y="392" width="124" height="88" rx="42" fill="#cfab92"/>
  <circle cx="318" cy="290" r="14" fill="#2b2523"/>
  <circle cx="404" cy="290" r="14" fill="#2b2523"/>
  <path d="M309 350c28 18 75 18 103 0" stroke="#6f4338" stroke-width="12" fill="none" stroke-linecap="round"/>
  <path d="M236 220c28-92 207-93 245 0-8-83-74-150-122-150-49 0-116 69-123 150Z" fill="#2f2b2a"/>
</svg>
SVG;
    } elseif ($variant === 'teacher') {
        $svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 720 460">
  <defs>
    <linearGradient id="wall" x1="0" x2="1" y1="0" y2="1">
      <stop offset="0%" stop-color="#f5efe4"/>
      <stop offset="100%" stop-color="#ddebf4"/>
    </linearGradient>
  </defs>
  <rect width="720" height="460" fill="url(#wall)"/>
  <rect x="60" y="70" width="600" height="220" rx="18" fill="#1f4a68"/>
  <rect x="84" y="94" width="552" height="172" rx="12" fill="#f7fafc"/>
  <rect x="110" y="118" width="210" height="18" rx="9" fill="#d8e3ee"/>
  <rect x="110" y="150" width="280" height="14" rx="7" fill="#e4ecf3"/>
  <rect x="110" y="178" width="240" height="14" rx="7" fill="#e4ecf3"/>
  <rect x="110" y="206" width="198" height="14" rx="7" fill="#e4ecf3"/>
  <circle cx="510" cy="224" r="70" fill="#355f8f"/>
  <circle cx="510" cy="204" r="46" fill="#d8b198"/>
  <rect x="464" y="252" width="92" height="110" rx="42" fill="#24486d"/>
  <rect x="40" y="330" width="640" height="76" rx="22" fill="#c59f65"/>
  <rect x="122" y="347" width="88" height="44" rx="12" fill="#f9f4ed"/>
  <rect x="244" y="347" width="88" height="44" rx="12" fill="#f9f4ed"/>
  <rect x="366" y="347" width="88" height="44" rx="12" fill="#f9f4ed"/>
</svg>
SVG;
    } else {
        $svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 720 460">
  <defs>
    <linearGradient id="sky" x1="0" x2="1" y1="0" y2="1">
      <stop offset="0%" stop-color="#f3ebdf"/>
      <stop offset="100%" stop-color="#d8e8f0"/>
    </linearGradient>
    <linearGradient id="grass" x1="0" x2="1" y1="0" y2="0">
      <stop offset="0%" stop-color="#6c8f62"/>
      <stop offset="100%" stop-color="#8fb07b"/>
    </linearGradient>
  </defs>
  <rect width="720" height="460" fill="url(#sky)"/>
  <rect y="318" width="720" height="142" fill="url(#grass)"/>
  <circle cx="220" cy="165" r="64" fill="#d9b49b"/>
  <rect x="170" y="228" width="104" height="116" rx="46" fill="#355f8f"/>
  <circle cx="356" cy="176" r="56" fill="#d7af95"/>
  <rect x="312" y="230" width="92" height="108" rx="42" fill="#274b6f"/>
  <circle cx="498" cy="168" r="58" fill="#d8b59d"/>
  <rect x="452" y="228" width="98" height="116" rx="44" fill="#8f613f"/>
  <path d="M184 156c10-52 69-78 106-32-13-68-92-89-119-27 1 17 6 34 13 59Z" fill="#2e2c2c"/>
  <path d="M322 170c12-49 62-72 92-27-7-60-69-81-100-29 0 15 3 31 8 56Z" fill="#2e2c2c"/>
  <path d="M465 163c12-48 61-70 91-25-6-57-66-77-97-28 0 15 2 30 6 53Z" fill="#2e2c2c"/>
</svg>
SVG;
    }

    return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode($svg);
}

$demoMemorial = [
    'name' => 'John Doe',
    'dates' => 'March 14, 1954 - September 22, 2023',
    'quote' => 'A beloved father, teacher, and storyteller whose quiet kindness shaped generations.',
    'resting_place' => 'San Miguel Memorial Park, Sample City',
    'profile_image' => demo_svg_data_uri('portrait'),
    'milestones' => [
        [
            'title' => 'A Life of Teaching',
            'date' => '1978 - 2008',
            'body' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Praesent vitae nisl at erat varius volutpat. Donec id sem eu ligula feugiat posuere.',
            'image' => demo_svg_data_uri('teacher'),
        ],
        [
            'title' => 'Family Gatherings and Sundays',
            'date' => '1985 - 2019',
            'body' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Vivamus vel enim non ligula posuere finibus. Integer luctus erat in erat viverra, eu feugiat risus vulputate.',
            'image' => demo_svg_data_uri('family'),
        ],
    ],
];
$registrationFlash = get_flash();
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
      href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Lora:wght@500;600&display=swap"
      rel="stylesheet"
    >
    <link
      rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css"
    >
    <link rel="stylesheet" href="styles.css?v=<?= urlencode((defined('ASSET_VERSION') ? ASSET_VERSION . '-' : '') . (string) (file_exists(__DIR__ . '/styles.css') ? filemtime(__DIR__ . '/styles.css') : time())) ?>">
  </head>
  <body>
    <header class="site-header" aria-label="Main navigation">
      <a class="brand" href="/">
        <span class="brand-mark" aria-hidden="true"><img class="brand-mark-image" src="assets/alaalamo-logo-mark.png?v=<?= urlencode((string) (file_exists(__DIR__ . '/assets/alaalamo-logo-mark.png') ? filemtime(__DIR__ . '/assets/alaalamo-logo-mark.png') : time())) ?>" alt=""></span>
        <span class="brand-highlight">AlaalaMo</span>
      </a>
      <button class="menu-toggle" type="button" aria-label="Open menu" aria-controls="primary-navigation" aria-expanded="false">
        <i class="fa-solid fa-bars" aria-hidden="true"></i>
      </button>
      <nav class="nav-links" id="primary-navigation" aria-label="Primary">
        <?php foreach ($navItems as $item): ?>
          <a href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
          </a>
        <?php endforeach; ?>
        <a class="nav-login" href="login.php"><i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i> Login</a>
      </nav>
    </header>

    <main>
      <section class="hero" aria-labelledby="hero-title">
        <div class="hero-media" aria-hidden="true"></div>
        <div class="hero-overlay"></div>
        <div class="hero-content">
          <p class="eyebrow">Private QR memorial pages</p>
          <h1 id="hero-title">A QR Memorial to preserve the faces, stories, and memories that should not fade with time.</h1>
          <p class="hero-copy">
            <span class="brand-highlight">AlaalaMo</span> gives families a private digital memorial with real
            photos, life details, and tributes that can be opened by scanning a
            QR code at the resting place.
          </p>
          <div class="hero-actions">
            <a class="button-primary" href="#signup"><i class="fa-solid fa-qrcode" aria-hidden="true"></i> Create a Lasting Memorial</a>
            <a class="button-secondary" href="#what-it-does"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Learn more</a>
          </div>
          <p class="privacy-note">Not searchable on the web. Shared only through its memorial QR code.</p>
        </div>
      </section>

      <section class="what-section" id="what-it-does" aria-labelledby="what-title">
        <div class="what-background" aria-hidden="true"></div>
        <div class="what-content">
          <p class="section-eyebrow">What <span class="brand-highlight">AlaalaMo</span> does</p>
          <h2 id="what-title">Let them see the life behind the name.</h2>
          <p class="section-quote">
            Flowers fade and stone can only say so much. <span class="brand-highlight">AlaalaMo</span> turns a QR
            code into a quiet doorway where children, grandchildren, and family
            can see the face, story, and memories of someone dearly loved.
          </p>
          <div class="intro-strip" aria-label="How AlaalaMo works">
            <?php foreach ($introItems as $item): ?>
              <div>
                <i class="<?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                <span><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></span>
                <p><?= htmlspecialchars($item['body'], ENT_QUOTES, 'UTF-8') ?></p>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <section class="features-section" id="features" aria-labelledby="features-title">
        <div class="features-content">
          <div class="section-heading">
            <p class="section-eyebrow">Features</p>
            <h2 id="features-title">A memorial page made to be opened at the moment of remembrance.</h2>
            <p class="section-quote">
              The webapp brings together the details, photos, stories, and
              messages that help family members know the person behind the name.
            </p>
          </div>

          <div class="feature-grid">
            <article class="feature-card feature-card-main">
              <span class="feature-kicker">Mobile-first webapp</span>
              <i class="feature-icon fa-solid fa-mobile-screen-button" aria-hidden="true"></i>
              <h3>Scan the QR. Open the memory.</h3>
              <p>
                <span class="brand-highlight">AlaalaMo</span> memorials are made for quick,
                respectful viewing on mobile phones. Families can share a QR
                code placed on a marker, keepsake, card, or printed material.
              </p>
            </article>

            <?php foreach ($memorialFeatures as $feature): ?>
              <article class="feature-card">
                <span class="feature-kicker"><?= htmlspecialchars($feature['label'], ENT_QUOTES, 'UTF-8') ?></span>
                <i class="feature-icon <?= htmlspecialchars($feature['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                <h3><?= htmlspecialchars($feature['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                <p><?= htmlspecialchars($feature['body'], ENT_QUOTES, 'UTF-8') ?></p>
              </article>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <section class="demo-section" id="qr-demo" aria-labelledby="demo-title">
        <div class="demo-content">
          <div class="section-heading">
            <p class="section-eyebrow">QR demo</p>
            <h2 id="demo-title">Show families what a finished memorial can feel like.</h2>
            <p class="section-quote">
              Use this sample memorial in your pitch: a QR on one side, and a mobile memorial preview on the other,
              showing how a loved one can be remembered through photos, milestones, and a life summary.
            </p>
          </div>

          <div class="demo-layout">
            <aside class="demo-qr-card">
              <p class="demo-qr-label">Sample memorial QR</p>
              <div class="demo-qr-code" aria-hidden="true">
                <?php for ($i = 0; $i < 100; $i++): ?>
                  <span class="<?= in_array($i % 10, [0, 1, 8, 9], true) && $i < 20 ? 'is-filled is-corner' : (($i + intdiv($i, 3)) % 3 === 0 ? 'is-filled' : '') ?>"></span>
                <?php endfor; ?>
              </div>
              <strong><?= htmlspecialchars($demoMemorial['name'], ENT_QUOTES, 'UTF-8') ?></strong>
              <p><?= htmlspecialchars($demoMemorial['resting_place'], ENT_QUOTES, 'UTF-8') ?></p>
              <small>Private QR-only sample for client presentation</small>
            </aside>

            <article class="demo-phone">
              <div class="demo-phone-hero">
                <img src="<?= htmlspecialchars($demoMemorial['profile_image'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($demoMemorial['name'], ENT_QUOTES, 'UTF-8') ?>">
                <div class="demo-phone-overlay"></div>
                <div class="demo-phone-copy">
                  <p class="demo-phone-kicker">In loving memory</p>
                  <h3><?= htmlspecialchars($demoMemorial['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                  <p class="demo-phone-dates"><?= htmlspecialchars($demoMemorial['dates'], ENT_QUOTES, 'UTF-8') ?></p>
                  <blockquote><?= htmlspecialchars($demoMemorial['quote'], ENT_QUOTES, 'UTF-8') ?></blockquote>
                </div>
              </div>

              <div class="demo-phone-body">
                <section class="demo-info-card">
                  <span>About this memorial</span>
                  <p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed nec nibh non justo volutpat cursus. Integer vitae augue sed ipsum lacinia tempor.</p>
                </section>

                <section class="demo-milestone-list">
                  <?php foreach ($demoMemorial['milestones'] as $milestone): ?>
                    <article class="demo-milestone-card">
                      <div class="demo-milestone-head">
                        <small><?= htmlspecialchars($milestone['date'], ENT_QUOTES, 'UTF-8') ?></small>
                        <h4><?= htmlspecialchars($milestone['title'], ENT_QUOTES, 'UTF-8') ?></h4>
                      </div>
                      <img src="<?= htmlspecialchars($milestone['image'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($milestone['title'], ENT_QUOTES, 'UTF-8') ?>">
                      <p><?= htmlspecialchars($milestone['body'], ENT_QUOTES, 'UTF-8') ?></p>
                    </article>
                  <?php endforeach; ?>
                </section>
              </div>
            </article>
          </div>
        </div>
      </section>

      <section class="pricing-section" id="pricing" aria-labelledby="pricing-title">
        <div class="pricing-content">
          <div class="section-heading">
            <p class="section-eyebrow">Pricing</p>
            <h2 id="pricing-title">Choose how much of their story you want to preserve.</h2>
            <p class="section-quote">
              Start with the essentials, or choose Premium when your family
              wants to save more photos, milestones, and memories for the next
              generation. Both plans are yearly subscriptions.
            </p>
          </div>

          <div class="pricing-grid">
            <?php foreach ($pricingPlans as $plan): ?>
              <article class="pricing-card<?= !empty($plan['featured']) ? ' pricing-card-featured' : '' ?>">
                <p class="plan-label"><?= htmlspecialchars($plan['label'], ENT_QUOTES, 'UTF-8') ?></p>
                <h3><?= htmlspecialchars($plan['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                <p class="plan-price">
                  <?= htmlspecialchars($plan['price'], ENT_QUOTES, 'UTF-8') ?>
                  <span><?= htmlspecialchars($plan['term'], ENT_QUOTES, 'UTF-8') ?></span>
                </p>
                <p><?= htmlspecialchars($plan['description'], ENT_QUOTES, 'UTF-8') ?></p>
                <ul>
                  <?php foreach ($plan['features'] as $feature): ?>
                    <li><i class="fa-solid fa-check" aria-hidden="true"></i><?= htmlspecialchars($feature, ENT_QUOTES, 'UTF-8') ?></li>
                  <?php endforeach; ?>
                </ul>
                <a class="plan-link" href="#signup">Choose <?= htmlspecialchars($plan['name'], ENT_QUOTES, 'UTF-8') ?> <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
              </article>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <section class="founder-section" aria-labelledby="founder-title">
        <div class="founder-content">
          <div class="founder-media">
            <div class="founder-mark">
              <img src="assets/founder-joseph-aramil.jpeg" alt="Joseph Michael Aramil, founder of AlaalaMo">
            </div>
            <div class="founder-logo">
              <img src="assets/alaalamo-logo-mark.png?v=<?= urlencode((string) (file_exists(__DIR__ . '/assets/alaalamo-logo-mark.png') ? filemtime(__DIR__ . '/assets/alaalamo-logo-mark.png') : time())) ?>" alt="AlaalaMo logo">
            </div>
          </div>
          <div>
            <p class="section-eyebrow">Founder story</p>
            <h2 id="founder-title">Built from memory, by someone who understands loss.</h2>
            <p class="section-quote">
              <strong>Joseph Michael Aramil</strong>, founder of the <strong>AlaalaMo</strong>
              startup, has been a freelance web developer since 2010 and an educator
              committed to building useful digital tools. Inspired by the passing of
              his father, he pursued and created <strong>AlaalaMo</strong> as a way for
              families to preserve the faces, stories, and memories that should not
              fade with time.
            </p>
          </div>
        </div>
      </section>

      <section class="signup-section" id="signup" aria-labelledby="signup-title">
        <div class="signup-content">
          <div>
            <p class="section-eyebrow">Sign up</p>
            <h2 id="signup-title">Create your account with email verification.</h2>
            <p class="section-quote">
              Interested clients sign up with their name and email. After email
              OTP verification, they can log in and continue to the dashboard to
              start filling in the memorial page details.
            </p>
          </div>

          <?php if ($registrationFlash): ?>
            <p class="auth-alert auth-alert-<?= htmlspecialchars($registrationFlash['type'], ENT_QUOTES, 'UTF-8') ?>">
              <?= htmlspecialchars($registrationFlash['message'], ENT_QUOTES, 'UTF-8') ?>
            </p>
          <?php endif; ?>

          <form class="signup-form" action="register.php" method="post">
            <label>
              Last name
              <input type="text" name="last_name" autocomplete="family-name" required>
            </label>
            <label>
              Given name
              <input type="text" name="given_name" autocomplete="given-name" required>
            </label>
            <label>
              Email address
              <input type="email" name="email" autocomplete="email" required>
            </label>
            <label>
              Subscription plan
              <select name="plan_type" required>
                <option value="regular">Standard - PHP 599 / year</option>
                <option value="premium">Premium - PHP 999 / year</option>
              </select>
            </label>
            <div class="signup-steps form-full" aria-label="Signup steps">
              <span><i class="fa-solid fa-user-plus" aria-hidden="true"></i> 1. Sign up</span>
              <span><i class="fa-solid fa-envelope-circle-check" aria-hidden="true"></i> 2. Verify OTP</span>
              <span><i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i> 3. Log in</span>
              <span><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i> 4. Build memorial</span>
            </div>
            <button class="button-primary form-submit" type="submit"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Send Email OTP</button>
          </form>
        </div>
      </section>
    </main>

    <footer class="site-footer">
      <div class="footer-content">
        <div>
          <a class="footer-brand" href="/">
            <span class="brand-mark" aria-hidden="true"><img class="brand-mark-image" src="assets/alaalamo-logo-mark.png?v=<?= urlencode((string) (file_exists(__DIR__ . '/assets/alaalamo-logo-mark.png') ? filemtime(__DIR__ . '/assets/alaalamo-logo-mark.png') : time())) ?>" alt=""></span>
            <span class="brand-highlight">AlaalaMo</span>
          </a>
          <p>
            Private QR memorial pages for families who want the next generation
            to know the life behind the name.
          </p>
        </div>
        <nav class="footer-links" aria-label="Footer">
          <a href="#what-it-does"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> What it does</a>
          <a href="#features"><i class="fa-solid fa-layer-group" aria-hidden="true"></i> Features</a>
          <a href="#pricing"><i class="fa-solid fa-tags" aria-hidden="true"></i> Pricing</a>
          <a href="#signup"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Register</a>
          <a href="login.php"><i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i> Login</a>
        </nav>
      </div>
      <p class="footer-note">&copy; <?= date('Y') ?> AlaalaMo. Memories made easier to revisit.</p>
    </footer>
    <script>
      const menuToggle = document.querySelector('.menu-toggle');
      const navLinks = document.querySelector('.nav-links');

      menuToggle?.addEventListener('click', () => {
        const isOpen = navLinks?.classList.toggle('is-open') || false;
        menuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        menuToggle.setAttribute('aria-label', isOpen ? 'Close menu' : 'Open menu');
      });

      navLinks?.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => {
          navLinks.classList.remove('is-open');
          menuToggle?.setAttribute('aria-expanded', 'false');
          menuToggle?.setAttribute('aria-label', 'Open menu');
        });
      });
    </script>
  </body>
</html>

