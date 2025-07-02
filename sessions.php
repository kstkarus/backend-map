<?php
// sessions.php

require_once __DIR__ . '/db.php';

function get_user_sessions($user_id) {
    $db = new Database();
    $pdo = $db->getPdo();
    $stmt = $pdo->prepare('SELECT id, user_agent, ip_address, created_at, last_active, is_active FROM sessions WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

function delete_session($user_id, $session_id) {
    $db = new Database();
    $pdo = $db->getPdo();
    // Только свою сессию
    $stmt = $pdo->prepare('UPDATE sessions SET is_active = 0 WHERE id = ? AND user_id = ?');
    $stmt->execute([$session_id, $user_id]);
    return ['success' => true];
} 