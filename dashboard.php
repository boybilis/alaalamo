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

function delete_uploaded_asset(?string $relativePath): void
{
    $path = str_replace('\\', '/', ltrim((string) $relativePath, '/'));

    if ($path === '' || !str_starts_with($path, 'uploads/') || str_contains($path, '..')) {
        return;
    }

    $uploadsRoot = realpath(__DIR__ . '/uploads');
    $target = realpath(__DIR__ . '/' . $path);

    if (!$uploadsRoot || !$target) {
        return;
    }

    $uploadsRoot = rtrim($uploadsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    if (str_starts_with($target, $uploadsRoot) && is_file($target)) {
        unlink($target);
    }
}

function clean_hex_color(string $value, string $fallback): string
{
    $color = trim($value);

    if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        return strtolower($color);
    }

    return $fallback;
}

function qr_plan_type(array $qrGroup): string
{
    return ($qrGroup['plan_type'] ?? 'regular') === 'premium' ? 'premium' : 'regular';
}

function qr_plan_limits(array $qrGroup): array
{
    $isPremium = qr_plan_type($qrGroup) === 'premium';

    return [
        'profile_images' => 5,
        'gallery_images' => $isPremium ? 20 : 6,
        'milestones' => $isPremium ? 5 : 2,
        'milestone_images' => $isPremium ? 6 : 2,
        'milestone_characters' => $isPremium ? 500 : 250,
        'life_story' => $isPremium,
    ];
}

function qr_additional_memorial_price(array $qrGroup): int
{
    return qr_plan_type($qrGroup) === 'premium' ? 700 : 399;
}

function is_ajax_request(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || ($_POST['ajax'] ?? '') === '1';
}

