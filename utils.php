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

function create_jwt($payload, $exp = 3600) {
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $payload['exp'] = time() + $exp;
    $segments = [
        base64url_encode(json_encode($header)),
        base64url_encode(json_encode($payload))
    ];
    $signing_input = implode('.', $segments);
    $signature = hash_hmac('sha256', $signing_input, $jwt_secret, true);
    $segments[] = base64url_encode($signature);
    return implode('.', $segments);
}

function verify_jwt($jwt) {
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

function generate_reset_token($length = 64) {
    return bin2hex(random_bytes($length / 2));
}

function get_user_by_email($email) {
    $db = new Database();
    $pdo = $db->getPdo();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function create_password_reset($user_id, $token, $expires_at) {
    $db = new Database();
    $pdo = $db->getPdo();
    $stmt = $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)');
    $stmt->execute([$user_id, $token, $expires_at]);
}

function get_password_reset($token) {
    $db = new Database();
    $pdo = $db->getPdo();
    $stmt = $pdo->prepare('SELECT * FROM password_resets WHERE token = ? AND used = 0 AND expires_at > NOW()');
    $stmt->execute([$token]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function mark_password_reset_used($id) {
    $db = new Database();
    $pdo = $db->getPdo();
    $stmt = $pdo->prepare('UPDATE password_resets SET used = 1 WHERE id = ?');
    $stmt->execute([$id]);
}

function update_user_password($user_id, $password) {
    $db = new Database();
    $pdo = $db->getPdo();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
    $stmt->execute([$hash, $user_id]);
}

// Подключаем приватный конфиг
$smtp_config = file_exists(__DIR__ . '/config.local.php') ? require __DIR__ . '/config.local.php' : [];
$jwt_secret = $smtp_config['jwt_secret'] ?? 'default_secret';

function send_reset_email($email, $reset_link) {
    global $smtp_config;
    $mail = new PHPMailer(true);
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

        $mail->send();
    } catch (Exception $e) {
        file_put_contents(__DIR__ . '/mail_errors.log', $e->getMessage() . PHP_EOL, FILE_APPEND);
    }
} 