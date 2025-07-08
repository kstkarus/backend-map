<?php
// events.php

require_once __DIR__ . '/db.php';

function get_events($filters = []) {
    $db = new Database();
    $pdo = $db->getPdo();
    $where = ['status = "approved"'];
    $params = [];
    if (!empty($filters['type_id'])) {
        $where[] = 'type_id = ?';
        $params[] = $filters['type_id'];
    }
    if (!empty($filters['date'])) {
        $where[] = 'DATE(start_time) = ?';
        $params[] = $filters['date'];
    }
    if (!empty($filters['city'])) {
        $where[] = 'city = ?';
        $params[] = $filters['city'];
    }
    // Фильтр по расстоянию (если заданы координаты и радиус)
    if (!empty($filters['lat']) && !empty($filters['lng']) && !empty($filters['radius_km'])) {
        $where[] = '(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) < ?';
        $params[] = $filters['lat'];
        $params[] = $filters['lng'];
        $params[] = $filters['lat'];
        $params[] = $filters['radius_km'];
    }
    $sql = 'SELECT * FROM events';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY start_time ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll();
    return $events;
}

function get_event($event_id) {
    $db = new Database();
    $pdo = $db->getPdo();
    $stmt = $pdo->prepare('SELECT e.*, et.name as type_name, u.name as creator_name, c.name as club_name FROM events e
        LEFT JOIN event_types et ON e.type_id = et.id
        LEFT JOIN users u ON e.creator_id = u.id
        LEFT JOIN clubs c ON e.club_id = c.id
        WHERE e.id = ?');
    $stmt->execute([$event_id]);
    $event = $stmt->fetch();
    if (!$event) return null;
    // Фотографии
    $stmt = $pdo->prepare('SELECT image_url FROM event_photos WHERE event_id = ?');
    $stmt->execute([$event_id]);
    $event['photos'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    // Количество "я пойду"
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM event_attendees WHERE event_id = ?');
    $stmt->execute([$event_id]);
    $event['attendees_count'] = (int)$stmt->fetchColumn();
    // Комментарии
    $stmt = $pdo->prepare('SELECT ec.*, u.name as user_name FROM event_comments ec JOIN users u ON ec.user_id = u.id WHERE ec.event_id = ? ORDER BY ec.created_at DESC');
    $stmt->execute([$event_id]);
    $event['comments'] = $stmt->fetchAll();
    return $event;
}

function get_user_events($user_id, $filters = []) {
    $db = new Database();
    $pdo = $db->getPdo();
    $where = ['a.user_id = ?','e.status = "approved"'];
    $params = [$user_id];
    if (!empty($filters['type_id'])) {
        $where[] = 'e.type_id = ?';
        $params[] = $filters['type_id'];
    }
    if (!empty($filters['date'])) {
        $where[] = 'DATE(e.start_time) = ?';
        $params[] = $filters['date'];
    }
    if (!empty($filters['city'])) {
        $where[] = 'e.city = ?';
        $params[] = $filters['city'];
    }
    $sql = 'SELECT e.* FROM events e JOIN event_attendees a ON a.event_id = e.id';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY e.start_time ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll();
    return $events;
} 