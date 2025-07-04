<?php
// utils.php

// Секретный ключ для подписи токенов (замени на свой!)
define('JWT_SECRET', 'your_super_secret_key');

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
    $signature = hash_hmac('sha256', $signing_input, JWT_SECRET, true);
    $segments[] = base64url_encode($signature);
    return implode('.', $segments);
}

function verify_jwt($jwt) {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return false;
    list($header64, $payload64, $signature64) = $parts;
    $signing_input = $header64 . '.' . $payload64;
    $signature = base64url_decode($signature64);
    $expected = hash_hmac('sha256', $signing_input, JWT_SECRET, true);
    if (!hash_equals($expected, $signature)) return false;
    $payload = json_decode(base64url_decode($payload64), true);
    if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) return false;
    return $payload;
}

function verify_session_token($token) {
    $db = new Database();
    $pdo = $db->getPdo();
    $stmt = $pdo->prepare('SELECT * FROM sessions WHERE token = ? AND is_active = 1');
    $stmt->execute([$token]);
    $session = $stmt->fetch();
    if (!$session) return false;
    // Обновляем last_active
    $stmt = $pdo->prepare('UPDATE sessions SET last_active = NOW() WHERE id = ?');
    $stmt->execute([$session['id']]);
    return $session;
} 