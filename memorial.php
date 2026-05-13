<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

$token = clean_input($_GET['t'] ?? '');

if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404);
    exit('Memorial not found.');
}

function memorial_theme_color(array $memorial, string $key, string $fallback): string
{
    $color = (string) ($memorial[$key] ?? '');

    return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : $fallback;
}

function readable_text_color(string $hexColor): string
{
    $hex = ltrim($hexColor, '#');
    $red = hexdec(substr($hex, 0, 2));
    $green = hexdec(substr($hex, 2, 2));
    $blue = hexdec(substr($hex, 4, 2));
    $brightness = (($red * 299) + ($green * 587) + ($blue * 114)) / 1000;

    return $brightness >= 150 ? '#1f2933' : '#ffffff';
}

function memorial_theme_style(array $memorial): string
{
    $primary = memorial_theme_color($memorial, 'theme_primary', '#214c63');
    $secondary = memorial_theme_color($memorial, 'theme_secondary', '#eadcc8');
    $tertiary = memorial_theme_color($memorial, 'theme_tertiary', '#fbfaf7');
    $style = [
        '--memorial-primary: ' . $primary,
        '--memorial-secondary: ' . $secondary,
        '--memorial-tertiary: ' . $tertiary,
        '--memorial-primary-text: ' . readable_text_color($primary),
        '--memorial-secondary-text: ' . readable_text_color($secondary),
        '--memorial-tertiary-text: ' . readable_text_color($tertiary),
    ];

    return htmlspecialchars(implode('; ', $style) . ';', ENT_QUOTES, 'UTF-8');
}

function memorial_display_date(?string $date): string
{
    if (!$date) {
        return '';
    }

    $timestamp = strtotime($date);

    return $timestamp ? date('F d, Y', $timestamp) : '';
}

