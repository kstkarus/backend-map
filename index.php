<?php
// index.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/clubs.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/comments.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/attendees.php';
require_once __DIR__ . '/validation.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);




if (!in_array($path, ['/register', '/login', '/docs', '/cities', '/auth/refresh', '/password/forgot', '/password/reset', '/reset'])) {
    $headers = getallheaders();
    $auth = isset($headers['Authorization']) ? $headers['Authorization'] : (isset($headers['authorization']) ? $headers['authorization'] : '');
    if (preg_match('/^Bearer (.+)$/', $auth, $matches)) {
        $token = $matches[1];
        $session = verify_jwt($token);
        if (!$session) {
            http_response_code(401);
            echo json_encode(['error' => 'Неверный или просроченный токен']);
            exit;
        }
        $user_id = $session['user_id'];
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Требуется токен авторизации']);
        exit;
    }
}

if ($method === 'POST' && $path === '/register') {
    $data = json_decode(file_get_contents('php://input'), true);
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
    // // Валидация города через функцию
    // if (!empty($data['city'])) {
    //     $city_check = validate_city_db($data['city']);
    //     if (!$city_check['valid']) {
    //         http_response_code(400);
    //         echo json_encode(['error' => $city_check['error']]);
    //         exit;
    //     }
    // }
    $device_id = $data['device_id'] ?? '';
    $result = register_user(
        $data['email'],
        $data['password'],
        $data['name'] ?? null,
        $data['city'] ?? null,
        $data['birthdate'] ?? null,
        $data['phone'] ?? null,
        $device_id
    );
    
    if (isset($result['error'])) {
        http_response_code(400);
    }
    echo json_encode($result);
    exit;
}

if ($method === 'POST' && $path === '/login') {
    $data = json_decode(file_get_contents('php://input'), true);
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
    $device_id = $data['device_id'] ?? '';
    $result = login_user($data['email'], $data['password'], $device_id);
    
    if (isset($result['error'])) {
        http_response_code(400);
    }
    echo json_encode($result);
    exit;
}

if ($method === 'POST' && $path === '/logout') {
    $refresh_token = $_COOKIE['refresh_token'] ?? null;
    $data = json_decode(file_get_contents('php://input'), true);
    $device_id = $data['device_id'] ?? null;
    if (!$refresh_token || !$device_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Нет refresh token или device_id']);
        exit;
    }
    $result = logout_user_by_refresh($refresh_token, $device_id);
    echo json_encode($result);
    exit;
}

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