function json_response(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function image_preview_html(array $image, string $type): string
{
    $path = htmlspecialchars((string) $image['image_path'], ENT_QUOTES, 'UTF-8');
    $id = (int) $image['id'];
    $label = $type === 'profile' ? 'Profile image preview' : ($type === 'gallery' ? 'Gallery image preview' : 'Milestone image preview');

    return '<div class="image-preview-item">'
        . '<img src="' . $path . '" alt="' . $label . '">'
        . '<button class="image-delete-link" type="button" data-image-delete="' . $type . ':' . $id . '">Delete</button>'
        . '</div>';
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
    $planLimits = qr_plan_limits($qrGroup);
    $isAjax = is_ajax_request();

    if ($formAction === 'approve_message' || $formAction === 'delete_message') {
        $messageId = (int) ($_POST['message_id'] ?? 0);

        $stmt = $pdo->prepare(
            'SELECT mm.*, me.id AS memorial_id
             FROM memorial_messages mm
             INNER JOIN memorials me ON me.id = mm.memorial_id
             WHERE mm.id = ? AND me.user_id = ? AND me.qr_group_id = ?
             LIMIT 1'
        );
        $stmt->execute([$messageId, (int) $user['id'], (int) $qrGroup['id']]);
        $messageForAction = $stmt->fetch();

        if (!$messageForAction) {
            flash('error', 'Message not found.');
            redirect_to('/dashboard.php?memorial_id=' . $memorialIdInput);
        }

        if ($formAction === 'approve_message') {
            $pdo->prepare('UPDATE memorial_messages SET status = "approved", approved_at = NOW() WHERE id = ?')
                ->execute([$messageId]);
            flash('success', 'Message approved and published.');
        } else {
            $pdo->prepare('DELETE FROM memorial_messages WHERE id = ?')
                ->execute([$messageId]);
            flash('success', 'Message deleted.');
        }

        redirect_to('/dashboard.php?memorial_id=' . (int) $messageForAction['memorial_id'] . '#messages-admin');
    }

    if ($formAction === 'generate_ai_story') {
        if (!$planLimits['life_story']) {
            flash('error', 'Premium Life Story is available only on premium plans.');
            redirect_to('/dashboard.php?memorial_id=' . $memorialIdInput);
        }

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

    if ($formAction === 'delete_image') {
        $imageDelete = clean_input($_POST['image_delete'] ?? '');
        [$imageType, $imageIdText] = array_pad(explode(':', $imageDelete, 2), 2, '');
        $imageId = (int) $imageIdText;

        if (($imageType === 'profile' || $imageType === 'gallery') && $imageId > 0) {
            $stmt = $pdo->prepare(
                'SELECT mi.*
                 FROM memorial_images mi
                 INNER JOIN memorials me ON me.id = mi.memorial_id
                 WHERE mi.id = ? AND mi.image_type = ? AND me.user_id = ? AND me.qr_group_id = ?
                 LIMIT 1'
            );
            $stmt->execute([$imageId, $imageType, (int) $user['id'], (int) $qrGroup['id']]);
            $image = $stmt->fetch();

            if ($image) {
                $pdo->prepare('DELETE FROM memorial_images WHERE id = ?')->execute([$imageId]);
                delete_uploaded_asset($image['image_path'] ?? null);
                $deleteLabel = $imageType === 'profile' ? 'Profile image' : 'Gallery image';

                if ($isAjax) {
                    json_response(['ok' => true, 'message' => $deleteLabel . ' deleted.']);
                }

                flash('success', $deleteLabel . ' deleted.');
                redirect_to('/dashboard.php?memorial_id=' . (int) $image['memorial_id']);
            }
        }

        if ($imageType === 'milestone' && $imageId > 0) {
            $stmt = $pdo->prepare(
                'SELECT mii.*, m.memorial_id
                 FROM milestone_images mii
                 INNER JOIN milestones m ON m.id = mii.milestone_id
                 INNER JOIN memorials me ON me.id = m.memorial_id
                 WHERE mii.id = ? AND me.user_id = ? AND me.qr_group_id = ?
                 LIMIT 1'
            );
            $stmt->execute([$imageId, (int) $user['id'], (int) $qrGroup['id']]);
            $image = $stmt->fetch();

            if ($image) {
                $pdo->prepare('DELETE FROM milestone_images WHERE id = ?')->execute([$imageId]);
                delete_uploaded_asset($image['image_path'] ?? null);
                if ($isAjax) {
                    json_response(['ok' => true, 'message' => 'Milestone image deleted.']);
                }

                flash('success', 'Milestone image deleted.');
                redirect_to('/dashboard.php?memorial_id=' . (int) $image['memorial_id']);
            }
        }

        if ($isAjax) {
            json_response(['ok' => false, 'message' => 'The image could not be deleted.'], 422);
        }

        flash('error', 'The image could not be deleted.');
        redirect_to('/dashboard.php?memorial_id=' . $memorialIdInput);
    }

    if ($formAction === 'save_milestone') {
        $milestoneIdInput = (int) ($_POST['milestone_id'] ?? 0);
        $sortOrder = max(0, min($planLimits['milestones'] - 1, (int) ($_POST['sort_order'] ?? 0)));
        $title = clean_input($_POST['title'] ?? '');
        $milestoneDate = clean_input($_POST['milestone_date'] ?? '');
        $description = clean_input($_POST['description'] ?? '');

        if (!$isAjax) {
            redirect_to('/dashboard.php?memorial_id=' . $memorialIdInput);
        }

        if ($memorialIdInput <= 0) {
            json_response(['ok' => false, 'message' => 'Save the memorial profile first before saving milestones.'], 422);
        }

        if ($title === '') {
            json_response(['ok' => false, 'message' => 'Milestone title is required.'], 422);
        }

        if (strlen($description) > $planLimits['milestone_characters']) {
            json_response([
                'ok' => false,
                'message' => 'Milestone description is limited to ' . $planLimits['milestone_characters'] . ' characters for this plan.',
            ], 422);
        }

        $stmt = $pdo->prepare('SELECT * FROM memorials WHERE id = ? AND user_id = ? AND qr_group_id = ? LIMIT 1');
        $stmt->execute([$memorialIdInput, (int) $user['id'], (int) $qrGroup['id']]);
        $targetMemorial = $stmt->fetch();

        if (!$targetMemorial) {
            json_response(['ok' => false, 'message' => 'Memorial not found.'], 404);
        }

        if ($milestoneIdInput > 0) {
            $stmt = $pdo->prepare('SELECT * FROM milestones WHERE id = ? AND memorial_id = ? LIMIT 1');
            $stmt->execute([$milestoneIdInput, $memorialIdInput]);

            if (!$stmt->fetch()) {
                json_response(['ok' => false, 'message' => 'Milestone not found.'], 404);
            }

            $pdo->prepare(
                'UPDATE milestones
                 SET title = ?, milestone_date = ?, description = ?, sort_order = ?
                 WHERE id = ? AND memorial_id = ?'
            )->execute([$title, $milestoneDate, $description, $sortOrder, $milestoneIdInput, $memorialIdInput]);
            $savedMilestoneId = $milestoneIdInput;
        } else {
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM milestones WHERE memorial_id = ?');
            $countStmt->execute([$memorialIdInput]);

            if ((int) $countStmt->fetchColumn() >= $planLimits['milestones']) {
                json_response(['ok' => false, 'message' => 'Maximum milestones reached.'], 422);
            }

            $pdo->prepare(
                'INSERT INTO milestones (memorial_id, title, milestone_date, description, sort_order)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$memorialIdInput, $title, $milestoneDate, $description, $sortOrder]);
            $savedMilestoneId = (int) $pdo->lastInsertId();
        }

        json_response([
            'ok' => true,
            'message' => 'Milestone saved.',
            'milestone_id' => $savedMilestoneId,
        ]);
    }

    if ($formAction === 'delete_milestone') {
        $milestoneIdInput = (int) ($_POST['milestone_id'] ?? 0);

        if (!$isAjax) {
            redirect_to('/dashboard.php?memorial_id=' . $memorialIdInput);
        }

        $stmt = $pdo->prepare(
            'SELECT m.*
             FROM milestones m
             INNER JOIN memorials me ON me.id = m.memorial_id
             WHERE m.id = ? AND me.user_id = ? AND me.qr_group_id = ?
             LIMIT 1'
        );
        $stmt->execute([$milestoneIdInput, (int) $user['id'], (int) $qrGroup['id']]);
        $targetMilestone = $stmt->fetch();

        if (!$targetMilestone) {
            json_response(['ok' => false, 'message' => 'Milestone not found.'], 404);
        }

        $stmt = $pdo->prepare('SELECT * FROM milestone_images WHERE milestone_id = ?');
        $stmt->execute([(int) $targetMilestone['id']]);
        $imagesToDelete = $stmt->fetchAll();

        $pdo->beginTransaction();

        try {
            $pdo->prepare('DELETE FROM milestone_images WHERE milestone_id = ?')->execute([(int) $targetMilestone['id']]);
            $pdo->prepare('DELETE FROM milestones WHERE id = ?')->execute([(int) $targetMilestone['id']]);
            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            error_log('Milestone delete failed: ' . $exception->getMessage());
            json_response(['ok' => false, 'message' => 'Milestone could not be deleted.'], 500);
        }

        foreach ($imagesToDelete as $imageToDelete) {
            delete_uploaded_asset($imageToDelete['image_path'] ?? null);
        }

        json_response(['ok' => true, 'message' => 'Milestone deleted.']);
    }

    if ($formAction === 'upload_milestone_images') {
        $milestoneIdInput = (int) ($_POST['milestone_id'] ?? 0);

        $stmt = $pdo->prepare(
            'SELECT m.*
             FROM milestones m
             INNER JOIN memorials me ON me.id = m.memorial_id
             WHERE m.id = ? AND me.user_id = ? AND me.qr_group_id = ?
             LIMIT 1'
        );
        $stmt->execute([$milestoneIdInput, (int) $user['id'], (int) $qrGroup['id']]);
        $targetMilestone = $stmt->fetch();

        if (!$targetMilestone) {
            if ($isAjax) {
                json_response(['ok' => false, 'message' => 'Please save the milestone before uploading images.'], 422);
            }

            flash('error', 'Please save the milestone before uploading images.');
            redirect_to('/dashboard.php?memorial_id=' . $memorialIdInput);
        }

        $existingCountStmt = $pdo->prepare('SELECT COUNT(*) FROM milestone_images WHERE milestone_id = ?');
        $existingCountStmt->execute([(int) $targetMilestone['id']]);
        $existingCount = (int) $existingCountStmt->fetchColumn();
        $remainingSlots = max(0, $planLimits['milestone_images'] - $existingCount);

        if ($remainingSlots === 0) {
            if ($isAjax) {
                json_response(['ok' => false, 'message' => 'This milestone already has the maximum number of images.'], 422);
            }

            flash('error', 'This milestone already has the maximum number of images.');
            redirect_to('/dashboard.php?memorial_id=' . (int) $targetMilestone['memorial_id']);
        }

        $uploaded = 0;
        $uploadedHtml = [];
        if (!empty($_FILES['milestone_images']['name'])) {
            $imageCount = min(count($_FILES['milestone_images']['name']), $remainingSlots);

            for ($i = 0; $i < $imageCount; $i++) {
                $path = store_uploaded_image(
                    uploaded_file_at($_FILES['milestone_images'], $i),
                    'users/' . (int) $user['id'] . '/memorials/' . (int) $targetMilestone['memorial_id'] . '/milestones/' . (int) $targetMilestone['id']
                );

                if ($path) {
                    $pdo->prepare('INSERT INTO milestone_images (milestone_id, image_path) VALUES (?, ?)')
                        ->execute([(int) $targetMilestone['id'], $path]);
                    $uploadedHtml[] = image_preview_html([
                        'id' => (int) $pdo->lastInsertId(),
                        'image_path' => $path,
                    ], 'milestone');
                    $uploaded++;
                }
            }
        }

        if ($isAjax) {
            json_response([
                'ok' => $uploaded > 0,
                'message' => $uploaded > 0 ? 'Milestone images uploaded.' : 'No valid milestone images were uploaded.',
                'html' => implode('', $uploadedHtml),
            ], $uploaded > 0 ? 200 : 422);
        }

        flash($uploaded > 0 ? 'success' : 'error', $uploaded > 0 ? 'Milestone images uploaded.' : 'No valid milestone images were uploaded.');
        redirect_to('/dashboard.php?memorial_id=' . (int) $targetMilestone['memorial_id']);
    }

    $lovedOneName = clean_input($_POST['loved_one_name'] ?? '');
    $birthDate = clean_input($_POST['birth_date'] ?? '');
    $deathDate = clean_input($_POST['death_date'] ?? '');
    $restingPlace = clean_input($_POST['resting_place'] ?? '');
    $memorialQuote = clean_input($_POST['memorial_quote'] ?? '');
    $shortDescription = clean_input($_POST['short_description'] ?? '');
    $themePrimary = clean_hex_color($_POST['theme_primary'] ?? '', '#214c63');
    $themeSecondary = clean_hex_color($_POST['theme_secondary'] ?? '', '#eadcc8');
    $themeTertiary = clean_hex_color($_POST['theme_tertiary'] ?? '', '#fbfaf7');

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
                 SET loved_one_name = ?, birth_date = ?, death_date = ?, resting_place = ?, memorial_quote = ?, short_description = ?, theme_primary = ?, theme_secondary = ?, theme_tertiary = ?, status = "published"
                 WHERE id = ?'
            )->execute([
                $lovedOneName,
                $birthDate !== '' ? $birthDate : null,
                $deathDate !== '' ? $deathDate : null,
                $restingPlace !== '' ? $restingPlace : null,
                $memorialQuote !== '' ? $memorialQuote : null,
                $shortDescription !== '' ? $shortDescription : null,
                $themePrimary,
                $themeSecondary,
                $themeTertiary,
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
                 (user_id, qr_group_id, public_token, loved_one_name, birth_date, death_date, resting_place, memorial_quote, short_description, theme_primary, theme_secondary, theme_tertiary, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "published")'
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
                $themePrimary,
                $themeSecondary,
                $themeTertiary,
            ]);
            $memorialId = (int) $pdo->lastInsertId();
        }

        if (!empty($_FILES['profile_images']['name'])) {
            $existingCountStmt = $pdo->prepare('SELECT COUNT(*) FROM memorial_images WHERE memorial_id = ? AND image_type = "profile"');
            $existingCountStmt->execute([$memorialId]);
            $existingCount = (int) $existingCountStmt->fetchColumn();
            $remainingSlots = max(0, $planLimits['profile_images'] - $existingCount);
            $profileUploadCount = min(count($_FILES['profile_images']['name']), $remainingSlots);

            for ($i = 0; $i < $profileUploadCount; $i++) {
                $path = store_uploaded_image(uploaded_file_at($_FILES['profile_images'], $i), 'users/' . (int) $user['id'] . '/memorials/' . $memorialId . '/profile');

                if ($path) {
                    $pdo->prepare('INSERT INTO memorial_images (memorial_id, image_type, image_path) VALUES (?, "profile", ?)')
                        ->execute([$memorialId, $path]);
                }
            }
        }

        if (!empty($_FILES['gallery_images']['name'])) {
            $existingCountStmt = $pdo->prepare('SELECT COUNT(*) FROM memorial_images WHERE memorial_id = ? AND image_type = "gallery"');
            $existingCountStmt->execute([$memorialId]);
            $existingCount = (int) $existingCountStmt->fetchColumn();
            $remainingSlots = max(0, $planLimits['gallery_images'] - $existingCount);
            $galleryUploadCount = min(count($_FILES['gallery_images']['name']), $remainingSlots);

            for ($i = 0; $i < $galleryUploadCount; $i++) {
                $path = store_uploaded_image(uploaded_file_at($_FILES['gallery_images'], $i), 'users/' . (int) $user['id'] . '/memorials/' . $memorialId . '/gallery');

                if ($path) {
                    $pdo->prepare('INSERT INTO memorial_images (memorial_id, image_type, image_path) VALUES (?, "gallery", ?)')
                        ->execute([$memorialId, $path]);
                }
            }
        }

        $milestoneIds = $_POST['milestone_id'] ?? [];
        $titles = $_POST['milestone_title'] ?? [];
        $dates = $_POST['milestone_date'] ?? [];
        $descriptions = $_POST['milestone_description'] ?? [];
        $milestoneCount = min(count($titles), $planLimits['milestones']);

        for ($i = 0; $i < $milestoneCount; $i++) {
            $milestoneIdInput = (int) ($milestoneIds[$i] ?? 0);
            $title = clean_input($titles[$i] ?? '');
            $description = substr(clean_input($descriptions[$i] ?? ''), 0, $planLimits['milestone_characters']);

            if ($title === '') {
                continue;
            }

            if ($milestoneIdInput > 0) {
                $pdo->prepare(
                    'UPDATE milestones
                     SET title = ?, milestone_date = ?, description = ?, sort_order = ?
                     WHERE id = ? AND memorial_id = ?'
                )->execute([
                    $title,
                    clean_input($dates[$i] ?? ''),
                    $description,
                    $i,
                    $milestoneIdInput,
                    $memorialId,
                ]);
            } else {
                $pdo->prepare(
                    'INSERT INTO milestones (memorial_id, title, milestone_date, description, sort_order)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([
                    $memorialId,
                    $title,
                    clean_input($dates[$i] ?? ''),
                    $description,
                    $i,
                ]);
            }
        }

        $pdo->commit();
        flash('success', 'Memorial details saved. Your QR preview link remains the same.');
        redirect_to('/dashboard.php?memorial_id=' . $memorialId);
    } catch (Throwable $exception) {
        $pdo->rollBack();
        error_log('Memorial save failed: ' . $exception->getMessage());
        flash('error', 'The memorial could not be saved. Please try again.');
        redirect_to('/dashboard.php');
    }
}

