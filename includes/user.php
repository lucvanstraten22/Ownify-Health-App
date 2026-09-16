<?php
/**
 * Users and profiles.
 *
 * Note the two different reads:
 *
 *   user_account($id)          everything the OWNER may see about themselves
 *   user_public_profile($id)   the only fields another user may see
 *
 * Anything that renders someone else — a leaderboard row, a friend search —
 * goes through the public one. That is the privacy boundary in code.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (!function_exists('user_validate_username')) {

    /* --------------------------------------------------------- username */

    function user_validate_username(string $username): ?string
    {
        $username = trim($username);

        if (mb_strlen($username) < 3 || mb_strlen($username) > 30) {
            return 'Kies een gebruikersnaam van 3 tot 30 tekens.';
        }

        if (!preg_match('/^[A-Za-z0-9._-]+$/', $username)) {
            return 'Gebruik alleen letters, cijfers, punt, streepje of underscore.';
        }

        return null;
    }

    /** The unique key is case-insensitive, so this check is too. */
    function user_username_taken(string $username, ?int $ignoreUserId = null): bool
    {
        $sql = 'SELECT id FROM users WHERE username = ?';
        $params = [trim($username)];

        if ($ignoreUserId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $ignoreUserId;
        }

        return db_value($sql, $params) !== null;
    }

    function user_update_username(int $userId, string $username): array
    {
        $username = trim($username);

        $error = user_validate_username($username);
        if ($error !== null) {
            return ['ok' => false, 'error' => $error];
        }

        if (user_username_taken($username, $userId)) {
            return ['ok' => false, 'error' => 'Deze gebruikersnaam is al bezet.'];
        }

        try {
            db_run('UPDATE users SET username = ? WHERE id = ?', [$username, $userId]);
        } catch (PDOException $e) {
            // Lost a race against another registration on the unique key.
            return ['ok' => false, 'error' => 'Deze gebruikersnaam is al bezet.'];
        }

        return ['ok' => true, 'error' => null, 'username' => $username];
    }

    /* ----------------------------------------------------------- reading */

    /** The owner's own account. Private — never render this for someone else. */
    function user_account(int $userId): ?array
    {
        $row = db_one(
            'SELECT u.id, u.username, u.status, u.created_at, u.last_seen_at,
                    p.first_name, p.last_name, p.date_of_birth, p.gender,
                    p.avatar_path, p.locale
               FROM users u
          LEFT JOIN user_profiles p ON p.user_id = u.id
              WHERE u.id = ? AND u.status <> ?',
            [$userId, 'deleted']
        );

        if ($row === null) {
            return null;
        }

        $row['id'] = (int) $row['id'];
        $row['age'] = user_age($row['date_of_birth']);
        $row['height'] = user_current_measurement($userId, 'height');
        $row['weight'] = user_current_measurement($userId, 'weight');

        return $row;
    }

    /**
     * What another user is allowed to see: a handle and a picture.
     * No name, no birth date, no measurement, no health data.
     */
    function user_public_profile(int $userId): ?array
    {
        $row = db_one(
            'SELECT u.id, u.username, p.avatar_path
               FROM users u
          LEFT JOIN user_profiles p ON p.user_id = u.id
              WHERE u.id = ? AND u.status = ?',
            [$userId, 'active']
        );

        if ($row === null) {
            return null;
        }

        return [
            'id'       => (int) $row['id'],
            'username' => $row['username'],
            'avatar'   => $row['avatar_path'],
        ];
    }

    /** Finding friends by handle. Returns public fields only. */
    function user_search_by_username(string $query, int $limit = 20): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $rows = db_all(
            'SELECT u.id, u.username, p.avatar_path
               FROM users u
          LEFT JOIN user_profiles p ON p.user_id = u.id
              WHERE u.status = ? AND u.username LIKE ?
              ORDER BY CHAR_LENGTH(u.username), u.username
              LIMIT ' . (int) max(1, min($limit, 50)),
            ['active', $query . '%']
        );

        return array_map(static fn (array $r): array => [
            'id'       => (int) $r['id'],
            'username' => $r['username'],
            'avatar'   => $r['avatar_path'],
        ], $rows);
    }

    /** Derived, never stored, so it cannot drift from the birth date. */
    function user_age(?string $dateOfBirth): ?int
    {
        if (!$dateOfBirth) {
            return null;
        }

        try {
            $born = new DateTimeImmutable($dateOfBirth);
        } catch (Exception $e) {
            return null;
        }

        return (int) $born->diff(new DateTimeImmutable('today'))->y;
    }

    /* ------------------------------------------------------- measurements */

    /** Current value = newest row. The older rows stay for the history. */
    function user_current_measurement(int $userId, string $type): ?array
    {
        $row = db_one(
            'SELECT value, unit, measured_at
               FROM user_measurements
              WHERE user_id = ? AND measurement_type = ?
              ORDER BY measured_at DESC
              LIMIT 1',
            [$userId, $type]
        );

        if ($row === null) {
            return null;
        }

        return ['value' => (float) $row['value'], 'unit' => $row['unit'], 'measured_at' => $row['measured_at']];
    }

    function user_measurement_history(int $userId, string $type, int $limit = 100): array
    {
        return db_all(
            'SELECT value, unit, measured_at
               FROM user_measurements
              WHERE user_id = ? AND measurement_type = ?
              ORDER BY measured_at DESC
              LIMIT ' . (int) max(1, min($limit, 500)),
            [$userId, $type]
        );
    }

    /** Recording a new value never overwrites the old one. */
    function user_record_measurement(
        int $userId,
        string $type,
        float $value,
        string $unit,
        string $sourceCode = 'manual',
        ?string $measuredAt = null
    ): ?int {
        require_once __DIR__ . '/health-data.php';

        db_run(
            'INSERT INTO user_measurements (user_id, measurement_type, value, unit, source_id, measured_at)
                  VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $type, $value, $unit, health_source_id($sourceCode), $measuredAt ?? date('Y-m-d H:i:s')]
        );

        return db_insert_id();
    }

    /* ------------------------------------------------------------ avatar */

    /**
     * Stores an uploaded profile picture.
     *
     * The client filename is never used and never trusted: the type is read
     * from the file's own bytes, and the stored name is generated here.
     */
    function user_set_avatar(int $userId, array $file, string $uploadRoot): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Uploaden is niet gelukt.'];
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            return ['ok' => false, 'error' => 'Ongeldige upload.'];
        }

        if (($file['size'] ?? 0) > 3 * 1024 * 1024) {
            return ['ok' => false, 'error' => 'Kies een afbeelding van maximaal 3 MB.'];
        }

        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($file['tmp_name']);

        if (!isset($allowed[$mime])) {
            return ['ok' => false, 'error' => 'Gebruik een JPG-, PNG- of WebP-afbeelding.'];
        }

        // Confirm it really decodes as an image, not just that it claims to.
        $dimensions = @getimagesize($file['tmp_name']);
        if ($dimensions === false) {
            return ['ok' => false, 'error' => 'Dit bestand is geen geldige afbeelding.'];
        }

        $directory = rtrim($uploadRoot, '/') . '/avatars';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return ['ok' => false, 'error' => 'Opslaan is niet gelukt.'];
        }

        $name = sprintf('u%d-%s.%s', $userId, bin2hex(random_bytes(8)), $allowed[$mime]);

        if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $name)) {
            return ['ok' => false, 'error' => 'Opslaan is niet gelukt.'];
        }

        $previous = db_value('SELECT avatar_path FROM user_profiles WHERE user_id = ?', [$userId]);
        $path = 'uploads/avatars/' . $name;

        db_run(
            'INSERT INTO user_profiles (user_id, avatar_path) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE avatar_path = VALUES(avatar_path)',
            [$userId, $path]
        );

        // Tidy up the replaced file, but only inside our own avatar directory.
        if (is_string($previous) && str_starts_with($previous, 'uploads/avatars/')) {
            $old = dirname(__DIR__) . '/' . $previous;
            if (is_file($old)) {
                @unlink($old);
            }
        }

        return ['ok' => true, 'error' => null, 'avatar' => $path];
    }
}
