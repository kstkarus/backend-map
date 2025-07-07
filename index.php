<?php
// index.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/clubs.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/comments.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/attendees.php';
require_once __DIR__ . '/sessions.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// --- JWT Middleware ---
$public_paths = ['/register', '/login', '/docs', '/token/refresh'];
if (!in_array($path, $public_paths)) {
    $token = get_bearer_token();
    $jwt_payload = $token ? verify_jwt($token) : false;
    if (!$jwt_payload) {
        http_response_code(401);
        echo json_encode(['error' => 'Неверный или просроченный access token']);
        exit;
    }
    $user_id = $jwt_payload['user_id'];
}

// --- Регистрация ---
if ($method === 'POST' && $path === '/register') {
    $data = json_decode(file_get_contents('php://input'), true);
    $device_id = $data['device_id'] ?? null;
    if (!$device_id) {
        http_response_code(400);
        echo json_encode(['error' => 'device_id обязателен']);
        exit;
    }
    if (!isset($data['email'], $data['password'], $data['name'], $data['city'], $data['birthdate'])) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Необходимы email, password, name, city, birthdate',
            'errors' => [
                'email' => 'Email обязателен для заполнения',
                'password' => 'Пароль обязателен для заполнения',
                'name' => 'Имя обязательно для заполнения',
                'city' => 'Город обязателен для заполнения',
                'birthdate' => 'Дата рождения обязательна для заполнения'
            ]
        ]);
        exit;
    }
    $result = register_user($data['email'], $data['password'], $data['name'], $data['city'], $data['birthdate'], $data['phone'] ?? null, $device_id);
    
    if (isset($result['error'])) {
        http_response_code(400);
    }
    echo json_encode($result);
    exit;
}

// --- Логин ---
if ($method === 'POST' && $path === '/login') {
    $data = json_decode(file_get_contents('php://input'), true);
    $device_id = $data['device_id'] ?? null;
    if (!$device_id) {
        http_response_code(400);
        echo json_encode(['error' => 'device_id обязателен']);
        exit;
    }
    if (!isset($data['email'], $data['password'])) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Необходимы email и password',
            'errors' => [
                'email' => 'Email обязателен для заполнения',
                'password' => 'Пароль обязателен для заполнения'
            ]
        ]);
        exit;
    }
    $result = login_user($data['email'], $data['password'], $device_id);
    
    if (isset($result['error'])) {
        http_response_code(400);
    }
    echo json_encode($result);
    exit;
}

// --- Logout (по refresh token из cookie) ---
if ($method === 'POST' && $path === '/logout') {
    $refresh_token = $_COOKIE['refresh_token'] ?? null;
    $device_id = $_POST['device_id'] ?? ($_GET['device_id'] ?? null);
    if (!$refresh_token || !$device_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Нет refresh token или device_id']);
        exit;
    }
    $result = logout_user_by_refresh($refresh_token, $device_id);
    echo json_encode($result);
    exit;
}

// --- Endpoint для обновления access/refresh токенов ---
if ($method === 'POST' && $path === '/token/refresh') {
    $refresh_token = $_COOKIE['refresh_token'] ?? null;
    $data = json_decode(file_get_contents('php://input'), true);
    $device_id = $data['device_id'] ?? null;
    if (!$refresh_token || !$device_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Нет refresh token или device_id']);
        exit;
    }
    $token_row = get_refresh_token($refresh_token, $device_id);
    if (!$token_row || strtotime($token_row['expires_at']) < time()) {
        http_response_code(401);
        echo json_encode(['error' => 'Refresh token невалиден или истёк']);
        exit;
    }
    deactivate_refresh_token($refresh_token, $device_id);
    $user_id = $token_row['user_id'];
    $user = get_user_by_id($user_id);
    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'Пользователь не найден']);
        exit;
    }
    $access_payload = [
        'user_id' => $user['id'],
        'email' => $user['email'],
        'role' => $user['role'],
        'is_verified' => $user['is_verified']
    ];
    $access_token = create_jwt($access_payload, 900); // 15 минут
    $new_refresh_token = generate_refresh_token();
    $refresh_expires = date('Y-m-d H:i:s', time() + 60*60*24*14); // 14 дней
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    save_refresh_token($user_id, $new_refresh_token, $refresh_expires, $user_agent, $ip, $device_id);
    setcookie('refresh_token', $new_refresh_token, [
        'expires' => strtotime($refresh_expires),
        'httponly' => true,
        'samesite' => 'Lax',
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS'])
    ]);
    echo json_encode(['access_token' => $access_token]);
    exit;
}

// --- /me ---
if ($method === 'GET' && $path === '/me') {
    $user = get_user_by_id($user_id);
    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'Пользователь не найден']);
        exit;
    }
    $response = [
        'success' => true,
        'user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'city' => $user['city'],
            'birthdate' => $user['birthdate'],
            'phone' => $user['phone'],
            'role' => $user['role'],
            'is_verified' => $user['is_verified']
        ]
    ];
    echo json_encode($response);
    exit;
}

if ($method === 'GET' && $path === '/clubs') {
    $clubs = get_clubs();
    echo json_encode(['clubs' => $clubs]);
    exit;
}

if ($method === 'GET' && preg_match('#^/clubs/(\\d+)$#', $path, $matches)) {
    $club = get_club((int)$matches[1]);
    if (!$club) {
        http_response_code(404);
        echo json_encode(['error' => 'Клуб не найден']);
        exit;
    }
    echo json_encode(['club' => $club]);
    exit;
}

if ($method === 'GET' && $path === '/events') {
    $filters = [];
    if (isset($_GET['type_id'])) $filters['type_id'] = (int)$_GET['type_id'];
    if (isset($_GET['date'])) $filters['date'] = $_GET['date'];
    if (isset($_GET['lat'])) $filters['lat'] = (float)$_GET['lat'];
    if (isset($_GET['lng'])) $filters['lng'] = (float)$_GET['lng'];
    if (isset($_GET['radius_km'])) $filters['radius_km'] = (float)$_GET['radius_km'];
    if (isset($_GET['city'])) $filters['city'] = $_GET['city'];
    $events = get_events($filters);
    echo json_encode(['events' => $events]);
    exit;
}

if ($method === 'GET' && preg_match('#^/events/(\\d+)$#', $path, $matches)) {
    $event = get_event((int)$matches[1]);
    if (!$event) {
        http_response_code(404);
        echo json_encode(['error' => 'Мероприятие не найдено']);
        exit;
    }
    echo json_encode(['event' => $event]);
    exit;
}

if ($method === 'POST' && preg_match('#^/clubs/(\\d+)/reviews$#', $path, $matches)) {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['rating'], $data['review'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Необходимы rating и review']);
        exit;
    }
    $result = add_club_review($user_id, (int)$matches[1], (int)$data['rating'], $data['review']);
    echo json_encode($result);
    exit;
}

if ($method === 'POST' && preg_match('#^/events/(\\d+)/comments$#', $path, $matches)) {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['comment'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Необходим comment']);
        exit;
    }
    $result = add_event_comment($user_id, (int)$matches[1], $data['comment']);
    echo json_encode($result);
    exit;
}

if ($method === 'POST' && preg_match('#^/events/(\\d+)/attend$#', $path, $matches)) {
    $result = attend_event($user_id, (int)$matches[1]);
    echo json_encode($result);
    exit;
}

if ($method === 'DELETE' && preg_match('#^/events/(\\d+)/attend$#', $path, $matches)) {
    $result = unattend_event($user_id, (int)$matches[1]);
    echo json_encode($result);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Not found']); 