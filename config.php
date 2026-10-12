<?php
// ====================== SMARTVOTE MAIN CONFIG ======================

define('BASE_PATH', dirname(__FILE__));
define('PUBLIC_PATH', BASE_PATH . '/public');

$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$scheme = $is_https ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$base_path = preg_replace('#/(admin|vote)$#', '', $script_dir);
$base_path = $base_path === '/' ? '' : rtrim($base_path, '/');

define('BASE_URL', $scheme . '://' . $host . $base_path);

// Include Database
require_once BASE_PATH . '/app/config/database.php';
require_once BASE_PATH . '/vendor/autoload.php';

/** Store a predictable username while retaining the user's chosen letter casing after its first character. */
function normalize_username($username) {
    $username = trim((string)$username);
    return $username === '' ? '' : strtoupper($username[0]) . substr($username, 1);
}

// Start Session
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $is_https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Keep older local databases compatible as features are added.
function db_has_column($pdo, $table, $column) {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch();
    } catch (Exception $e) {
        return false;
    }
}

function db_add_column_if_missing($pdo, $table, $column, $definition) {
    if (!db_has_column($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN $definition");
    }
}

function ensure_smartvote_schema($pdo) {
    try {
        db_add_column_if_missing($pdo, 'elections', 'code', "`code` VARCHAR(7) NULL UNIQUE AFTER `slug`");
        db_add_column_if_missing($pdo, 'elections', 'voter_verification_field', "`voter_verification_field` VARCHAR(100) NULL AFTER `voter_id_type`");
        db_add_column_if_missing($pdo, 'elections', 'voter_id_example', "`voter_id_example` VARCHAR(100) NULL AFTER `voter_id_type`");
        db_add_column_if_missing($pdo, 'elections', 'voter_id_format', "`voter_id_format` VARCHAR(100) NULL AFTER `voter_id_example`");
        db_add_column_if_missing($pdo, 'elections', 'organization_logo', "`organization_logo` VARCHAR(255) NULL AFTER `organization_name`");
        db_add_column_if_missing($pdo, 'elections', 'organization_type', "`organization_type` VARCHAR(100) NULL AFTER `organization_name`");
        db_add_column_if_missing($pdo, 'elections', 'organization_email', "`organization_email` VARCHAR(190) NULL AFTER `organization_type`");
        db_add_column_if_missing($pdo, 'elections', 'organization_phone', "`organization_phone` VARCHAR(50) NULL AFTER `organization_email`");
        db_add_column_if_missing($pdo, 'elections', 'organization_address', "`organization_address` VARCHAR(255) NULL AFTER `organization_phone`");
        db_add_column_if_missing($pdo, 'elections', 'organization_country', "`organization_country` VARCHAR(100) NULL AFTER `organization_address`");
        db_add_column_if_missing($pdo, 'candidate_roles', 'role_type', "`role_type` VARCHAR(20) NOT NULL DEFAULT 'multiple' AFTER `role_name`");
        db_add_column_if_missing($pdo, 'candidate_roles', 'proposition_name', "`proposition_name` VARCHAR(190) NULL AFTER `role_type`");
        db_add_column_if_missing($pdo, 'candidate_roles', 'proposition_description', "`proposition_description` TEXT NULL AFTER `proposition_name`");
        db_add_column_if_missing($pdo, 'candidate_roles', 'proposition_image_path', "`proposition_image_path` VARCHAR(255) NULL AFTER `proposition_description`");
        db_add_column_if_missing($pdo, 'candidates', 'image_path', "`image_path` VARCHAR(255) NULL AFTER `description`");
        db_add_column_if_missing($pdo, 'votes', 'role_id', "`role_id` INT NULL AFTER `user_id`");
        db_add_column_if_missing($pdo, 'votes', 'voter_identifier', "`voter_identifier` VARCHAR(100) NULL AFTER `role_id`");
        db_add_column_if_missing($pdo, 'votes', 'email', "`email` VARCHAR(190) NULL AFTER `voter_identifier`");
        db_add_column_if_missing($pdo, 'votes', 'voted_at', "`voted_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP");
        db_add_column_if_missing($pdo, 'users', 'username', "`username` VARCHAR(80) NULL UNIQUE AFTER `full_name`");
        db_add_column_if_missing($pdo, 'valid_voters', 'identifier_label', "`identifier_label` VARCHAR(100) NOT NULL DEFAULT 'Name' AFTER `staff_id`");
        db_add_column_if_missing($pdo, 'valid_voters', 'identifier_value', "`identifier_value` VARCHAR(190) NOT NULL DEFAULT '' AFTER `identifier_label`");
        db_add_column_if_missing($pdo, 'valid_voters', 'source_data', "`source_data` TEXT NULL AFTER `phone`");

        // Legacy accounts receive a consistently first-letter-capitalized account identifier.
        $pdo->exec("UPDATE users SET username = CONCAT('User_', id) WHERE username IS NULL OR username = '' OR username = email OR username REGEXP '^USER_[0-9]+$'");
        // Existing accounts are retained without a gender value; new registrations require one.
        db_add_column_if_missing($pdo, 'users', 'gender', "`gender` VARCHAR(1) NULL AFTER `index_number`");
        // First-run account: this is created only when the Admin username does not exist.
        // The password is never stored in plaintext.
        $adminCheck = $pdo->prepare('SELECT id FROM users WHERE BINARY username = BINARY ? LIMIT 1');
        $adminCheck->execute(['Admin']);
        if (!$adminCheck->fetch()) {
            $createAdmin = $pdo->prepare("INSERT INTO users (full_name, username, email, password, role, index_number) VALUES (?, ?, ?, ?, 'admin', NULL)");
            $createAdmin->execute([
                'Admin',
                'Admin',
                'admin@smartvote.local',
                password_hash('123456789', PASSWORD_DEFAULT)
            ]);
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS vote_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            election_id INT NULL,
            voter_identifier VARCHAR(100) NULL,
            ip_address VARCHAR(64) NULL,
            email VARCHAR(190) NULL,
            user_agent TEXT NULL,
            action_attempted VARCHAR(120) NOT NULL,
            attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            status VARCHAR(40) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS otp_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(190) NOT NULL,
            code VARCHAR(10) NOT NULL,
            election_id INT NULL,
            voter_identifier VARCHAR(100) NULL,
            expires_at DATETIME NOT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX(email),
            INDEX(election_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS valid_voters (
            id INT AUTO_INCREMENT PRIMARY KEY,
            election_id INT NOT NULL,
            full_name VARCHAR(190) NOT NULL,
            email VARCHAR(190) NOT NULL,
            staff_id VARCHAR(100) NOT NULL,
            identifier_label VARCHAR(100) NOT NULL DEFAULT 'Name',
            identifier_value VARCHAR(190) NOT NULL DEFAULT '',
            phone VARCHAR(50) NULL,
            imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_valid_voter_election_staff (election_id, staff_id),
            UNIQUE KEY uq_valid_voter_election_email (election_id, email),
            INDEX idx_valid_voters_election (election_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // A receipt is deliberately separate from individual vote rows: its unique keys make a
        // second completed ballot impossible even when an election has several positions.
        $pdo->exec("CREATE TABLE IF NOT EXISTS vote_receipts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            election_id INT NOT NULL,
            user_id INT NOT NULL,
            voter_identifier VARCHAR(100) NOT NULL,
            cast_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_receipt_election_user (election_id, user_id),
            UNIQUE KEY uq_receipt_election_identifier (election_id, voter_identifier),
            INDEX idx_receipt_election (election_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // One review per voter/election is enforced by the unique key, including for concurrent requests.
        $pdo->exec("CREATE TABLE IF NOT EXISTS election_reviews (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            election_id INT NOT NULL,
            rating TINYINT UNSIGNED NOT NULL,
            comment TEXT NOT NULL,
            submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_election_review_user (election_id, user_id),
            INDEX idx_election_reviews_election (election_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            election_id INT NULL,
            event_type VARCHAR(100) NOT NULL,
            event_detail VARCHAR(255) NOT NULL,
            ip_address VARCHAR(64) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_audit_election (election_id),
            INDEX idx_audit_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS election_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            election_id INT NOT NULL,
            report_body TEXT NOT NULL,
            emailed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY election_id (election_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS login_activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, logged_in_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ip_address VARCHAR(64) NULL, INDEX idx_login_user (user_id, logged_in_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS election_creation_requests (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, full_name VARCHAR(190) NOT NULL,
            email VARCHAR(190) NOT NULL, organization_name VARCHAR(190) NOT NULL, reason TEXT NOT NULL,
            status ENUM('pending','approved','rejected','used') NOT NULL DEFAULT 'pending',
            reviewed_by INT NULL, reviewed_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_request_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db_add_column_if_missing($pdo, 'election_creation_requests', 'organization_type', "`organization_type` VARCHAR(100) NULL AFTER `organization_name`");
        db_add_column_if_missing($pdo, 'election_creation_requests', 'organization_email', "`organization_email` VARCHAR(190) NULL AFTER `organization_type`");
        db_add_column_if_missing($pdo, 'election_creation_requests', 'organization_phone', "`organization_phone` VARCHAR(50) NULL AFTER `organization_email`");
        db_add_column_if_missing($pdo, 'election_creation_requests', 'organization_address', "`organization_address` VARCHAR(255) NULL AFTER `organization_phone`");
        db_add_column_if_missing($pdo, 'election_creation_requests', 'organization_country', "`organization_country` VARCHAR(100) NULL AFTER `organization_address`");
        db_add_column_if_missing($pdo, 'election_creation_requests', 'organization_logo', "`organization_logo` VARCHAR(255) NULL AFTER `organization_country`");
        $pdo->exec("CREATE TABLE IF NOT EXISTS election_committee_members (
            id INT AUTO_INCREMENT PRIMARY KEY, election_id INT NOT NULL, full_name VARCHAR(190) NOT NULL,
            email VARCHAR(190) NULL, user_id INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_committee_election (election_id), INDEX idx_committee_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db_add_column_if_missing($pdo, 'election_committee_members', 'status', "`status` VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER `user_id`");
        db_add_column_if_missing($pdo, 'election_committee_members', 'invitation_token_hash', "`invitation_token_hash` CHAR(64) NULL AFTER `status`");
        db_add_column_if_missing($pdo, 'election_committee_members', 'confirmation_code_hash', "`confirmation_code_hash` CHAR(64) NULL AFTER `invitation_token_hash`");
        db_add_column_if_missing($pdo, 'election_committee_members', 'confirmation_expires_at', "`confirmation_expires_at` DATETIME NULL AFTER `confirmation_code_hash`");
        db_add_column_if_missing($pdo, 'election_committee_members', 'accepted_at', "`accepted_at` DATETIME NULL AFTER `confirmation_expires_at`");
        db_add_column_if_missing($pdo, 'election_committee_members', 'confirmed_at', "`confirmed_at` DATETIME NULL AFTER `accepted_at`");
        db_add_column_if_missing($pdo, 'election_committee_members', 'expires_at', "`expires_at` DATETIME NULL AFTER `confirmed_at`");
    } catch (Exception $e) {
        // Pages still render; a form action will show the database error if migration is required.
    }
}

ensure_smartvote_schema($pdo);

// ====================== HELPER FUNCTIONS ======================
function url($path = '') {
    return BASE_URL . '/' . ltrim($path, '/');
}

function redirect($url) {
    header("Location: " . url($url));
    exit;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function has_approved_creation_request($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT id FROM election_creation_requests WHERE user_id = ? AND status = 'approved' LIMIT 1");
    $stmt->execute([$user_id]);
    return (bool)$stmt->fetch();
}

function can_create_election($pdo) { return isAdmin() || has_approved_creation_request($pdo, $_SESSION['user_id'] ?? 0); }

function user_has_created_election($pdo, $user_id) {
    $stmt = $pdo->prepare('SELECT id FROM elections WHERE created_by = ? LIMIT 1');
    $stmt->execute([$user_id]);
    return (bool)$stmt->fetch();
}

function has_active_subadmin_access($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT id FROM elections WHERE created_by = ? AND DATE_ADD(end_datetime, INTERVAL 48 HOUR) >= NOW() LIMIT 1");
    $stmt->execute([$user_id]);
    return (bool)$stmt->fetch();
}

function can_manage_election($pdo, $election_id) {
    if (!isLoggedIn()) return false;
    $stmt = $pdo->prepare('SELECT * FROM elections WHERE id = ? AND created_by = ? LIMIT 1');
    $stmt->execute([(int)$election_id, $_SESSION['user_id']]);
    $election = $stmt->fetch();
    return $election && (isAdmin() || election_special_access_active($election));
}

function election_special_access_active($election) {
    return strtotime($election['end_datetime'] . ' +48 hours') >= time();
}

function is_active_auditor_for_election($pdo, $election_id, $user_id, $email) {
    if (!$user_id || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    $stmt = $pdo->prepare("SELECT id FROM election_committee_members
        WHERE election_id = ? AND status = 'active' AND expires_at >= NOW()
        AND (user_id = ? OR LOWER(email) = LOWER(?)) LIMIT 1");
    $stmt->execute([(int)$election_id, (int)$user_id, $email]);
    return (bool)$stmt->fetch();
}

function has_active_auditor_access($pdo, $user_id, $email) {
    if (!$user_id || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    $stmt = $pdo->prepare("SELECT id FROM election_committee_members
        WHERE status = 'active' AND expires_at >= NOW() AND (user_id = ? OR LOWER(email) = LOWER(?)) LIMIT 1");
    $stmt->execute([(int)$user_id, $email]);
    return (bool)$stmt->fetch();
}

function create_audit_invitation($pdo, $election, $email) {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Enter a valid Audit Committee email address.');

    $duplicate = $pdo->prepare("SELECT id FROM election_committee_members
        WHERE election_id = ? AND LOWER(email) = LOWER(?) AND status IN ('pending', 'accepted', 'active') LIMIT 1");
    $duplicate->execute([(int)$election['id'], $email]);
    if ($duplicate->fetch()) throw new Exception('Each Audit Committee email address can only be invited once per election.');

    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime($election['end_datetime'] . ' +48 hours'));
    $user = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
    $user->execute([$email]);
    $userId = $user->fetchColumn() ?: null;
    $insert = $pdo->prepare("INSERT INTO election_committee_members
        (election_id, full_name, email, user_id, status, invitation_token_hash, expires_at)
        VALUES (?, ?, ?, ?, 'pending', ?, ?)");
    $insert->execute([(int)$election['id'], $email, $email, $userId, hash('sha256', $token), $expiresAt]);

    return $token;
}

function send_audit_invitation_email($election, $email, $token) {
    $acceptUrl = url('/audit-invitation.php?token=' . rawurlencode($token));
    $message = "You have been invited to join the Audit Committee for \"{$election['title']}\".\n\n"
        . "Accept your invitation here: {$acceptUrl}\n\n"
        . "After accepting, we will email you a confirmation code. Once confirmed, you can view audit information for this election only. "
        . "Audit Committee members cannot vote, and access ends 48 hours after the election finishes.";
    return send_smartvote_email($email, 'SmartVote Audit Committee invitation', $message);
}

function requireLogin() {
    if (!isLoggedIn()) {
        redirect('/login.php');
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        die("Access Denied: Admins Only");
    }
}

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function generate_election_code($pdo) {
    do {
        $code = strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 7));
        $stmt = $pdo->prepare("SELECT id FROM elections WHERE code = ? LIMIT 1");
        $stmt->execute([$code]);
    } while ($stmt->fetch());

    return $code;
}

function client_ip() {
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function log_vote_attempt($pdo, $election_id, $identifier, $email, $action, $status) {
    try {
        $stmt = $pdo->prepare("INSERT INTO vote_attempts
            (election_id, voter_identifier, ip_address, email, user_agent, action_attempted, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $election_id ?: null,
            $identifier ?: null,
            client_ip(),
            $email ?: null,
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $action,
            $status
        ]);

        if ($status === 'blocked' && strpos($action, 'revote') === 0 && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            send_smartvote_email(
                $email,
                'SmartVote revote warning',
                'A second voting attempt was detected for this election and was blocked. If this was not you, please contact the election organizer.'
            );
        }
    } catch (Exception $e) {
        // Logging must never break voting.
    }
}

function log_audit_event($pdo, $event_type, $detail, $election_id = null, $user_id = null) {
    try {
        $stmt = $pdo->prepare('INSERT INTO audit_events (user_id, election_id, event_type, event_detail, ip_address) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$user_id, $election_id, $event_type, $detail, client_ip()]);
    } catch (Exception $e) {
        // Audit logging must not stop a legitimate user action.
    }
}

function anonymize_audit_reference($value) {
    return $value === null || $value === '' ? '' : substr(hash('sha256', (string)$value), 0, 12);
}

function valid_voter_for_election($pdo, $election_id, $identifier, $email) {
    // identifier_value is populated from the exact field selected by the organizer. BINARY keeps
    // the comparison case-sensitive, including when the selected field is Full Name or Email.
    $stmt = $pdo->prepare('SELECT id FROM valid_voters WHERE election_id = ? AND BINARY identifier_value = BINARY ? LIMIT 1');
    $stmt->execute([$election_id, $identifier]);
    return (bool)$stmt->fetch();
}

function user_is_restricted_from_election($pdo, $election, $user_id, $email) {
    if ((int)$election['created_by'] === (int)$user_id) return true;
    $stmt = $pdo->prepare("SELECT id FROM election_committee_members
        WHERE election_id = ? AND status = 'active' AND expires_at >= NOW()
        AND (user_id = ? OR (email IS NOT NULL AND LOWER(email) = LOWER(?))) LIMIT 1");
    $stmt->execute([$election['id'], $user_id, $email]);
    return (bool)$stmt->fetch();
}

function public_upload_path($storedPath) {
    return PUBLIC_PATH . '/' . ltrim((string)$storedPath, '/');
}

function save_image_upload($file, $folder, $prefix) {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) return null;
    if (($file['size'] ?? 0) > 40 * 1024 * 1024) throw new Exception('Image must be 40 MB or smaller.');
    $info = @getimagesize($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!$info || !isset($allowed[$info['mime']])) throw new Exception('Image must be a valid JPG, PNG, or WebP file.');
    $relative = 'uploads/' . trim($folder, '/') . '/' . $prefix . '_' . bin2hex(random_bytes(16)) . '.' . $allowed[$info['mime']];
    $destination = public_upload_path($relative);
    if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0755, true)) throw new Exception('Could not create the image storage directory.');
    if (!move_uploaded_file($file['tmp_name'], $destination)) throw new Exception('Could not save uploaded image.');
    return $relative;
}

function election_identifier_label($pdo, $election_id) {
    // New elections retain this choice on the election itself so validation does not depend on
    // which voter row happened to be imported first. Keep the voter-list lookup for legacy data.
    $stmt = $pdo->prepare('SELECT voter_verification_field FROM elections WHERE id = ? LIMIT 1');
    $stmt->execute([$election_id]);
    $field = trim((string)$stmt->fetchColumn());
    if ($field !== '') return $field;

    $stmt = $pdo->prepare('SELECT identifier_label FROM valid_voters WHERE election_id = ? ORDER BY id ASC LIMIT 1');
    $stmt->execute([$election_id]);
    return $stmt->fetchColumn() ?: 'Name';
}

function voter_has_completed_ballot($pdo, $election_id, $user_id, $identifier) {
    $stmt = $pdo->prepare('SELECT id FROM vote_receipts WHERE election_id = ? AND (user_id = ? OR voter_identifier = ?) LIMIT 1');
    $stmt->execute([$election_id, $user_id, $identifier]);
    return (bool)$stmt->fetch();
}

function send_smartvote_email($to, $subject, $message) {
    // This file normally loads Composer near the top, but retain this guard so this helper
    // can never instantiate PHPMailer before its classes are available.
    require_once BASE_PATH . '/vendor/autoload.php';

    $mail = null;
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.ionos.co.uk';
		$mail->Port = 587;
		$mail->SMTPAuth = true;
		$mail->Username = 'otp@smart-voting.com';
		$mail->Password = 'SmartVote2026';          // ← use the simple password
		$mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom('otp@smart-voting.com', 'SmartVote');
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $message;
        $mail->isHTML(false);

        return $mail->send();
    } catch (\Throwable $e) {
        $mailerError = ($mail instanceof \PHPMailer\PHPMailer\PHPMailer) ? $mail->ErrorInfo : '';
        error_log('SmartVote SMTP send failed: ' . $e->getMessage() . ($mailerError !== '' ? ' | PHPMailer: ' . $mailerError : ''));
        return false;
    }
}

function create_vote_otp($pdo, $email, $election_id, $identifier) {
    $code = (string)random_int(100000, 999999);
    $expires = date('Y-m-d H:i:s', time() + 600);

    $pdo->prepare("UPDATE otp_codes SET used = 1 WHERE email = ? AND election_id = ? AND used = 0")
        ->execute([$email, $election_id]);

    $stmt = $pdo->prepare("INSERT INTO otp_codes (email, code, election_id, voter_identifier, expires_at)
        VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$email, $code, $election_id, $identifier, $expires]);

    $sent = send_smartvote_email(
        $email,
        'Your SmartVote OTP',
        "Your SmartVote verification code is {$code}. It expires in 10 minutes."
    );

    if (!$sent) {
        $_SESSION['last_dev_otp'] = $code;
    }

    return $sent;
}

function voter_id_matches_format($identifier, $format) {
    $format = trim((string)$format);
    if ($format === '') {
        return true;
    }

    $pattern = preg_quote($format, '/');
    $pattern = str_replace('_', '[A-Za-z0-9]', $pattern);
    return (bool)preg_match('/^' . $pattern . '$/i', $identifier);
}

/** Return the database-backed statistics used by the on-screen and downloaded election reports. */
function election_report_data($pdo, $election_id) {
    $electionStmt = $pdo->prepare('SELECT * FROM elections WHERE id = ? LIMIT 1');
    $electionStmt->execute([(int)$election_id]);
    $election = $electionStmt->fetch();
    if (!$election) return null;

    $eligible = $pdo->prepare('SELECT COUNT(*) FROM valid_voters WHERE election_id = ?');
    $eligible->execute([$election_id]);
    $eligibleCount = (int)$eligible->fetchColumn();
    $cast = $pdo->prepare('SELECT COUNT(*) FROM vote_receipts WHERE election_id = ?');
    $cast->execute([$election_id]);
    $castCount = (int)$cast->fetchColumn();

    $activity = $pdo->prepare("SELECT
        COALESCE(SUM(status = 'blocked' AND action_attempted LIKE 'revote%'), 0) AS double_votes,
        COALESCE(SUM(status = 'blocked'), 0) AS blocked_attempts,
        COUNT(*) AS total_attempts
        FROM vote_attempts WHERE election_id = ?");
    $activity->execute([$election_id]);
    $activityCounts = $activity->fetch() ?: [];

    $genderStmt = $pdo->prepare("SELECT CASE WHEN u.gender IN ('M', 'F') THEN u.gender ELSE 'Unknown' END AS gender, COUNT(*) AS voters
        FROM vote_receipts vr LEFT JOIN users u ON u.id = vr.user_id
        WHERE vr.election_id = ? GROUP BY CASE WHEN u.gender IN ('M', 'F') THEN u.gender ELSE 'Unknown' END");
    $genderStmt->execute([$election_id]);
    $gender = ['M' => 0, 'F' => 0, 'Unknown' => 0];
    foreach ($genderStmt->fetchAll() as $row) $gender[$row['gender']] = (int)$row['voters'];

    $timeStmt = $pdo->prepare("SELECT DATE_FORMAT(cast_at, '%Y-%m-%d %H:00:00') AS hour_start, COUNT(*) AS votes
        FROM vote_receipts WHERE election_id = ? GROUP BY DATE_FORMAT(cast_at, '%Y-%m-%d %H:00:00') ORDER BY hour_start");
    $timeStmt->execute([$election_id]);
    $hourlyVotes = $timeStmt->fetchAll();
    $rangeStmt = $pdo->prepare('SELECT MIN(cast_at) AS first_vote, MAX(cast_at) AS last_vote FROM vote_receipts WHERE election_id = ?');
    $rangeStmt->execute([$election_id]);
    $voteRange = $rangeStmt->fetch() ?: [];
    $mostActive = null;
    foreach ($hourlyVotes as $hour) if ($mostActive === null || (int)$hour['votes'] > (int)$mostActive['votes']) $mostActive = $hour;

    $roleStmt = $pdo->prepare('SELECT id, role_name FROM candidate_roles WHERE election_id = ? ORDER BY id');
    $roleStmt->execute([$election_id]);
    $roles = $roleStmt->fetchAll();
    $candidateStmt = $pdo->prepare('SELECT c.id, c.name, COUNT(v.id) AS votes
        FROM candidates c LEFT JOIN votes v ON v.candidate_id = c.id
        WHERE c.role_id = ? GROUP BY c.id, c.name ORDER BY votes DESC, c.name ASC');
    foreach ($roles as &$role) {
        $candidateStmt->execute([$role['id']]);
        $role['candidates'] = $candidateStmt->fetchAll();
        $role['total_votes'] = array_sum(array_map(fn($candidate) => (int)$candidate['votes'], $role['candidates']));
        $role['winner_votes'] = $role['candidates'] ? (int)$role['candidates'][0]['votes'] : 0;
    }
    unset($role);

    $blockedStmt = $pdo->prepare("SELECT email, voter_identifier, ip_address, action_attempted, status, attempt_time
        FROM vote_attempts WHERE election_id = ? AND status = 'blocked' ORDER BY attempt_time DESC");
    $blockedStmt->execute([$election_id]);

    return [
        'election' => $election, 'eligible_count' => $eligibleCount, 'cast_count' => $castCount,
        'turnout_percent' => $eligibleCount ? ($castCount / $eligibleCount) * 100 : 0,
        'double_votes' => (int)($activityCounts['double_votes'] ?? 0),
        'blocked_attempts' => (int)($activityCounts['blocked_attempts'] ?? 0),
        'total_attempts' => (int)($activityCounts['total_attempts'] ?? 0),
        'gender' => $gender, 'hourly_votes' => $hourlyVotes, 'first_vote' => $voteRange['first_vote'] ?? null,
        'last_vote' => $voteRange['last_vote'] ?? null, 'most_active' => $mostActive,
        'roles' => $roles, 'blocked_events' => $blockedStmt->fetchAll()
    ];
}

function generate_tamper_report($pdo, $election_id) {
    $summary = $pdo->prepare("SELECT
        SUM(status = 'blocked' AND action_attempted LIKE 'revote%') AS double_votes,
        SUM(status = 'blocked') AS blocked_attempts,
        COUNT(*) AS total_attempts
        FROM vote_attempts WHERE election_id = ?");
    $summary->execute([$election_id]);
    $counts = $summary->fetch();

    $attempts = $pdo->prepare("SELECT email, voter_identifier, ip_address, action_attempted, status, attempt_time
        FROM vote_attempts WHERE election_id = ? ORDER BY attempt_time DESC");
    $attempts->execute([$election_id]);

    $lines = [];
    $lines[] = "Audit Report";
    $lines[] = "Double-voting attempts: " . (int)($counts['double_votes'] ?? 0);
    $lines[] = "Blocked attempts: " . (int)($counts['blocked_attempts'] ?? 0);
    $lines[] = "Total logged events: " . (int)($counts['total_attempts'] ?? 0);
    $lines[] = "";
    $lines[] = "Events:";

    foreach ($attempts->fetchAll() as $attempt) {
        $lines[] = sprintf(
            "%s | %s | %s | %s | %s | %s",
            $attempt['attempt_time'],
            anonymize_audit_reference($attempt['email']) ?: 'no-email',
            anonymize_audit_reference($attempt['voter_identifier']) ?: 'no-id',
            $attempt['ip_address'] ?: 'no-ip',
            $attempt['action_attempted'],
            $attempt['status']
        );
    }

    return implode("\n", $lines);
}

function store_election_audit_report($pdo, $election) {
    if (strtotime($election['end_datetime']) > time()) {
        return;
    }

    $report_body = generate_tamper_report($pdo, $election['id']);
    $stmt = $pdo->prepare("INSERT INTO election_reports (election_id, report_body)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE report_body = VALUES(report_body)");
    $stmt->execute([$election['id'], $report_body]);

    $report = $pdo->prepare("SELECT * FROM election_reports WHERE election_id = ? LIMIT 1");
    $report->execute([$election['id']]);
    $row = $report->fetch();

    // Audit reports are available only to administrators; voters are not emailed election-end
    // messages or activity data.
}
?>
