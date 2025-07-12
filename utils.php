<?php
// utils.php

require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';
require_once __DIR__ . '/phpmailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}

function create_jwt($payload, $exp = 900) {
    global $jwt_secret;
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $segments = [
        base64url_encode(json_encode($header)),
        base64url_encode(json_encode(array_merge($payload, ['exp' => time() + $exp])))
    ];
    $signing_input = implode('.', $segments);
    $signature = hash_hmac('sha256', $signing_input, $jwt_secret, true);
    $segments[] = base64url_encode($signature);
    return implode('.', $segments);
}

function verify_jwt($jwt) {
    global $jwt_secret;
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return false;
    list($header64, $payload64, $signature64) = $parts;
    $signing_input = $header64 . '.' . $payload64;
    $signature = base64url_decode($signature64);
    $expected = hash_hmac('sha256', $signing_input, $jwt_secret, true);
    if (!hash_equals($expected, $signature)) return false;
    $payload = json_decode(base64url_decode($payload64), true);
    if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) return false;
    return $payload;
}

function get_bearer_token() {
    $headers = getallheaders();
    $auth = isset($headers['Authorization']) ? $headers['Authorization'] : (isset($headers['authorization']) ? $headers['authorization'] : '');
    if (preg_match('/^Bearer (.+)$/', $auth, $matches)) {
        return $matches[1];
    }
    return null;
}

function get_user_by_email($email) {
    $db = new Database();
    $pdo = $db->getPdo();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function update_user_password($user_id, $password) {
    $db = new Database();
    $pdo = $db->getPdo();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->execute([$hash, $user_id]);
}

// Подключаем приватный конфиг
$smtp_config = file_exists(__DIR__ . '/config.local.php') ? require __DIR__ . '/config.local.php' : [];
$jwt_secret = $smtp_config['jwt_secret'] ?? 'default_secret';

function send_reset_email($email, $reset_link) {
    global $smtp_config;
    $log_dir = __DIR__ . '/logs';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0777, true);
    }
    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    try {
        $mail->isSMTP();
        $mail->Host = $smtp_config['smtp_host'] ?? '';
        $mail->SMTPAuth = true;
        $mail->Username = $smtp_config['smtp_user'] ?? '';
        $mail->Password = $smtp_config['smtp_pass'] ?? '';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = $smtp_config['smtp_port'] ?? 465;

        $mail->setFrom($smtp_config['smtp_from'] ?? '', $smtp_config['smtp_from_name'] ?? '');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'Сброс пароля';
        $mail->Body = 'Для сброса пароля перейдите по ссылке: <a href="' . htmlspecialchars($reset_link) . '">' . htmlspecialchars($reset_link) . '</a>';
        $mail->AltBody = 'Для сброса пароля перейдите по ссылке: ' . $reset_link;

        // Включаем отладку PHPMailer
        $mail->SMTPDebug = 2;
        $mail->Debugoutput = function($str, $level) use ($log_dir) { file_put_contents($log_dir . '/phpmailer_debug.log', $str . PHP_EOL, FILE_APPEND); };

        $mail->send();
    } catch (Exception $e) {
        file_put_contents($log_dir . '/mail_errors.log', $e->getMessage() . PHP_EOL, FILE_APPEND);
    }
}

// --- Новый функционал для кодов сброса пароля и подтверждения email ---
function generate_5digit_code() {
    return str_pad(strval(random_int(0, 99999)), 5, '0', STR_PAD_LEFT);
}

