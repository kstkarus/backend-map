<?php
// index.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/clubs.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/comments.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/attendees.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Только для /register и /login не требуется токен
if (!in_array($path, ['/register', '/login'])) {
    $headers = getallheaders();
    $auth = isset($headers['Authorization']) ? $headers['Authorization'] : (isset($headers['authorization']) ? $headers['authorization'] : '');
    if (preg_match('/^Bearer (.+)$/', $auth, $matches)) {
        $jwt = $matches[1];
        $payload = verify_jwt($jwt);
        if (!$payload) {
            http_response_code(401);
            echo json_encode(['error' => 'Неверный или просроченный токен']);
            exit;
        }
        // user_id и user доступны для дальнейших действий
        $user_id = $payload['user_id'];
        $user_payload = $payload;
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Требуется токен авторизации']);
        exit;
    }
}

if ($method === 'POST' && $path === '/register') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['email'], $data['password'], $data['name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Необходимы email, password и name']);
        exit;
    }
    $result = register_user($data['email'], $data['password'], $data['name']);
    echo json_encode($result);
    exit;
}

if ($method === 'POST' && $path === '/login') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['email'], $data['password'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Необходимы email и password']);
        exit;
    }
    $result = login_user($data['email'], $data['password']);
    echo json_encode($result);
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

if ($method === 'GET' && $path === '/docs') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/docs.html');
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Not found']); 