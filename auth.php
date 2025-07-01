<?php
// auth.php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';

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
    $token = create_jwt(['user_id' => $user_id, 'email' => $email, 'name' => $name]);
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
    $token = create_jwt(['user_id' => $user['id'], 'email' => $email, 'name' => $user['name']]);
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