function save_user_code($email, $user_id, $code, $type) {
    $db = new Database();
    $pdo = $db->getPdo();
    $expires_at = date('Y-m-d H:i:s', time() + 600); // 10 минут
    $stmt = $pdo->prepare('INSERT INTO user_codes (user_id, email, code, type, expires_at) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$user_id, $email, $code, $type, $expires_at]);
}

function request_reset_code($email) {
    $user = get_user_by_email($email);
    if (!$user) {
        return ['success' => true, 'message' => 'Если такой email существует, код отправлен'];
    }
    $code = generate_5digit_code();
    save_user_code($email, $user['id'], $code, 'reset');
    send_code_email($email, $code, 'reset');
    return ['success' => true, 'message' => 'Код отправлен на почту'];
}

function verify_reset_code($email, $code) {
    $db = new Database();
    $pdo = $db->getPdo();
    $stmt = $pdo->prepare('SELECT * FROM user_codes WHERE email = ? AND code = ? AND type = ? AND used = 0 AND expires_at > NOW()');
    $stmt->execute([$email, $code, 'reset']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['error' => 'Код невалиден или истёк'];
    }
    return ['success' => true];
}

function reset_password_with_code($email, $code, $password) {
    $user = get_user_by_email($email);
    if (!$user) {
        return ['error' => 'Пользователь не найден'];
    }
    $db = new Database();
    $pdo = $db->getPdo();
    $stmt = $pdo->prepare('SELECT * FROM user_codes WHERE email = ? AND code = ? AND type = ? AND used = 0 AND expires_at > NOW()');
    $stmt->execute([$email, $code, 'reset']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['error' => 'Код невалиден или истёк'];
    }
    $password_validation = validate_password($password);
    if (!$password_validation['valid']) {
        return ['error' => $password_validation['error']];
    }
    update_user_password($user['id'], $password);
    $stmt = $pdo->prepare('UPDATE user_codes SET used = 1 WHERE id = ?');
    $stmt->execute([$row['id']]);
    return ['success' => true, 'message' => 'Пароль успешно изменён'];
}

function request_email_verification_code($email) {
    $user = get_user_by_email($email);
    if (!$user) {
        return ['error' => 'Пользователь не найден'];
    }
    $code = generate_5digit_code();
    save_user_code($email, $user['id'], $code, 'verify');
    send_code_email($email, $code, 'verify');
    return ['success' => true, 'message' => 'Код отправлен на почту'];
}

function verify_email_code($email, $code) {
    $user = get_user_by_email($email);
    if (!$user) {
        return ['error' => 'Пользователь не найден'];
    }
    $db = new Database();
    $pdo = $db->getPdo();
    $stmt = $pdo->prepare('SELECT * FROM user_codes WHERE email = ? AND code = ? AND type = ? AND used = 0 AND expires_at > NOW()');
    $stmt->execute([$email, $code, 'verify']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['error' => 'Код невалиден или истёк'];
    }
    $stmt = $pdo->prepare('UPDATE users SET is_verified = 1 WHERE id = ?');
    $stmt->execute([$user['id']]);
    $stmt = $pdo->prepare('UPDATE user_codes SET used = 1 WHERE id = ?');
    $stmt->execute([$row['id']]);
    return ['success' => true, 'message' => 'Email подтверждён'];
}

function send_code_email($email, $code, $type) {
    global $smtp_config;
    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    try {
        $mail->isSMTP();
        $mail->Host = $smtp_config['smtp_host'] ?? '';
        $mail->SMTPAuth = true;
        $mail->Username = $smtp_config['smtp_user'] ?? '';
        $mail->Password = $smtp_config['smtp_pass'] ?? '';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = $smtp_config['smtp_port'] ?? 465;
        $mail->setFrom($smtp_config['smtp_from'] ?? '', $smtp_config['smtp_from_name'] ?? '');
        $mail->addAddress($email);
        $mail->isHTML(true);
        if ($type === 'reset') {
            $mail->Subject = 'Код для сброса пароля';
            $mail->Body = 'Ваш код для сброса пароля: <b>' . htmlspecialchars($code) . '</b><br>Код действует 10 минут.';
            $mail->AltBody = 'Ваш код для сброса пароля: ' . $code . '. Код действует 10 минут.';
        } else {
            $mail->Subject = 'Код для подтверждения почты';
            $mail->Body = 'Ваш код для подтверждения почты: <b>' . htmlspecialchars($code) . '</b><br>Код действует 10 минут.';
            $mail->AltBody = 'Ваш код для подтверждения почты: ' . $code . '. Код действует 10 минут.';
        }
        $mail->send();
    } catch (Exception $e) {
        // Можно логировать ошибку
    }
} 