if ($method === 'PATCH' && $path === '/me') {
    $data = json_decode(file_get_contents('php://input'), true);
    $validation = validate_profile_update_data($data);
    if (!$validation['valid']) {
        http_response_code(400);
        echo json_encode([
            'error' => $validation['message'],
            'errors' => $validation['errors']
        ]);
        exit;
    }
    $fields = [];
    $params = [];
    if (isset($data['name'])) {
        $fields[] = 'name = ?';
        $params[] = $data['name'];
    }
    if (isset($data['city'])) {
        $fields[] = 'city = ?';
        $params[] = $data['city'];
    }
    if (isset($data['birthdate'])) {
        $fields[] = 'birthdate = ?';
        $params[] = $data['birthdate'];
    }
    if (isset($data['phone'])) {
        $fields[] = 'phone = ?';
        $params[] = $data['phone'];
    }
    if (empty($fields)) {
        http_response_code(400);
        echo json_encode(['error' => 'Нет данных для обновления']);
        exit;
    }
    $params[] = $user_id;
    $db = new Database();
    $pdo = $db->getPdo();
    $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'GET' && $path === '/me/reviews') {
    $db = new Database();
    $pdo = $db->getPdo();
    // Отзывы на клубы
    $stmt = $pdo->prepare('SELECT id, club_id, rating, review, created_at FROM club_reviews WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->execute([$user_id]);
    $club_reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Отзывы на события (комментарии)
    $stmt = $pdo->prepare('SELECT id, event_id, comment, created_at FROM event_comments WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->execute([$user_id]);
    $event_comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode([
        'club_reviews' => $club_reviews,
        'event_comments' => $event_comments
    ]);
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

if ($method === 'GET' && $path === '/events/attending') {
    $filters = [];
    if (isset($_GET['type_id'])) $filters['type_id'] = (int)$_GET['type_id'];
    if (isset($_GET['date'])) $filters['date'] = $_GET['date'];
    if (isset($_GET['city'])) $filters['city'] = $_GET['city'];
    $events = get_user_events($user_id, $filters);
    echo json_encode(['events' => $events]);
    exit;
}

if ($method === 'GET' && $path === '/auth/sessions') {
    $db = new Database();
    $pdo = $db->getPdo();
    $stmt = $pdo->prepare('SELECT id, device_id, user_agent, ip_address, created_at, expires_at FROM refresh_tokens WHERE user_id = ? AND is_active = 1');
    $stmt->execute([$user_id]);
    $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['refresh_tokens' => $tokens]);
    exit;
}

if ($method === 'DELETE' && preg_match('#^/auth/sessions/(\d+)$#', $path, $matches)) {
    $token_id = (int)$matches[1];
    $db = new Database();
    $pdo = $db->getPdo();
    // Проверяем, что токен принадлежит пользователю
    $stmt = $pdo->prepare('SELECT token FROM refresh_tokens WHERE id = ? AND user_id = ? AND is_active = 1');
    $stmt->execute([$token_id, $user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Токен не найден']);
        exit;
    }
    // Деактивируем токен
    deactivate_refresh_token($row['token']);
    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'GET' && $path === '/cities') {
    $db = new Database();
    $pdo = $db->getPdo();
    $stmt = $pdo->query('SELECT id, name FROM cities WHERE is_active = 1 ORDER BY name');
    $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['cities' => $cities]);
    exit;
}

if ($method === 'POST' && $path === '/auth/refresh') {
    $refresh_token = $_COOKIE['refresh_token'] ?? null;
    $data = json_decode(file_get_contents('php://input'), true);
    $device_id = $data['device_id'] ?? null;
    if (!$refresh_token || !$device_id) {
        http_response_code(401);
        echo json_encode(['error' => 'Нет refresh token или device_id']);
        exit;
    }
    $row = get_refresh_token($refresh_token, $device_id);
    if (!$row) {
        http_response_code(401);
        echo json_encode(['error' => 'Refresh token невалиден или истёк']);
        exit;
    }
    // Деактивируем старый refresh_token (ротация)
    deactivate_refresh_token($refresh_token, $device_id);
    delete_refresh_token($refresh_token, $device_id); // Удаляем старый refresh_token
    // Генерируем новые токены
    $user_id = $row['user_id'];
    $user = get_user_by_id($user_id);
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Пользователь не найден']);
        exit;
    }
    $access_payload = [
        'user_id' => $user_id,
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
    echo json_encode([
        'access_token' => $access_token
    ]);
    exit;
}

if ($method === 'POST' && $path === '/password/forgot') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['email'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Email обязателен']);
        exit;
    }
    $user = get_user_by_email($data['email']);
    
    if ($user) {
        $token = generate_reset_token();
        $expires = date('Y-m-d H:i:s', time() + 3600); // 1 час
        create_password_reset($user['id'], $token, $expires);
        $reset_link = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . '/reset?token=' . $token;
        send_reset_email($user['email'], $reset_link);
    }
    echo json_encode(['success' => true, 'message' => 'Если такой email существует, инструкция отправлена']);
    exit;
}

if ($method === 'POST' && $path === '/password/reset') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['token'], $data['password'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Требуются token и password']);
        exit;
    }

    $password_validation = validate_password($data['password']);
    if (!$password_validation['valid']) {
        http_response_code(400);
        echo json_encode([
            'error' => $password_validation['error'],
            'errors' => ['password' => $password_validation['error']]
        ]);
        exit;
    }
    $reset = get_password_reset($data['token']);
    if (!$reset) {
        http_response_code(400);
        echo json_encode(['error' => 'Токен невалиден или истёк']);
        exit;
    }
    update_user_password($reset['user_id'], $data['password']);
    mark_password_reset_used($reset['id']);
    echo json_encode(['success' => true, 'message' => 'Пароль успешно изменён']);
    exit;
}

if ($method === 'GET' && $path === '/reset') {
    $token = isset($_GET['token']) ? urlencode($_GET['token']) : '';
    $location = '/reset_password.html' . ($token ? ('?token=' . $token) : '');
    header('Location: ' . $location, true, 302);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Not found']); 