$qrGroup = ensure_qr_group((int) $user['id']);
$planType = qr_plan_type($qrGroup);
$planLimits = qr_plan_limits($qrGroup);
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
$profileImages = [];
$galleryImages = [];
$memorialMessages = [];
if ($memorial) {
    $stmt = $pdo->prepare(
        'SELECT mi.*
         FROM memorial_images mi
         INNER JOIN memorials me ON me.id = mi.memorial_id
         WHERE mi.memorial_id = ? AND mi.image_type = "profile" AND me.user_id = ? AND me.qr_group_id = ?
         ORDER BY mi.id ASC'
    );
    $stmt->execute([(int) $memorial['id'], (int) $user['id'], (int) $qrGroup['id']]);
    $profileImages = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT mi.*
         FROM memorial_images mi
         INNER JOIN memorials me ON me.id = mi.memorial_id
         WHERE mi.memorial_id = ? AND mi.image_type = "gallery" AND me.user_id = ? AND me.qr_group_id = ?
         ORDER BY mi.id ASC'
    );
    $stmt->execute([(int) $memorial['id'], (int) $user['id'], (int) $qrGroup['id']]);
    $galleryImages = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM milestones WHERE memorial_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([(int) $memorial['id']]);
    $milestones = $stmt->fetchAll();

    if ($milestones) {
        $imageStmt = $pdo->prepare(
            'SELECT mii.*
             FROM milestone_images mii
             INNER JOIN milestones m ON m.id = mii.milestone_id
             INNER JOIN memorials me ON me.id = m.memorial_id
             WHERE mii.milestone_id = ? AND me.user_id = ? AND me.qr_group_id = ?
             ORDER BY mii.id ASC'
        );

        foreach ($milestones as $loadedMilestone) {
            $imageStmt->execute([(int) $loadedMilestone['id'], (int) $user['id'], (int) $qrGroup['id']]);
            $milestoneImages[(int) $loadedMilestone['id']] = $imageStmt->fetchAll();
        }
    }

    $stmt = $pdo->prepare(
        'SELECT mm.*
         FROM memorial_messages mm
         INNER JOIN memorials me ON me.id = mm.memorial_id
         WHERE mm.memorial_id = ? AND me.user_id = ? AND me.qr_group_id = ?
         ORDER BY mm.status ASC, mm.created_at DESC'
    );
    $stmt->execute([(int) $memorial['id'], (int) $user['id'], (int) $qrGroup['id']]);
    $memorialMessages = $stmt->fetchAll();
}

