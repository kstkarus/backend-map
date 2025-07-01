<?php
// attendees.php

require_once __DIR__ . '/db.php';

function attend_event($user_id, $event_id) {
    $db = new Database();
    $pdo = $db->getPdo();
    // Проверка: уже отмечался?
    $stmt = $pdo->prepare('SELECT id FROM event_attendees WHERE user_id = ? AND event_id = ?');
    $stmt->execute([$user_id, $event_id]);
    if ($stmt->fetch()) {
        return ['error' => 'Вы уже отметились на это мероприятие'];
    }
    $stmt = $pdo->prepare('INSERT INTO event_attendees (user_id, event_id) VALUES (?, ?)');
    $stmt->execute([$user_id, $event_id]);
    return ['success' => true];
}

function unattend_event($user_id, $event_id) {
    $db = new Database();
    $pdo = $db->getPdo();
    $stmt = $pdo->prepare('DELETE FROM event_attendees WHERE user_id = ? AND event_id = ?');
    $stmt->execute([$user_id, $event_id]);
    return ['success' => true];
} 