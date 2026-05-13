<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

start_app_session();

if (empty($_SESSION['user_id'])) {
    redirect_to('/login.php');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([(int) $_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    redirect_to('/login.php');
}

function uploaded_file_at(array $files, int $index): array
{
    return [
        'name' => $files['name'][$index] ?? '',
        'type' => $files['type'][$index] ?? '',
        'tmp_name' => $files['tmp_name'][$index] ?? '',
        'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
        'size' => $files['size'][$index] ?? 0,
    ];
}

function nested_uploaded_file_at(array $files, int $groupIndex, int $fileIndex): array
{
    return [
        'name' => $files['name'][$groupIndex][$fileIndex] ?? '',
        'type' => $files['type'][$groupIndex][$fileIndex] ?? '',
        'tmp_name' => $files['tmp_name'][$groupIndex][$fileIndex] ?? '',
        'error' => $files['error'][$groupIndex][$fileIndex] ?? UPLOAD_ERR_NO_FILE,
        'size' => $files['size'][$groupIndex][$fileIndex] ?? 0,
    ];
}

function extract_ai_story_payload(string $text): ?array
{
    $clean = trim($text);
    $clean = preg_replace('/^```json\s*/i', '', $clean) ?? $clean;
    $clean = preg_replace('/^```\s*/', '', $clean) ?? $clean;
    $clean = preg_replace('/\s*```$/', '', $clean) ?? $clean;
    $decoded = json_decode($clean, true);

    if (is_array($decoded)) {
        return $decoded;
    }

    if (preg_match('/\{.*\}/s', $clean, $matches)) {
        $decoded = json_decode($matches[0], true);
        return is_array($decoded) ? $decoded : null;
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = clean_input($_POST['form_action'] ?? 'save_memorial');
    $memorialIdInput = (int) ($_POST['memorial_id'] ?? 0);
    $qrGroup = ensure_qr_group((int) $user['id']);

    if ($formAction === 'generate_ai_story') {
        if (!openai_is_configured()) {
            flash('error', 'OpenAI API key is not configured yet. Add it in config.php before generating the premium life story.');
            redirect_to('/dashboard.php?memorial_id=' . $memorialIdInput);
        }

        $stmt = $pdo->prepare('SELECT * FROM memorials WHERE id = ? AND user_id = ? AND qr_group_id = ? LIMIT 1');
        $stmt->execute([$memorialIdInput, (int) $user['id'], (int) $qrGroup['id']]);
        $memorialForAi = $stmt->fetch();

        if (!$memorialForAi) {
            flash('error', 'Please save the memorial profile before generating the AI life story.');
            redirect_to('/dashboard.php');
        }

        $stmt = $pdo->prepare('SELECT * FROM milestones WHERE memorial_id = ? ORDER BY sort_order ASC, id ASC');
        $stmt->execute([(int) $memorialForAi['id']]);
        $milestonesForAi = $stmt->fetchAll();

        if (!$milestonesForAi) {
            flash('error', 'Please add and save at least one milestone before generating the AI life story.');
            redirect_to('/dashboard.php?memorial_id=' . (int) $memorialForAi['id']);
        }

        $instructions = 'You write simple, heartfelt, respectful memorial prose for Filipino families. Do not invent facts. Use only the provided details. Avoid flowery, overly dramatic, or complicated language. Make every sentence easy to understand, sincere, and emotionally meaningful.';
        $context = build_memorial_context($memorialForAi, $milestonesForAi);
        $storyJson = openai_text_response(
            $instructions,
            $context . "\n\nReturn only valid JSON with this exact structure: {\"autobiography\":\"...\",\"milestones\":[{\"id\":123,\"narration\":\"...\"}]}. The autobiography should be simple but impactful, about 250 to 400 words, written in first person as if the loved one is gently speaking to their family. Focus on love, family, values, memories, and legacy. Each milestone narration should be short, solemn, and natural, about 30 to 45 seconds when spoken. Use the actual milestone id values from this list:\n" . json_encode(array_map(static fn(array $milestone): array => [
                'id' => (int) $milestone['id'],
                'title' => $milestone['title'],
                'date' => $milestone['milestone_date'],
                'description' => $milestone['description'],
            ], $milestonesForAi), JSON_UNESCAPED_UNICODE)
        );
        $storyPayload = $storyJson ? extract_ai_story_payload($storyJson) : null;
        $autobiography = is_array($storyPayload) ? clean_input((string) ($storyPayload['autobiography'] ?? '')) : '';

        if (!$autobiography) {
            flash('error', 'The AI life story could not be generated. Check OpenAI key, billing, cURL support, and Hostinger error logs.');
            redirect_to('/dashboard.php?memorial_id=' . (int) $memorialForAi['id']);
        }

        $pdo->prepare('UPDATE memorials SET autobiography_text = ?, autobiography_generated_at = NOW() WHERE id = ?')
            ->execute([$autobiography, (int) $memorialForAi['id']]);

        $narrationsById = [];
        foreach (($storyPayload['milestones'] ?? []) as $generatedMilestone) {
            $generatedId = (int) ($generatedMilestone['id'] ?? 0);
            $generatedText = clean_input((string) ($generatedMilestone['narration'] ?? ''));

            if ($generatedId > 0 && $generatedText !== '') {
                $narrationsById[$generatedId] = $generatedText;
            }
        }

        $savedNarrations = 0;
        foreach ($milestonesForAi as $milestoneForAi) {
            $narration = $narrationsById[(int) $milestoneForAi['id']] ?? clean_input((string) ($milestoneForAi['description'] ?? ''));

            $pdo->prepare(
                'UPDATE milestones
                 SET ai_narration_text = ?, narration_audio_path = NULL, narration_generated_at = NOW()
                 WHERE id = ?'
            )->execute([$narration, (int) $milestoneForAi['id']]);
            $savedNarrations++;
        }

        flash('success', 'Premium AI autobiography and ' . $savedNarrations . ' milestone narration text entries were generated and saved.');
        redirect_to('/dashboard.php?memorial_id=' . (int) $memorialForAi['id']);
    }

    $lovedOneName = clean_input($_POST['loved_one_name'] ?? '');
    $birthDate = clean_input($_POST['birth_date'] ?? '');
    $deathDate = clean_input($_POST['death_date'] ?? '');
    $restingPlace = clean_input($_POST['resting_place'] ?? '');
    $memorialQuote = clean_input($_POST['memorial_quote'] ?? '');
    $shortDescription = clean_input($_POST['short_description'] ?? '');

    if ($lovedOneName === '') {
        flash('error', 'Please enter the name of the loved one for the memorial profile.');
        redirect_to('/dashboard.php');
    }

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare('SELECT * FROM memorials WHERE id = ? AND user_id = ? AND qr_group_id = ? LIMIT 1');
        $stmt->execute([$memorialIdInput, (int) $user['id'], (int) $qrGroup['id']]);
        $memorial = $stmt->fetch();

        if ($memorial) {
            $memorialId = (int) $memorial['id'];
            $token = $memorial['public_token'];
            $pdo->prepare(
                'UPDATE memorials
                 SET loved_one_name = ?, birth_date = ?, death_date = ?, resting_place = ?, memorial_quote = ?, short_description = ?, status = "published"
                 WHERE id = ?'
            )->execute([
                $lovedOneName,
                $birthDate !== '' ? $birthDate : null,
                $deathDate !== '' ? $deathDate : null,
                $restingPlace !== '' ? $restingPlace : null,
                $memorialQuote !== '' ? $memorialQuote : null,
                $shortDescription !== '' ? $shortDescription : null,
                $memorialId,
            ]);
        } else {
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM memorials WHERE qr_group_id = ?');
            $countStmt->execute([(int) $qrGroup['id']]);
            $memorialCount = (int) $countStmt->fetchColumn();

            if ($memorialCount >= MAX_MEMORIALS_PER_QR) {
                throw new RuntimeException('Maximum memorials reached for this QR group.');
            }

            $token = generate_token();
            $pdo->prepare(
                'INSERT INTO memorials
                 (user_id, qr_group_id, public_token, loved_one_name, birth_date, death_date, resting_place, memorial_quote, short_description, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "published")'
            )->execute([
                (int) $user['id'],
                (int) $qrGroup['id'],
                $token,
                $lovedOneName,
                $birthDate !== '' ? $birthDate : null,
                $deathDate !== '' ? $deathDate : null,
                $restingPlace !== '' ? $restingPlace : null,
                $memorialQuote !== '' ? $memorialQuote : null,
                $shortDescription !== '' ? $shortDescription : null,
            ]);
            $memorialId = (int) $pdo->lastInsertId();
        }

        if (!empty($_FILES['profile_images']['name'])) {
            $existingCountStmt = $pdo->prepare('SELECT COUNT(*) FROM memorial_images WHERE memorial_id = ?');
            $existingCountStmt->execute([$memorialId]);
            $existingCount = (int) $existingCountStmt->fetchColumn();
            $remainingSlots = max(0, MAX_PROFILE_IMAGES - $existingCount);
            $profileUploadCount = min(count($_FILES['profile_images']['name']), $remainingSlots);

            for ($i = 0; $i < $profileUploadCount; $i++) {
                $path = store_uploaded_image(uploaded_file_at($_FILES['profile_images'], $i), 'memorials/' . $memorialId . '/profile');

                if ($path) {
                    $pdo->prepare('INSERT INTO memorial_images (memorial_id, image_path) VALUES (?, ?)')
                        ->execute([$memorialId, $path]);
                }
            }
        }

        $pdo->prepare('DELETE FROM milestones WHERE memorial_id = ?')->execute([$memorialId]);

        $titles = $_POST['milestone_title'] ?? [];
        $dates = $_POST['milestone_date'] ?? [];
        $descriptions = $_POST['milestone_description'] ?? [];
        $milestoneCount = min(count($titles), MAX_MILESTONES);

        for ($i = 0; $i < $milestoneCount; $i++) {
            $title = clean_input($titles[$i] ?? '');

            if ($title === '') {
                continue;
            }

            $pdo->prepare(
                'INSERT INTO milestones (memorial_id, title, milestone_date, description, sort_order)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $memorialId,
                $title,
                clean_input($dates[$i] ?? ''),
                clean_input($descriptions[$i] ?? ''),
                $i,
            ]);

            $milestoneId = (int) $pdo->lastInsertId();

            if (!empty($_FILES['milestone_images']['name'][$i])) {
                $imageCount = min(count($_FILES['milestone_images']['name'][$i]), MAX_MILESTONE_IMAGES);

                for ($j = 0; $j < $imageCount; $j++) {
                    $path = store_uploaded_image(
                        nested_uploaded_file_at($_FILES['milestone_images'], $i, $j),
                        'memorials/' . $memorialId . '/milestones/' . $milestoneId
                    );

                    if ($path) {
                        $pdo->prepare('INSERT INTO milestone_images (milestone_id, image_path) VALUES (?, ?)')
                            ->execute([$milestoneId, $path]);
                    }
                }
            }
        }

        $pdo->commit();
        flash('success', 'Memorial details saved. Your QR preview is ready.');
        redirect_to('/dashboard.php?memorial_id=' . $memorialId);
    } catch (Throwable $exception) {
        $pdo->rollBack();
        error_log('Memorial save failed: ' . $exception->getMessage());
        flash('error', 'The memorial could not be saved. Please try again.');
        redirect_to('/dashboard.php');
    }
}

$qrGroup = ensure_qr_group((int) $user['id']);
$stmt = $pdo->prepare('SELECT * FROM memorials WHERE user_id = ? AND qr_group_id = ? ORDER BY id ASC');
$stmt->execute([(int) $user['id'], (int) $qrGroup['id']]);
$memorials = $stmt->fetchAll();

$selectedMemorialId = (int) ($_GET['memorial_id'] ?? 0);
$memorial = null;

foreach ($memorials as $candidate) {
    if ($selectedMemorialId > 0 && (int) $candidate['id'] === $selectedMemorialId) {
        $memorial = $candidate;
        break;
    }
}

if (!$memorial && $memorials) {
    $memorial = $memorials[0];
}

if (isset($_GET['new']) && count($memorials) < MAX_MEMORIALS_PER_QR) {
    $memorial = null;
}

$milestones = [];
$milestoneImages = [];
if ($memorial) {
    $stmt = $pdo->prepare('SELECT * FROM milestones WHERE memorial_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([(int) $memorial['id']]);
    $milestones = $stmt->fetchAll();

    if ($milestones) {
        $imageStmt = $pdo->prepare('SELECT * FROM milestone_images WHERE milestone_id = ? ORDER BY id ASC');

        foreach ($milestones as $loadedMilestone) {
            $imageStmt->execute([(int) $loadedMilestone['id']]);
            $milestoneImages[(int) $loadedMilestone['id']] = $imageStmt->fetchAll();
        }
    }
}

$flash = get_flash();
$previewUrl = app_base_url() . '/memorial.php?t=' . urlencode($qrGroup['public_token']);
$qrUrl = $previewUrl !== '' ? 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($previewUrl) : '';
$additionalCost = max(0, count($memorials) - 1) * ADDITIONAL_MEMORIAL_PRICE;
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | AlaalaMo</title>
    <link rel="stylesheet" href="styles.css?v=<?= urlencode(defined('ASSET_VERSION') ? ASSET_VERSION : '20260513-1') ?>">
  </head>
  <body class="dashboard-page">
    <header class="dashboard-header">
      <a class="brand auth-brand" href="/">
        <span class="brand-mark" aria-hidden="true">A</span>
        <span class="brand-highlight">AlaalaMo</span>
      </a>
      <a class="auth-link" href="/logout.php">Logout</a>
    </header>

    <main class="dashboard-layout">
      <section class="dashboard-panel">
        <p class="section-eyebrow">Memorial dashboard</p>
        <h1>Welcome, <?= htmlspecialchars($user['given_name'], ENT_QUOTES, 'UTF-8') ?>.</h1>
        <p>
          Fill up the memorial profile first, then add up to <?= MAX_MILESTONES ?> life milestones.
          Each milestone can include up to <?= MAX_MILESTONE_IMAGES ?> images. When saved, AlaalaMo
          generates one private QR that can hold up to <?= MAX_MEMORIALS_PER_QR ?> memorial pages.
        </p>

        <?php if ($flash): ?>
          <p class="auth-alert auth-alert-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?>
          </p>
        <?php endif; ?>

        <div class="dashboard-guide">
          <h2>How to fill this up</h2>
          <ol>
            <li>Start with the correct full name and important dates.</li>
            <li>Add a short description that family members will recognize.</li>
            <li>Upload clear photos connected to the memorial profile.</li>
            <li>Add milestones in life order, such as childhood, family, work, achievements, and legacy.</li>
            <li>Save, scan the QR, and review the family card list or individual memorial on a mobile device.</li>
          </ol>
        </div>
      </section>

      <aside class="qr-panel">
        <h2>Family QR preview</h2>
        <?php if ($qrUrl): ?>
          <img src="<?= htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Memorial QR code">
          <a class="button-primary" href="<?= htmlspecialchars($previewUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Open Preview</a>
          <p><?= count($memorials) ?> of <?= MAX_MEMORIALS_PER_QR ?> memorials in this QR. Extra memorials are PHP <?= ADDITIONAL_MEMORIAL_PRICE ?> each.</p>
          <?php if ($additionalCost > 0): ?>
            <p>Additional memorial total: PHP <?= number_format($additionalCost) ?> per year.</p>
          <?php endif; ?>
        <?php else: ?>
          <p>Save the memorial details first to generate a QR preview.</p>
        <?php endif; ?>
      </aside>

      <form class="memorial-form" method="post" action="dashboard.php" enctype="multipart/form-data">
        <section class="form-section">
          <h2>Memorials in this QR</h2>
          <p>Premium promo: one QR can include up to <?= MAX_MEMORIALS_PER_QR ?> memorial pages. Each additional memorial is PHP <?= ADDITIONAL_MEMORIAL_PRICE ?> per year.</p>
          <?php if ($memorials): ?>
            <div class="dashboard-memorial-list">
              <?php foreach ($memorials as $item): ?>
                <a class="<?= $memorial && (int) $memorial['id'] === (int) $item['id'] ? 'is-active' : '' ?>" href="/dashboard.php?memorial_id=<?= (int) $item['id'] ?>">
                  <?= htmlspecialchars($item['loved_one_name'], ENT_QUOTES, 'UTF-8') ?>
                </a>
              <?php endforeach; ?>
              <?php if (count($memorials) < MAX_MEMORIALS_PER_QR): ?>
                <a href="/dashboard.php?new=1">+ Add memorial</a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </section>

        <section class="form-section">
          <h2>Memorial Profile</h2>
          <input type="hidden" name="memorial_id" value="<?= isset($_GET['new']) ? 0 : (int) ($memorial['id'] ?? 0) ?>">
          <div class="form-grid">
            <label>
              Loved one's full name
              <input type="text" name="loved_one_name" value="<?= htmlspecialchars($memorial['loved_one_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
            </label>
            <label>
              Resting place
              <input type="text" name="resting_place" value="<?= htmlspecialchars($memorial['resting_place'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </label>
            <label>
              Birth date
              <input type="date" name="birth_date" value="<?= htmlspecialchars($memorial['birth_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </label>
            <label>
              Death date
              <input type="date" name="death_date" value="<?= htmlspecialchars($memorial['death_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </label>
            <label class="form-full">
              Quote or dedication from loved ones
              <textarea name="memorial_quote" rows="3" placeholder="Example: Your love remains our light, and your memory walks with us every day."><?= htmlspecialchars($memorial['memorial_quote'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            </label>
            <label class="form-full">
              Short description
              <textarea name="short_description" rows="4" placeholder="A gentle introduction to who they were."><?= htmlspecialchars($memorial['short_description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            </label>
            <label class="form-full">
              Profile images
              <input type="file" name="profile_images[]" accept="image/jpeg,image/png,image/webp" multiple>
              <span class="field-note">You may upload memorial profile photos. Premium supports up to <?= MAX_PROFILE_IMAGES ?> gallery images.</span>
            </label>
          </div>
        </section>

        <section class="form-section">
          <h2>Life Milestones</h2>
          <p>Add up to <?= MAX_MILESTONES ?> milestones. Each one can have up to <?= MAX_MILESTONE_IMAGES ?> images.</p>

          <?php for ($i = 0; $i < MAX_MILESTONES; $i++): ?>
            <?php $milestone = $milestones[$i] ?? null; ?>
            <?php $imagesForMilestone = $milestone ? ($milestoneImages[(int) $milestone['id']] ?? []) : []; ?>
            <div class="milestone-box">
              <h3>Milestone <?= $i + 1 ?></h3>
              <div class="form-grid">
                <label>
                  Title
                  <input type="text" name="milestone_title[]" value="<?= htmlspecialchars($milestone['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Example: Built a family, Started a business">
                </label>
                <label>
                  Date or period
                  <input type="text" name="milestone_date[]" value="<?= htmlspecialchars($milestone['milestone_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Example: 1975, Childhood, 1990s">
                </label>
                <label class="form-full">
                  Description
                  <textarea name="milestone_description[]" rows="3" placeholder="What happened in this chapter of their life?"><?= htmlspecialchars($milestone['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                </label>
                <label class="form-full">
                  Milestone images
                  <input type="file" name="milestone_images[<?= $i ?>][]" accept="image/jpeg,image/png,image/webp" multiple>
                  <span class="field-note">Maximum <?= MAX_MILESTONE_IMAGES ?> images for this milestone.</span>
                </label>
                <div class="milestone-image-preview form-full">
                  <?php if ($imagesForMilestone): ?>
                    <?php foreach ($imagesForMilestone as $image): ?>
                      <img src="<?= htmlspecialchars($image['image_path'], ENT_QUOTES, 'UTF-8') ?>" alt="Milestone image preview">
                    <?php endforeach; ?>
                  <?php else: ?>
                    <p>No milestone images yet.</p>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endfor; ?>
        </section>

        <button class="button-primary form-submit" type="submit">Save and Generate QR</button>
      </form>

      <?php if ($memorial): ?>
        <form class="memorial-form ai-story-form" method="post" action="dashboard.php">
          <input type="hidden" name="form_action" value="generate_ai_story">
          <input type="hidden" name="memorial_id" value="<?= (int) $memorial['id'] ?>">
          <section class="form-section">
            <h2>Premium AI Life Story</h2>
            <p>
              Generate and save a solemn autobiography plus narration text for each milestone.
              The mobile view will narrate the saved text using the visitor's device voice and
              show the milestone images in a full-screen slideshow.
            </p>
            <?php if (!openai_is_configured()): ?>
              <p class="auth-alert auth-alert-error">OpenAI API key is not configured yet in config.php.</p>
            <?php elseif (!empty($memorial['autobiography_text'])): ?>
              <p class="auth-alert auth-alert-success">AI life story has already been generated. You may regenerate it after editing milestones.</p>
            <?php endif; ?>
            <button class="button-primary form-submit" type="submit">Generate Premium Life Story</button>
          </section>
        </form>
      <?php endif; ?>
    </main>
  </body>
</html>