$flash = get_flash();
$previewUrl = app_base_url() . '/memorial.php?t=' . urlencode($qrGroup['public_token']);
$qrUrl = $previewUrl !== '' ? 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($previewUrl) : '';
$additionalMemorialPrice = qr_additional_memorial_price($qrGroup);
$additionalCost = max(0, count($memorials) - 1) * $additionalMemorialPrice;
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | AlaalaMo</title>
    <link rel="stylesheet" href="styles.css?v=<?= urlencode(defined('ASSET_VERSION') ? ASSET_VERSION : '20260513-34') ?>">
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
          Fill up the memorial profile first, then add up to <?= $planLimits['milestones'] ?> life milestones.
          Each milestone can include up to <?= $planLimits['milestone_images'] ?> images. When saved, AlaalaMo
          generates one private QR that can hold up to <?= MAX_MEMORIALS_PER_QR ?> memorial pages.
        </p>
        <p class="field-note">
          Current plan: <?= htmlspecialchars(ucfirst($planType), ENT_QUOTES, 'UTF-8') ?>.
          Gallery limit: <?= $planLimits['gallery_images'] ?> images.
          Milestone text limit: <?= $planLimits['milestone_characters'] ?> characters.
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
          <p><?= count($memorials) ?> of <?= MAX_MEMORIALS_PER_QR ?> memorials in this QR. Extra <?= htmlspecialchars($planType, ENT_QUOTES, 'UTF-8') ?> memorials are PHP <?= number_format($additionalMemorialPrice) ?> each.</p>
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
          <p>
            One QR can include up to <?= MAX_MEMORIALS_PER_QR ?> memorial pages.
            Each additional <?= htmlspecialchars($planType, ENT_QUOTES, 'UTF-8') ?> memorial is PHP <?= number_format($additionalMemorialPrice) ?> per year.
          </p>
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
              Profile photos
              <input type="file" name="profile_images[]" accept="image/jpeg,image/png,image/webp" multiple>
              <span class="field-note"><?= count($profileImages) ?> of <?= $planLimits['profile_images'] ?> profile photos used. These photos power the hero slideshow.</span>
            </label>
            <div class="image-preview-list form-full">
              <?php if ($profileImages): ?>
                <?php foreach ($profileImages as $image): ?>
                  <div class="image-preview-item">
                    <img src="<?= htmlspecialchars($image['image_path'], ENT_QUOTES, 'UTF-8') ?>" alt="Profile image preview">
                    <button class="image-delete-link" type="button" data-image-delete="profile:<?= (int) $image['id'] ?>">Delete</button>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <p>No profile photos yet.</p>
              <?php endif; ?>
            </div>
            <div class="theme-picker form-full">
              <div>
                <h3>Memorial color theme</h3>
                <p>Choose the colors used on the QR memorial page.</p>
              </div>
              <label>
                Primary
                <input type="color" name="theme_primary" value="<?= htmlspecialchars($memorial['theme_primary'] ?? '#214c63', ENT_QUOTES, 'UTF-8') ?>">
                <span class="field-note">Main background and headings</span>
              </label>
              <label>
                Secondary
                <input type="color" name="theme_secondary" value="<?= htmlspecialchars($memorial['theme_secondary'] ?? '#eadcc8', ENT_QUOTES, 'UTF-8') ?>">
                <span class="field-note">Buttons and quote line</span>
              </label>
              <label>
                Tertiary
                <input type="color" name="theme_tertiary" value="<?= htmlspecialchars($memorial['theme_tertiary'] ?? '#fbfaf7', ENT_QUOTES, 'UTF-8') ?>">
                <span class="field-note">Page background</span>
              </label>
            </div>
            <button class="button-primary form-submit" type="submit">Save Memorial Details</button>
          </div>
        </section>

        <section class="form-section">
          <h2>Life Milestones</h2>
          <p>
            Add up to <?= $planLimits['milestones'] ?> milestones. Each one can have up to
            <?= $planLimits['milestone_images'] ?> images and <?= $planLimits['milestone_characters'] ?> characters.
          </p>

          <?php for ($i = 0; $i < $planLimits['milestones']; $i++): ?>
            <?php $milestone = $milestones[$i] ?? null; ?>
            <?php $imagesForMilestone = $milestone ? ($milestoneImages[(int) $milestone['id']] ?? []) : []; ?>
            <div class="milestone-box" data-milestone-box>
              <h3>Milestone <?= $i + 1 ?></h3>
              <div class="form-grid">
                <input type="hidden" name="milestone_id[]" data-milestone-id value="<?= (int) ($milestone['id'] ?? 0) ?>">
                <input type="hidden" data-sort-order value="<?= $i ?>">
                <label>
                  Title
                  <input type="text" name="milestone_title[]" data-milestone-title value="<?= htmlspecialchars($milestone['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Example: Built a family, Started a business">
                </label>
                <label>
                  Date or period
                  <input type="text" name="milestone_date[]" data-milestone-date value="<?= htmlspecialchars($milestone['milestone_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Example: 1975, Childhood, 1990s">
                </label>
                <label class="form-full">
                  Description
                  <textarea name="milestone_description[]" data-milestone-description rows="3" maxlength="<?= $planLimits['milestone_characters'] ?>" placeholder="What happened in this chapter of their life?"><?= htmlspecialchars($milestone['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                  <span class="field-note">Maximum <?= $planLimits['milestone_characters'] ?> characters.</span>
                </label>
                <label class="form-full">
                  Milestone images
                  <?php if ($milestone): ?>
                    <input type="file" name="milestone_images[]" data-milestone-images accept="image/jpeg,image/png,image/webp" multiple>
                    <span class="field-note">Maximum <?= $planLimits['milestone_images'] ?> images for this milestone. This upload will not change the QR code.</span>
                  <?php else: ?>
                    <input type="file" name="milestone_images[]" data-milestone-images accept="image/jpeg,image/png,image/webp" multiple disabled>
                    <span class="field-note">Save this milestone first, then upload images for it.</span>
                  <?php endif; ?>
                </label>
                <div class="image-preview-list form-full" data-milestone-preview>
                  <?php if ($imagesForMilestone): ?>
                    <?php foreach ($imagesForMilestone as $image): ?>
                      <div class="image-preview-item">
                        <img src="<?= htmlspecialchars($image['image_path'], ENT_QUOTES, 'UTF-8') ?>" alt="Milestone image preview">
                        <button class="image-delete-link" type="button" data-image-delete="milestone:<?= (int) $image['id'] ?>">Delete</button>
                      </div>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <p>No milestone images yet.</p>
                  <?php endif; ?>
                </div>
                <div class="milestone-ajax-actions form-full">
                  <button class="button-secondary milestone-save-button" type="button" data-save-milestone>Save This Milestone</button>
                  <button class="button-secondary milestone-upload-button" type="button" data-upload-milestone <?= $milestone ? '' : 'disabled' ?>>Upload Images for This Milestone</button>
                  <button class="image-delete-link milestone-delete-button" type="button" data-delete-milestone <?= $milestone ? '' : 'disabled' ?>>Delete This Milestone</button>
                  <span class="milestone-ajax-status" data-milestone-status></span>
                </div>
              </div>
            </div>
          <?php endfor; ?>
        </section>

        <section class="form-section">
          <h2>Gallery</h2>
          <p>
            Upload stacked photos for the memorial gallery.
            <?= $planType === 'premium' ? 'Premium supports up to 20 gallery images.' : 'Regular supports up to 6 gallery images.' ?>
          </p>
          <div class="form-grid">
            <label class="form-full">
              Gallery images
              <input type="file" name="gallery_images[]" accept="image/jpeg,image/png,image/webp" multiple>
              <span class="field-note"><?= count($galleryImages) ?> of <?= $planLimits['gallery_images'] ?> gallery images used.</span>
            </label>
            <div class="image-preview-list form-full">
              <?php if ($galleryImages): ?>
                <?php foreach ($galleryImages as $image): ?>
                  <div class="image-preview-item">
                    <img src="<?= htmlspecialchars($image['image_path'], ENT_QUOTES, 'UTF-8') ?>" alt="Gallery image preview">
                    <button class="image-delete-link" type="button" data-image-delete="gallery:<?= (int) $image['id'] ?>">Delete</button>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <p>No gallery images yet.</p>
              <?php endif; ?>
            </div>
          </div>
        </section>
      </form>

      <?php if ($memorial): ?>
        <section class="memorial-form" id="messages-admin">
          <div class="form-section">
            <h2>Messages of Love</h2>
            <p>Approve messages before they appear on the public memorial page.</p>
            <div class="admin-message-list">
              <?php if ($memorialMessages): ?>
                <?php foreach ($memorialMessages as $loveMessage): ?>
                  <article class="admin-message-card">
                    <div>
                      <span class="message-status message-status-<?= htmlspecialchars($loveMessage['status'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars(ucfirst($loveMessage['status']), ENT_QUOTES, 'UTF-8') ?>
                      </span>
                      <h3><?= htmlspecialchars($loveMessage['sender_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                      <p><?= nl2br(htmlspecialchars($loveMessage['message'], ENT_QUOTES, 'UTF-8')) ?></p>
                      <small><?= htmlspecialchars($loveMessage['sender_email'], ENT_QUOTES, 'UTF-8') ?></small>
                    </div>
                    <div class="admin-message-actions">
                      <?php if ($loveMessage['status'] !== 'approved'): ?>
                        <form method="post" action="dashboard.php">
                          <input type="hidden" name="form_action" value="approve_message">
                          <input type="hidden" name="memorial_id" value="<?= (int) $memorial['id'] ?>">
                          <input type="hidden" name="message_id" value="<?= (int) $loveMessage['id'] ?>">
                          <button class="button-primary" type="submit">Approve</button>
                        </form>
                      <?php endif; ?>
                      <form method="post" action="dashboard.php">
                        <input type="hidden" name="form_action" value="delete_message">
                        <input type="hidden" name="memorial_id" value="<?= (int) $memorial['id'] ?>">
                        <input type="hidden" name="message_id" value="<?= (int) $loveMessage['id'] ?>">
                        <button class="image-delete-link" type="submit">Delete</button>
                      </form>
                    </div>
                  </article>
                <?php endforeach; ?>
              <?php else: ?>
                <p class="field-note">No messages submitted yet.</p>
              <?php endif; ?>
            </div>
          </div>
        </section>
      <?php endif; ?>

      <?php if ($memorial && $planLimits['life_story']): ?>
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
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script>
      $(function () {
        const memorialId = <?= (int) ($memorial['id'] ?? 0) ?>;

        function setStatus($box, message, type) {
          const $status = $box.find('[data-milestone-status]');
          $status.removeClass('is-success is-error').addClass(type === 'error' ? 'is-error' : 'is-success').text(message);
        }

        function ajaxErrorMessage(xhr, fallback) {
          return (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : fallback;
        }

        $('[data-save-milestone]').on('click', function () {
          const $button = $(this);
          const $box = $button.closest('[data-milestone-box]');

          $button.prop('disabled', true);
          setStatus($box, 'Saving...', 'success');

          $.ajax({
            url: 'dashboard.php',
            method: 'POST',
            dataType: 'json',
            data: {
              ajax: '1',
              form_action: 'save_milestone',
              memorial_id: memorialId,
              milestone_id: $box.find('[data-milestone-id]').val(),
              sort_order: $box.find('[data-sort-order]').val(),
              title: $box.find('[data-milestone-title]').val(),
              milestone_date: $box.find('[data-milestone-date]').val(),
              description: $box.find('[data-milestone-description]').val()
            }
          }).done(function (response) {
            $box.find('[data-milestone-id]').val(response.milestone_id);
            $box.find('[data-milestone-images], [data-upload-milestone], [data-delete-milestone]').prop('disabled', false);
            $box.find('[data-milestone-images]').siblings('.field-note').text('Maximum <?= $planLimits['milestone_images'] ?> images for this milestone. This upload will not change the QR code.');
            setStatus($box, response.message || 'Milestone saved.', 'success');
          }).fail(function (xhr) {
            setStatus($box, ajaxErrorMessage(xhr, 'Milestone could not be saved.'), 'error');
          }).always(function () {
            $button.prop('disabled', false);
          });
        });

        $('[data-upload-milestone]').on('click', function () {
          const $button = $(this);
          const $box = $button.closest('[data-milestone-box]');
          const milestoneId = $box.find('[data-milestone-id]').val();
          const files = $box.find('[data-milestone-images]')[0].files;

          if (!milestoneId || milestoneId === '0') {
            setStatus($box, 'Save this milestone first.', 'error');
            return;
          }

          if (!files.length) {
            setStatus($box, 'Choose at least one image first.', 'error');
            return;
          }

          const formData = new FormData();
          formData.append('ajax', '1');
          formData.append('form_action', 'upload_milestone_images');
          formData.append('memorial_id', memorialId);
          formData.append('milestone_id', milestoneId);

          $.each(files, function (_, file) {
            formData.append('milestone_images[]', file);
          });

          $button.prop('disabled', true);
          setStatus($box, 'Uploading...', 'success');

          $.ajax({
            url: 'dashboard.php',
            method: 'POST',
            dataType: 'json',
            data: formData,
            processData: false,
            contentType: false
          }).done(function (response) {
            const $preview = $box.find('[data-milestone-preview]');
            $preview.find('p').remove();
            $preview.append(response.html || '');
            $box.find('[data-milestone-images]').val('');
            setStatus($box, response.message || 'Images uploaded.', 'success');
          }).fail(function (xhr) {
            setStatus($box, ajaxErrorMessage(xhr, 'Images could not be uploaded.'), 'error');
          }).always(function () {
            $button.prop('disabled', false);
          });
        });

        $('[data-delete-milestone]').on('click', function () {
          const $button = $(this);
          const $box = $button.closest('[data-milestone-box]');
          const milestoneId = $box.find('[data-milestone-id]').val();

          if (!milestoneId || milestoneId === '0') {
            setStatus($box, 'This milestone is not saved yet.', 'error');
            return;
          }

          if (!confirm('Delete this milestone and its images?')) {
            return;
          }

          $button.prop('disabled', true);
          setStatus($box, 'Deleting...', 'success');

          $.ajax({
            url: 'dashboard.php',
            method: 'POST',
            dataType: 'json',
            data: {
              ajax: '1',
              form_action: 'delete_milestone',
              memorial_id: memorialId,
              milestone_id: milestoneId
            }
          }).done(function (response) {
            $box.find('[data-milestone-id]').val('0');
            $box.find('[data-milestone-title], [data-milestone-date], [data-milestone-description]').val('');
            $box.find('[data-milestone-images]').val('').prop('disabled', true);
            $box.find('[data-upload-milestone], [data-delete-milestone]').prop('disabled', true);
            $box.find('[data-milestone-preview]').html('<p>No milestone images yet.</p>');
            $box.find('.field-note').text('Save this milestone first, then upload images for it.');
            setStatus($box, response.message || 'Milestone deleted.', 'success');
          }).fail(function (xhr) {
            $button.prop('disabled', false);
            setStatus($box, ajaxErrorMessage(xhr, 'Milestone could not be deleted.'), 'error');
          });
        });

        $(document).on('click', '[data-image-delete]', function () {
          const $button = $(this);
          const $item = $button.closest('.image-preview-item');
          const $box = $button.closest('[data-milestone-box]');

          $button.prop('disabled', true).text('Deleting...');

          $.ajax({
            url: 'dashboard.php',
            method: 'POST',
            dataType: 'json',
            data: {
              ajax: '1',
              form_action: 'delete_image',
              memorial_id: memorialId,
              image_delete: $button.data('image-delete')
            }
          }).done(function (response) {
            $item.remove();

            if ($box.length) {
              const $preview = $box.find('[data-milestone-preview]');

              if (!$preview.find('.image-preview-item').length) {
                $preview.html('<p>No milestone images yet.</p>');
              }

              setStatus($box, response.message || 'Image deleted.', 'success');
            }
          }).fail(function (xhr) {
            $button.prop('disabled', false).text('Delete');

            if ($box.length) {
              setStatus($box, ajaxErrorMessage(xhr, 'Image could not be deleted.'), 'error');
            } else {
              alert(ajaxErrorMessage(xhr, 'Image could not be deleted.'));
            }
          });
        });
      });
    </script>
  </body>
</html>

