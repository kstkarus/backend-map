<?php
// auth.php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';

function generate_token() {
    // Генерация случайного токена (UUID v4)
    return bin2hex(random_bytes(16));
}

function register_user($email, $password, $name) {
    $db = new Database();
    $pdo = $db->getPdo();
    // Проверка на существование email
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        return ['error' => 'Пользователь с таким email уже существует'];
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, name, is_verified) VALUES (?, ?, ?, 0)');
    $stmt->execute([$email, $hash, $name]);
    $user_id = $pdo->lastInsertId();
    $token = generate_token();
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $pdo->prepare('INSERT INTO sessions (user_id, token, user_agent, ip_address) VALUES (?, ?, ?, ?)');
    $stmt->execute([$user_id, $token, $user_agent, $ip]);
    return [
        'success' => true,
        'token' => $token,
        'user' => [
            'id' => $user_id,
            'name' => $name,
            'email' => $email,
            'role' => 'user',
            'is_verified' => 0,
        ]
    ];
}

function login_user($email, $password) {
    $db = new Database();
    $pdo = $db->getPdo();
    $stmt = $pdo->prepare('SELECT id, password_hash, name, role, is_verified FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['error' => 'Неверный email или пароль'];
    }
    $token = generate_token();
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $pdo->prepare('INSERT INTO sessions (user_id, token, user_agent, ip_address) VALUES (?, ?, ?, ?)');
    $stmt->execute([$user['id'], $token, $user_agent, $ip]);
    return [
        'success' => true,
        'token' => $token,
        'user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $email,
            'role' => $user['role'],
            'is_verified' => $user['is_verified'],
        ]
    ];
} 