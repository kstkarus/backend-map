<?php
// auth.php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/validation.php';

function generate_token() {
    // Генерация случайного токена (UUID v4)
    return bin2hex(random_bytes(16));
}

// --- Вспомогательные функции для работы с refresh токенами ---
function generate_refresh_token() {
    return bin2hex(random_bytes(32));
}

function save_refresh_token($user_id, $token, $expires_at, $user_agent = null, $ip = null, $device_id = '') {
    $db = new Database();
    $pdo = $db->getPdo();
    $stmt = $pdo->prepare('INSERT INTO refresh_tokens (user_id, token, user_agent, ip_address, expires_at, device_id) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$user_id, $token, $user_agent, $ip, $expires_at, $device_id]);
}

function deactivate_refresh_token($token, $device_id = null) {
    $db = new Database();
    $pdo = $db->getPdo();
    if ($device_id) {
        $stmt = $pdo->prepare('UPDATE refresh_tokens SET is_active = 0 WHERE token = ? AND device_id = ?');
        $stmt->execute([$token, $device_id]);
    } else {
        $stmt = $pdo->prepare('UPDATE refresh_tokens SET is_active = 0 WHERE token = ?');
        $stmt->execute([$token]);
    }
}

function get_refresh_token($token, $device_id = null) {
    $db = new Database();
    $pdo = $db->getPdo();
    if ($device_id) {
        $stmt = $pdo->prepare('SELECT * FROM refresh_tokens WHERE token = ? AND device_id = ? AND is_active = 1');
        $stmt->execute([$token, $device_id]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM refresh_tokens WHERE token = ? AND is_active = 1');
        $stmt->execute([$token]);
    }
    return $stmt->fetch();
}

// --- Регистрация пользователя ---
function register_user($email, $password, $name, $city, $birthdate, $phone = null, $device_id = '') {
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
        
        // --- JWT ---
        $access_payload = [
            'user_id' => $user_id,
            'email' => $email,
            'role' => 'user',
            'is_verified' => 0
        ];
        $access_token = create_jwt($access_payload, 900); // 15 минут
        $refresh_token = generate_refresh_token();
        $refresh_expires = date('Y-m-d H:i:s', time() + 60*60*24*14); // 14 дней
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        save_refresh_token($user_id, $refresh_token, $refresh_expires, $user_agent, $ip, $device_id);
        
        $pdo->commit();
        
        // Устанавливаем refresh token в httpOnly cookie
        setcookie('refresh_token', $refresh_token, [
            'expires' => strtotime($refresh_expires),
            'httponly' => true,
            'samesite' => 'Lax',
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS'])
        ]);
        
        return [
            'success' => true,
            'access_token' => $access_token,
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

// --- Логин пользователя ---
function login_user($email, $password, $device_id = '') {
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
        $access_payload = [
            'user_id' => $user['id'],
            'email' => $email,
            'role' => $user['role'],
            'is_verified' => $user['is_verified']
        ];
        $access_token = create_jwt($access_payload, 900); // 15 минут
        $refresh_token = generate_refresh_token();
        $refresh_expires = date('Y-m-d H:i:s', time() + 60*60*24*14); // 14 дней
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        save_refresh_token($user['id'], $refresh_token, $refresh_expires, $user_agent, $ip, $device_id);
        setcookie('refresh_token', $refresh_token, [
            'expires' => strtotime($refresh_expires),
            'httponly' => true,
            'samesite' => 'Lax',
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS'])
        ]);
        return [
            'success' => true,
            'access_token' => $access_token,
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
    $stmt = $pdo->prepare('SELECT id, email, name, city, birthdate, phone, role, social_id, social_type, is_verified, created_at FROM users WHERE id = ?');
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

// --- Logout пользователя ---
function logout_user_by_refresh($refresh_token, $device_id = null) {
    deactivate_refresh_token($refresh_token, $device_id);
    setcookie('refresh_token', '', [
        'expires' => time() - 3600,
        'httponly' => true,
        'samesite' => 'Lax',
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS'])
    ]);
    return ['success' => true];
} 