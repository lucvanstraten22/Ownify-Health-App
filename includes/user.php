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

/** The side, in pixels, of the small copy of a profile picture the boards and friends lists show. */
if (!defined('AVATAR_SMALL_SIDE')) {
    define('AVATAR_SMALL_SIDE', 192);
}

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
                    p.activity_level, p.avatar_path, p.locale
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

    /* -------------------------------------------------------- profile */

    /** What a person may change about themselves after onboarding. */
    function user_update_profile(int $userId, array $fields): array
    {
        $set    = [];
        $params = [];

        /* The column name is taken from this map, never from the caller's
           array key. The loop is over literals either way, but an identifier
           cannot be a bound parameter, so the one place a name reaches SQL is
           worth pinning down rather than trusting the next edit. */
        $columns = ['first_name' => '`first_name`', 'last_name' => '`last_name`'];

        foreach ($columns as $key => $column) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }

            $value = trim((string) $fields[$key]);

            if (mb_strlen($value) > 60) {
                return ['ok' => false, 'error' => 'Die naam is te lang.'];
            }

            $set[]    = $column . ' = ?';
            $params[] = $value === '' ? null : $value;
        }

        if (array_key_exists('activity_level', $fields)) {
            $level = (string) $fields['activity_level'];
            $allowed = ['sedentary', 'light', 'moderate', 'active', 'athlete'];

            if ($level !== '' && !in_array($level, $allowed, true)) {
                return ['ok' => false, 'error' => 'Onbekend activiteitsniveau.'];
            }

            $set[]    = '`activity_level` = ?';
            $params[] = $level === '' ? null : $level;
        }

        if ($set === []) {
            return ['ok' => false, 'error' => 'Niets om op te slaan.'];
        }

        /* The profile row is created with the account, but an account made
           before that was guaranteed still has to be able to save. */
        db_run('INSERT IGNORE INTO user_profiles (user_id) VALUES (?)', [$userId]);

        $params[] = $userId;
        db_run('UPDATE user_profiles SET ' . implode(', ', $set) . ' WHERE user_id = ?', $params);

        return ['ok' => true, 'error' => null];
    }

    /**
     * Gender and date of birth, which onboarding sets and nothing else changes.
     *
     * The product rule is that these are not editable afterwards, so this
     * refuses rather than overwrites once a value is in place. Enforcing it
     * here rather than by hiding the field is what makes it true: a hidden
     * field is a request away from being sent anyway.
     */
    function user_set_onboarding_facts(int $userId, ?string $dateOfBirth, ?string $gender): array
    {
        db_run('INSERT IGNORE INTO user_profiles (user_id) VALUES (?)', [$userId]);

        $current = db_one(
            'SELECT date_of_birth, gender FROM user_profiles WHERE user_id = ?',
            [$userId]
        ) ?? ['date_of_birth' => null, 'gender' => 'undisclosed'];

        $set    = [];
        $params = [];

        if ($dateOfBirth !== null && $dateOfBirth !== '') {
            if ($current['date_of_birth'] !== null) {
                return ['ok' => false, 'error' => 'Je geboortedatum staat al vast.'];
            }

            $date = DateTimeImmutable::createFromFormat('Y-m-d', $dateOfBirth);

            if ($date === false || $date->format('Y-m-d') !== $dateOfBirth) {
                return ['ok' => false, 'error' => 'Vul een geldige geboortedatum in.'];
            }

            $age = (int) $date->diff(new DateTimeImmutable('today'))->y;

            if ($date > new DateTimeImmutable('today') || $age > 120) {
                return ['ok' => false, 'error' => 'Vul een geldige geboortedatum in.'];
            }

            $set[]    = '`date_of_birth` = ?';
            $params[] = $dateOfBirth;
        }

        if ($gender !== null && $gender !== '') {
            if (($current['gender'] ?? 'undisclosed') !== 'undisclosed') {
                return ['ok' => false, 'error' => 'Je geslacht staat al vast.'];
            }

            if (!in_array($gender, ['female', 'male', 'non_binary', 'other', 'undisclosed'], true)) {
                return ['ok' => false, 'error' => 'Onbekende waarde.'];
            }

            $set[]    = '`gender` = ?';
            $params[] = $gender;
        }

        if ($set === []) {
            return ['ok' => false, 'error' => 'Niets om op te slaan.'];
        }

        $params[] = $userId;
        db_run('UPDATE user_profiles SET ' . implode(', ', $set) . ' WHERE user_id = ?', $params);

        return ['ok' => true, 'error' => null];
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

        // The small copy the boards and friends lists show, made now.
        avatar_small($path);

        // Tidy up the replaced file and its small copy, but only inside our own avatar directory.
        if (is_string($previous) && str_starts_with($previous, 'uploads/avatars/') && !str_contains($previous, '..')) {
            $old = dirname(__DIR__) . '/' . $previous;
            foreach ([$old, ...(glob(preg_replace('/\.[a-z]+$/', '', $old) . '-s.*') ?: [])] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }

        return ['ok' => true, 'error' => null, 'avatar' => $path];
    }

    /* ------------------------------------------------- the small copy */

    /**
     * Where a picture's small copy lives: beside it, same name plus "-s".
     * WebP where this PHP can write it, JPEG otherwise.
     */
    function avatar_small_path(string $path): string
    {
        $webp = function_exists('imagewebp') && (imagetypes() & IMG_WEBP) !== 0;

        return preg_replace('/\.[a-z]+$/', '', $path) . '-s.' . ($webp ? 'webp' : 'jpg');
    }

    /**
     * The picture to show in a small circle — a board row, a friend — as a
     * square copy of AVATAR_SMALL_SIDE pixels instead of the upload itself
     * (up to 3 MB). Made at upload; a picture from before that gets its copy
     * the first time it is shown. Whenever a copy cannot be made (no GD, a
     * file it cannot read) the original is shown, as before.
     */
    function avatar_small(?string $path): ?string
    {
        static $known = [];

        if ($path === null || $path === '') {
            return null;
        }

        if (!str_starts_with($path, 'uploads/avatars/') || str_contains($path, '..')) {
            return $path;
        }

        if (!array_key_exists($path, $known)) {
            $root  = dirname(__DIR__) . '/';
            $small = avatar_small_path($path);

            $known[$path] = is_file($root . $small) || avatar_make_small($root . $path, $root . $small)
                ? $small
                : $path;
        }

        return $known[$path];
    }

    /** Every row's avatar_path as its small copy. */
    function avatar_small_rows(array $rows): array
    {
        foreach ($rows as $i => $row) {
            if (array_key_exists('avatar_path', $row)) {
                $rows[$i]['avatar_path'] = avatar_small($row['avatar_path']);
            }
        }

        return $rows;
    }

    /**
     * Writes the small copy: the centre square — what the round frames show
     * of the original anyway — turned upright the way a phone photo says it
     * should be, scaled down to AVATAR_SMALL_SIDE (never up).
     */
    function avatar_make_small(string $source, string $target): bool
    {
        if (!function_exists('imagecreatetruecolor') || !is_file($source)) {
            return false;
        }

        $info = @getimagesize($source);
        if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 40_000_000) {
            return false;
        }

        // A decoded picture takes about 5 bytes a pixel; make room, or leave it.
        if (!avatar_memory_for((int) ($info[0] * $info[1] * 5) + 16 * 1024 * 1024)) {
            return false;
        }

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG  => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            default        => false,
        };

        if ($image === false) {
            return false;
        }

        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($source);
            $image = avatar_upright($image, (int) ($exif['Orientation'] ?? 1));
        }

        $width  = imagesx($image);
        $height = imagesy($image);
        $square = min($width, $height);
        $side   = min(AVATAR_SMALL_SIDE, $square);
        $webp   = str_ends_with($target, '.webp');

        $small = imagecreatetruecolor($side, $side);
        if ($webp) {
            imagealphablending($small, false);
            imagesavealpha($small, true);
            imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
        } else {
            imagefill($small, 0, 0, imagecolorallocate($small, 255, 255, 255));
        }

        imagecopyresampled($small, $image, 0, 0,
            intdiv($width - $square, 2), intdiv($height - $square, 2), $side, $side, $square, $square);
        imagedestroy($image);

        // Written aside and moved into place, so nobody is ever sent half a file.
        $partial = $target . '.' . bin2hex(random_bytes(4)) . '.part';
        $written = $webp ? @imagewebp($small, $partial, 82) : @imagejpeg($small, $partial, 85);
        imagedestroy($small);

        if (!$written || !@rename($partial, $target)) {
            @unlink($partial);
            return false;
        }

        return true;
    }

    /** Turns a JPEG the way its EXIF orientation (1–8) says. */
    function avatar_upright(\GdImage $image, int $orientation): \GdImage
    {
        $turned = match ($orientation) {
            3, 4    => imagerotate($image, 180, 0),
            5, 6    => imagerotate($image, -90, 0),
            7, 8    => imagerotate($image, 90, 0),
            default => $image,
        };

        if ($turned === false) {
            return $image;
        }

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($turned, IMG_FLIP_HORIZONTAL);
        }

        return $turned;
    }

    /** Whether $bytes more fit under memory_limit, raising it if that is allowed. */
    function avatar_memory_for(int $bytes): bool
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return true;
        }

        $value = (int) $limit;
        $value *= match (strtolower(substr($limit, -1))) {
            'g'     => 1024 ** 3,
            'm'     => 1024 ** 2,
            'k'     => 1024,
            default => 1,
        };

        $needed = memory_get_usage() + $bytes;

        return $needed <= $value || ini_set('memory_limit', (string) $needed) !== false;
    }

    /* ---------------------------------------------------------- deleting */

    /**
     * Deletes an account and everything that belongs to it. For good.
     *
     * One statement: every table that holds something of a user's references
     * users with ON DELETE CASCADE — the profile, every way of signing in
     * (the password and the Google link alike), health data, goals and their
     * history, measurements, paired phones and their tokens, pairing codes,
     * integrations, friendships in both directions, blocks, points and
     * leaderboard positions. Nothing is kept aside, and nothing is marked
     * deleted instead: the rows are gone.
     *
     * The profile picture is a file rather than a row, so it is removed by
     * hand — the current one, and any older one a failed tidy-up left behind.
     *
     * Returns the Google account id that was linked, if any, so the caller can
     * also ask Google to forget Ownify. It is not stored anywhere any more.
     *
     * @return array{ok: bool, error: ?string, google_sub: ?string}
     */
    function user_delete_account(int $userId, string $uploadRoot): array
    {
        if (!db_available()) {
            return ['ok' => false, 'error' => 'Geen databaseverbinding.', 'google_sub' => null];
        }

        $googleSub = db_value(
            'SELECT provider_subject FROM user_auth_identities WHERE user_id = ? AND provider = ?',
            [$userId, 'google']
        );
        $avatar = db_value('SELECT avatar_path FROM user_profiles WHERE user_id = ?', [$userId]);

        $statement = db_run('DELETE FROM users WHERE id = ?', [$userId]);

        if ($statement === null || $statement->rowCount() !== 1) {
            return ['ok' => false, 'error' => 'Dit account kon niet worden verwijderd.', 'google_sub' => null];
        }

        /* Files: only ever inside our own avatar directory, and only this
           user's — u12- never matches u123-. */
        $directory = rtrim($uploadRoot, '/') . '/avatars';
        $files     = glob($directory . '/u' . $userId . '-*') ?: [];

        if (is_string($avatar) && str_starts_with($avatar, 'uploads/avatars/')) {
            $files[] = dirname(__DIR__) . '/' . $avatar;
        }

        foreach (array_unique($files) as $file) {
            if (is_file($file) && str_starts_with(basename($file), 'u' . $userId . '-')) {
                @unlink($file);
            }
        }

        return ['ok' => true, 'error' => null, 'google_sub' => is_string($googleSub) ? $googleSub : null];
    }
}
