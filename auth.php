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

function delete_refresh_token($token, $device_id = null) {
    $db = new Database();
    $pdo = $db->getPdo();
    if ($device_id) {
        $stmt = $pdo->prepare('DELETE FROM refresh_tokens WHERE token = ? AND device_id = ?');
        $stmt->execute([$token, $device_id]);
    } else {
        $stmt = $pdo->prepare('DELETE FROM refresh_tokens WHERE token = ?');
        $stmt->execute([$token]);
    }
}

// --- Регистрация пользователя ---
function register_user($email, $password, $name = null, $city = null, $birthdate = null, $phone = null, $device_id = '') {
    // Валидация только email и пароля
    $validation = validate_registration_data([
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
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        return [
            'error' => 'Пользователь с таким email уже существует',
            'errors' => ['email' => 'Этот email уже используется']
        ];
    }
    $clean_phone = null;
    // if (!empty($phone)) {
    //     $phone_validation = validate_phone($phone);
    //     if ($phone_validation['valid'] && isset($phone_validation['clean_phone'])) {
    //         $clean_phone = $phone_validation['clean_phone'];
    //     }
    // }
    $user_id = null;
    try {
        $pdo->beginTransaction();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, name, city, birthdate, phone, is_verified) VALUES (?, ?, ?, ?, ?, ?, 0)');
        $stmt->execute([
            $email,
            $hash,
            $name,
            $city,
            $birthdate,
            $clean_phone
        ]);
        $user_id = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $isDev = false; $env = getenv('APP_ENV'); if ($env === false && isset($_SERVER['APP_ENV'])) { $env = $_SERVER['APP_ENV']; }
        if (strtolower((string)$env) === 'dev') { $isDev = true; }
        $resp = ['error' => 'Ошибка при создании аккаунта. Попробуйте позже.'];
        if ($isDev) { $resp['debug'] = $e->getMessage(); }
        return $resp;
    }
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
    try {
        $pdo->beginTransaction();
        save_refresh_token($user_id, $refresh_token, $refresh_expires, $user_agent, $ip, $device_id);
        $pdo->commit();
        setcookie('refresh_token', $refresh_token, [
            'expires' => strtotime($refresh_expires),
            'httponly' => true,
            'samesite' => 'Lax',
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS'])
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        $isDev = false; $env = getenv('APP_ENV'); if ($env === false && isset($_SERVER['APP_ENV'])) { $env = $_SERVER['APP_ENV']; }
        if (strtolower((string)$env) === 'dev') { $isDev = true; }
        $resp = ['error' => 'Пользователь создан, но не удалось создать refresh_token. Попробуйте войти заново.'];
        if ($isDev) { $resp['debug'] = $e->getMessage(); }
        return $resp;
    }
    return [
        'success' => true,
        'access_token' => $access_token,
        'user' => [
            'id' => $user_id,
            'name' => $name,
            'email' => $email,
            'city' => get_city_by_name($city),
            'birthdate' => $birthdate,
            'phone' => $clean_phone,
            'role' => 'user',
            'is_verified' => 0,
        ]
    ];
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
    
    $stmt = $pdo->prepare('SELECT id, password_hash, name, city, birthdate, phone, role, is_verified FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return [
            'error' => 'Неверный email или пароль',
            'errors' => ['auth' => 'Проверьте правильность email и пароля']
        ];
    }
    
    try {
        // Деактивируем и удаляем все refresh токены для этого пользователя и device_id
        $db = new Database();
        $pdo = $db->getPdo();
        $stmt = $pdo->prepare('SELECT token FROM refresh_tokens WHERE user_id = ? AND device_id = ? AND is_active = 1');
        $stmt->execute([$user['id'], $device_id]);
        $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tokens as $t) {
            deactivate_refresh_token($t['token'], $device_id);
            delete_refresh_token($t['token'], $device_id);
        }
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
                'city' => get_city_by_name($user['city']),
                'birthdate' => $user['birthdate'],
                'phone' => $user['phone'],
                'role' => $user['role'],
                'is_verified' => $user['is_verified'],
            ]
        ];
    } catch (Exception $e) {
        $isDev = false; $env = getenv('APP_ENV'); if ($env === false && isset($_SERVER['APP_ENV'])) { $env = $_SERVER['APP_ENV']; }
        if (strtolower((string)$env) === 'dev') { $isDev = true; }
        $resp = ['error' => 'Ошибка при входе в систему. Попробуйте позже.'];
        if ($isDev) { $resp['debug'] = $e->getMessage(); }
        return $resp;
    }
}

function guest_login($device_id = '') {


    $db = new Database();


    $pdo = $db->getPdo();


    $name = 'Гость';


    $role = 'guest';


    $city = null;


    $birthdate = null;


    $phone = null;


    $is_verified = 0;


    $unique_guest_id = 'guest_' . bin2hex(random_bytes(8));


    $guest_email = $unique_guest_id . '@guest.local';


    // Создаём гостевого пользователя в БД


    try {


        $pdo->beginTransaction();


        $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, name, city, birthdate, phone, role, is_verified, social_id, social_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');


        $stmt->execute([


            $guest_email, // email (уникальный)


            '', // password_hash (нет пароля)


            $name,


            $city,


            $birthdate,


            $phone,


            $role,


            $is_verified,


            $unique_guest_id,


            'guest'


        ]);


        $user_id = (int)$pdo->lastInsertId();


        $pdo->commit();


    } catch (Exception $e) {
        $pdo->rollBack();
        $isDev = false; $env = getenv('APP_ENV'); if ($env === false && isset($_SERVER['APP_ENV'])) { $env = $_SERVER['APP_ENV']; }
        if (strtolower((string)$env) === 'dev') { $isDev = true; }
        $resp = ['error' => 'Ошибка при создании гостевого пользователя. Попробуйте позже.'];
        if ($isDev) { $resp['debug'] = $e->getMessage(); }
        return $resp;
    }


    // --- JWT ---


    $access_payload = [


        'user_id' => $user_id,


        'email' => $guest_email,


        'role' => $role,


        'is_verified' => 0


    ];


    $access_token = create_jwt($access_payload, 900); // 15 минут


    $refresh_token = generate_refresh_token();


    $refresh_expires = date('Y-m-d H:i:s', time() + 60*60*24*14); // 14 дней


    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;


    $ip = $_SERVER['REMOTE_ADDR'] ?? null;


    try {


        $pdo->beginTransaction();


        save_refresh_token($user_id, $refresh_token, $refresh_expires, $user_agent, $ip, $device_id);


        $pdo->commit();


        setcookie('refresh_token', $refresh_token, [


            'expires' => strtotime($refresh_expires),


            'httponly' => true,


            'samesite' => 'Lax',


            'path' => '/',


            'secure' => isset($_SERVER['HTTPS'])


        ]);


    } catch (Exception $e) {
        $pdo->rollBack();
        $isDev = false; $env = getenv('APP_ENV'); if ($env === false && isset($_SERVER['APP_ENV'])) { $env = $_SERVER['APP_ENV']; }
        if (strtolower((string)$env) === 'dev') { $isDev = true; }
        $resp = ['error' => 'Гостевой пользователь создан, но не удалось создать refresh_token. Попробуйте войти заново.'];
        if ($isDev) { $resp['debug'] = $e->getMessage(); }
        return $resp;
    }


    return [


        'success' => true,


        'access_token' => $access_token,


        'user' => [


            'id' => $user_id,


            'name' => $name,


            'email' => $guest_email,


            'city' => get_city_by_name($city),


            'birthdate' => null,


            'phone' => null,


            'role' => $role,


            'is_verified' => 0,


        ]


    ];


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