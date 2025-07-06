<?php
// auth.php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/validation.php';

function generate_token() {
    // Генерация случайного токена (UUID v4)
    return bin2hex(random_bytes(16));
}

function register_user($email, $password, $name, $city, $birthdate, $phone = null) {
    // Валидация входных данных
    $validation = validate_registration_data([
        'email' => $email,
        'password' => $password,
        'name' => $name,
        'city' => $city,
        'birthdate' => $birthdate,
        'phone' => $phone
    ]);
    
    if (!$validation['valid']) {
        return [
            'error' => $validation['message'],
            'errors' => $validation['errors']
        ];
    }
    
    $db = new Database();
    $pdo = $db->getPdo();
    
    // Проверка на существование email
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        return [
            'error' => 'Пользователь с таким email уже существует',
            'errors' => ['email' => 'Этот email уже используется']
        ];
    }
    
    // Очистка телефона если он предоставлен
    $clean_phone = null;
    if (!empty($phone)) {
        $phone_validation = validate_phone($phone);
        if ($phone_validation['valid'] && isset($phone_validation['clean_phone'])) {
            $clean_phone = $phone_validation['clean_phone'];
        }
    }
    
    try {
        $pdo->beginTransaction();
        
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, name, city, birthdate, phone, is_verified) VALUES (?, ?, ?, ?, ?, ?, 0)');
        $stmt->execute([$email, $hash, $name, $city, $birthdate, $clean_phone]);
        $user_id = $pdo->lastInsertId();
        
        $token = generate_token();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt = $pdo->prepare('INSERT INTO sessions (user_id, token, user_agent, ip_address) VALUES (?, ?, ?, ?)');
        $stmt->execute([$user_id, $token, $user_agent, $ip]);
        
        $pdo->commit();
        
        return [
            'success' => true,
            'token' => $token,
            'user' => [
                'id' => $user_id,
                'name' => $name,
                'email' => $email,
                'city' => $city,
                'birthdate' => $birthdate,
                'phone' => $clean_phone,
                'role' => 'user',
                'is_verified' => 0,
            ]
        ];
    } catch (Exception $e) {
        $pdo->rollBack();
        return [
            'error' => 'Ошибка при создании аккаунта. Попробуйте позже.',
            'debug' => $e->getMessage() // Убрать в продакшене
        ];
    }
}

function login_user($email, $password) {
    // Валидация входных данных
    $validation = validate_login_data([
        'email' => $email,
        'password' => $password
    ]);
    
    if (!$validation['valid']) {
        return [
            'error' => $validation['message'],
            'errors' => $validation['errors']
        ];
    }
    
    $db = new Database();
    $pdo = $db->getPdo();
    
    $stmt = $pdo->prepare('SELECT id, password_hash, name, role, is_verified FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return [
            'error' => 'Неверный email или пароль',
            'errors' => ['auth' => 'Проверьте правильность email и пароля']
        ];
    }
    
    try {
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
    } catch (Exception $e) {
        return [
            'error' => 'Ошибка при входе в систему. Попробуйте позже.',
            'debug' => $e->getMessage() // Убрать в продакшене
        ];
    }
}

function get_user_by_id($user_id) {
    $db = new Database();
    $pdo = $db->getPdo();
    $stmt = $pdo->prepare('SELECT id, email, name, role, social_id, social_type, is_verified, created_at FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    return $stmt->fetch();
}

function get_user_by_uuid($uuid) {
    $db = new Database();
    $pdo = $db->getPdo();
    $stmt = $pdo->prepare('SELECT id, email, name, role, social_id, social_type, is_verified, created_at FROM users WHERE social_id = ?');
    $stmt->execute([$uuid]);
    return $stmt->fetch();
}

function logout_user($session_id) {
    $db = new Database();
    $pdo = $db->getPdo();
    $stmt = $pdo->prepare('UPDATE sessions SET is_active = 0 WHERE id = ?');
    $stmt->execute([$session_id]);
    return ['success' => true];
} 