function memorial_date_range(array $memorial): string
{
    $birthDate = memorial_display_date($memorial['birth_date'] ?? null);
    $deathDate = memorial_display_date($memorial['death_date'] ?? null);

    if ($birthDate !== '' && $deathDate !== '') {
        return $birthDate . ' - ' . $deathDate;
    }

    return $birthDate !== '' ? $birthDate : $deathDate;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM qr_groups WHERE public_token = ? LIMIT 1');
$stmt->execute([$token]);
$qrGroup = $stmt->fetch();

$memorials = [];
$isGroupView = false;

if ($qrGroup) {
    $stmt = $pdo->prepare('SELECT * FROM memorials WHERE qr_group_id = ? AND status = "published" ORDER BY id ASC');
    $stmt->execute([(int) $qrGroup['id']]);
    $memorials = $stmt->fetchAll();
    $isGroupView = count($memorials) > 1;

    if (!$memorials) {
        http_response_code(404);
        exit('Memorial not found.');
    }

    $memorial = $memorials[0];
} else {
    $stmt = $pdo->prepare('SELECT * FROM memorials WHERE public_token = ? AND status = "published" LIMIT 1');
    $stmt->execute([$token]);
    $memorial = $stmt->fetch();

    if (!$memorial) {
        http_response_code(404);
        exit('Memorial not found.');
    }
}

$selectedId = (int) ($_GET['m'] ?? 0);

if ($selectedId > 0 && $qrGroup) {
    foreach ($memorials as $candidate) {
        if ((int) $candidate['id'] === $selectedId) {
            $memorial = $candidate;
            $isGroupView = false;
            break;
        }
    }
}

$themeStyle = memorial_theme_style($memorial);

if ($isGroupView): ?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Family Memorials | AlaalaMo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css?v=<?= urlencode(defined('ASSET_VERSION') ? ASSET_VERSION : '20260513-10') ?>">
  </head>
  <body class="memorial-preview-page" style="<?= $themeStyle ?>">
    <main class="mobile-memorial">
      <section class="mobile-memorial-header">
        <p class="section-eyebrow">Family tribute</p>
        <h1>Memorials in this QR</h1>
        <p>Select a loved one to view their memorial page.</p>
      </section>
      <section class="mobile-memorial-section">
        <div class="memorial-card-list">
          <?php foreach ($memorials as $item): ?>
            <?php
              $imageStmt = $pdo->prepare('SELECT image_path FROM memorial_images WHERE memorial_id = ? ORDER BY id ASC LIMIT 1');
              $imageStmt->execute([(int) $item['id']]);
              $image = $imageStmt->fetchColumn();
              $itemUrl = 'memorial.php?t=' . urlencode($token) . '&m=' . (int) $item['id'];
            ?>
            <a class="memorial-select-card" href="<?= htmlspecialchars($itemUrl, ENT_QUOTES, 'UTF-8') ?>">
              <?php if ($image): ?>
                <img src="<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($item['loved_one_name'], ENT_QUOTES, 'UTF-8') ?>">
              <?php endif; ?>
              <span>
                <strong><?= htmlspecialchars($item['loved_one_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                <small><?= htmlspecialchars(memorial_date_range($item), ENT_QUOTES, 'UTF-8') ?></small>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      </section>
      <footer class="mobile-memorial-footer">
        <a href="https://alaalamo.site" target="_blank" rel="noopener">All rights reserved AlaalaMo</a>
      </footer>
    </main>
  </body>
</html>
<?php exit; endif;

$stmt = $pdo->prepare('SELECT * FROM memorial_images WHERE memorial_id = ? ORDER BY id ASC LIMIT 20');
$stmt->execute([(int) $memorial['id']]);
$images = $stmt->fetchAll();
$coverImage = $images[0]['image_path'] ?? '';

$stmt = $pdo->prepare('SELECT * FROM milestones WHERE memorial_id = ? ORDER BY sort_order ASC, id ASC');
$stmt->execute([(int) $memorial['id']]);
$milestones = $stmt->fetchAll();

$milestoneImages = [];
if ($milestones) {
    $imageStmt = $pdo->prepare(
        'SELECT mi.*
         FROM milestone_images mi
         WHERE mi.milestone_id = ?
         ORDER BY mi.id ASC'
    );

    foreach ($milestones as $milestone) {
        $imageStmt->execute([(int) $milestone['id']]);
        $milestoneImages[(int) $milestone['id']] = $imageStmt->fetchAll();
    }
}

?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= htmlspecialchars($memorial['loved_one_name'], ENT_QUOTES, 'UTF-8') ?> | AlaalaMo Memorial</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="styles.css?v=<?= urlencode(defined('ASSET_VERSION') ? ASSET_VERSION : '20260513-10') ?>">
  </head>
  <body class="memorial-preview-page" style="<?= $themeStyle ?>">
    <main class="mobile-memorial mx-auto" style="<?= $themeStyle ?>">
      <section class="mobile-memorial-cover d-flex align-items-end">
        <?php if ($images): ?>
          <div class="profile-cover-slideshow" aria-hidden="true">
            <?php foreach ($images as $imageIndex => $image): ?>
              <img
                class="<?= $imageIndex === 0 ? 'is-active' : '' ?>"
                src="<?= htmlspecialchars($image['image_path'], ENT_QUOTES, 'UTF-8') ?>"
                alt=""
              >
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <div class="mobile-memorial-cover-content w-100">
          <p class="section-eyebrow">In loving memory</p>
          <h1><?= htmlspecialchars($memorial['loved_one_name'], ENT_QUOTES, 'UTF-8') ?></h1>
          <p class="memorial-dates">
            <?= htmlspecialchars(memorial_date_range($memorial), ENT_QUOTES, 'UTF-8') ?>
          </p>
          <hr class="memorial-date-rule">
          <?php if (!empty($memorial['memorial_quote'])): ?>
            <blockquote><?= nl2br(htmlspecialchars($memorial['memorial_quote'], ENT_QUOTES, 'UTF-8')) ?></blockquote>
          <?php endif; ?>
          <?php if (!empty($memorial['resting_place'])): ?>
            <p class="memorial-resting-place">
              <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
              <span><?= htmlspecialchars($memorial['resting_place'], ENT_QUOTES, 'UTF-8') ?></span>
            </p>
          <?php endif; ?>
          <div class="memorial-hero-actions d-grid gap-2 mt-3">
            <?php if (!empty($memorial['autobiography_text']) || $milestones): ?>
              <button class="btn btn-light btn-lg story-play-button" type="button">Play Life Story</button>
            <?php endif; ?>
            <?php if ($images): ?>
              <a class="btn btn-outline-light btn-lg" href="#gallery">View Gallery</a>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <?php if (!empty($memorial['short_description'])): ?>
        <section class="mobile-memorial-section">
          <h2>About</h2>
          <p><?= nl2br(htmlspecialchars($memorial['short_description'], ENT_QUOTES, 'UTF-8')) ?></p>
        </section>
      <?php endif; ?>

      <?php if (!empty($memorial['autobiography_text'])): ?>
        <section class="mobile-memorial-section life-story-player">
          <h2>Life Story</h2>
          <p><?= nl2br(htmlspecialchars($memorial['autobiography_text'], ENT_QUOTES, 'UTF-8')) ?></p>
          <p class="field-note">Narration uses the visitor device voice. No audio file is stored.</p>
        </section>
      <?php endif; ?>

      <?php if ($images): ?>
        <section class="mobile-memorial-section" id="gallery">
          <h2>Photos</h2>
          <div class="preview-gallery row g-2">
            <?php foreach ($images as $image): ?>
              <div class="col-6">
                <img class="img-fluid" src="<?= htmlspecialchars($image['image_path'], ENT_QUOTES, 'UTF-8') ?>" alt="Memorial photo">
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>

      <?php if ($milestones): ?>
        <section class="mobile-memorial-section">
          <h2>Life Milestones</h2>
          <?php foreach ($milestones as $milestone): ?>
            <?php $imagesForMilestone = $milestoneImages[(int) $milestone['id']] ?? []; ?>
            <article
              class="preview-milestone"
              data-narration="<?= htmlspecialchars($milestone['ai_narration_text'] ?: $milestone['description'], ENT_QUOTES, 'UTF-8') ?>"
              data-images="<?= htmlspecialchars(json_encode(array_column($imagesForMilestone, 'image_path'), JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>"
            >
              <span><?= htmlspecialchars($milestone['milestone_date'], ENT_QUOTES, 'UTF-8') ?></span>
              <h3><?= htmlspecialchars($milestone['title'], ENT_QUOTES, 'UTF-8') ?></h3>
              <p><?= nl2br(htmlspecialchars($milestone['ai_narration_text'] ?: $milestone['description'], ENT_QUOTES, 'UTF-8')) ?></p>
              <?php if ($imagesForMilestone): ?>
                <div class="milestone-slideshow">
                  <?php foreach ($imagesForMilestone as $imageIndex => $image): ?>
                    <img
                      class="<?= $imageIndex === 0 ? 'is-active' : '' ?>"
                      src="<?= htmlspecialchars($image['image_path'], ENT_QUOTES, 'UTF-8') ?>"
                      alt="Milestone photo"
                    >
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>
      <footer class="mobile-memorial-footer">
        <a href="https://alaalamo.site" target="_blank" rel="noopener">All rights reserved AlaalaMo</a>
      </footer>
    </main>
    <div class="story-modal" aria-hidden="true">
      <div class="story-modal-backdrop"></div>
      <section class="story-modal-panel" role="dialog" aria-modal="true" aria-label="Life story narration">
        <button class="story-modal-close" type="button" aria-label="Close life story">&times;</button>
        <div class="story-modal-media">
          <img class="story-modal-image story-modal-image-a is-active" src="" alt="">
          <img class="story-modal-image story-modal-image-b" src="" alt="">
        </div>
        <div class="story-modal-copy">
          <h2 class="story-modal-title">Life Story</h2>
          <p class="story-modal-text"></p>
        </div>
      </section>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/js/bootstrap.bundle.min.js"></script>
    <script>
      const playButton = document.querySelector('.story-play-button');
      const profileCoverImages = Array.from(document.querySelectorAll('.profile-cover-slideshow img'));
      const milestones = Array.from(document.querySelectorAll('.preview-milestone'));
      const modal = document.querySelector('.story-modal');
      const modalImages = Array.from(document.querySelectorAll('.story-modal-image'));
      const modalTitle = document.querySelector('.story-modal-title');
      const modalText = document.querySelector('.story-modal-text');
      const modalClose = document.querySelector('.story-modal-close');
      let slideTimer = null;
      let profileCoverTimer = null;
      let activeModalImage = 0;

      if (profileCoverImages.length > 1) {
        let profileIndex = 0;
        profileCoverTimer = setInterval(() => {
          profileCoverImages[profileIndex].classList.remove('is-active');
          profileIndex = (profileIndex + 1) % profileCoverImages.length;
          profileCoverImages[profileIndex].classList.add('is-active');
        }, 8200);
      }

      function stopNarration() {
        window.speechSynthesis?.cancel();
        clearInterval(slideTimer);
        modal?.classList.remove('is-open');
        modal?.setAttribute('aria-hidden', 'true');
        document.querySelectorAll('.preview-milestone.is-playing').forEach((item) => {
          item.classList.remove('is-playing');
        });
      }

      function runSlideshow(images) {
        clearInterval(slideTimer);
        if (!modalImages.length || !images.length) return;

        let index = 0;
        activeModalImage = 0;
        modalImages.forEach((image, imageIndex) => {
          image.classList.toggle('is-active', imageIndex === 0);
          image.src = imageIndex === 0 ? images[index] : '';
        });

        slideTimer = setInterval(() => {
          index = (index + 1) % images.length;
          const nextImage = modalImages[activeModalImage === 0 ? 1 : 0];
          const currentImage = modalImages[activeModalImage];
          nextImage.src = images[index];
          nextImage.classList.add('is-active');
          currentImage.classList.remove('is-active');
          activeModalImage = activeModalImage === 0 ? 1 : 0;
        }, 5200);
      }

      function speakMilestone(index) {
        if (index >= milestones.length) {
          stopNarration();
          return;
        }

        const milestone = milestones[index];
        const text = milestone.dataset.narration || '';
        const title = milestone.querySelector('h3')?.textContent || 'Life Story';
        let images = [];
        try {
          images = JSON.parse(milestone.dataset.images || '[]');
        } catch (error) {
          images = [];
        }
        if (!text.trim()) {
          speakMilestone(index + 1);
          return;
        }

        document.querySelectorAll('.preview-milestone.is-playing').forEach((item) => {
          item.classList.remove('is-playing');
        });
        milestone.classList.add('is-playing');
        modal?.classList.add('is-open');
        modal?.setAttribute('aria-hidden', 'false');
        if (modalTitle) modalTitle.textContent = title;
        if (modalText) modalText.textContent = text;
        runSlideshow(images);

        const utterance = new SpeechSynthesisUtterance(text);
        utterance.rate = 0.82;
        utterance.pitch = 0.88;
        utterance.onend = () => speakMilestone(index + 1);
        window.speechSynthesis.speak(utterance);
      }

      playButton?.addEventListener('click', () => {
        if (!('speechSynthesis' in window)) {
          alert('Narration is not supported on this browser.');
          return;
        }
        stopNarration();
        speakMilestone(0);
      });

      modalClose?.addEventListener('click', stopNarration);
      document.querySelector('.story-modal-backdrop')?.addEventListener('click', stopNarration);
      window.addEventListener('beforeunload', stopNarration);
    </script>
  </body>
</html>

