<?php
// comments.php

require_once __DIR__ . '/db.php';

function add_club_review($user_id, $club_id, $rating, $review) {
    $db = new Database();
    $pdo = $db->getPdo();
    // Проверка: уже оставлял отзыв?
    $stmt = $pdo->prepare('SELECT id FROM club_reviews WHERE user_id = ? AND club_id = ?');
    $stmt->execute([$user_id, $club_id]);
    if ($stmt->fetch()) {
        return ['error' => 'Вы уже оставляли отзыв этому клубу'];
    }
    $stmt = $pdo->prepare('INSERT INTO club_reviews (user_id, club_id, rating, review) VALUES (?, ?, ?, ?)');
    $stmt->execute([$user_id, $club_id, $rating, $review]);
    // Пересчет рейтинга клуба
    $stmt = $pdo->prepare('SELECT AVG(rating) FROM club_reviews WHERE club_id = ?');
    $stmt->execute([$club_id]);
    $avg = $stmt->fetchColumn();
    $stmt = $pdo->prepare('UPDATE clubs SET rating = ? WHERE id = ?');
    $stmt->execute([$avg, $club_id]);
    return ['success' => true];
}

function add_event_comment($user_id, $event_id, $comment) {
    $db = new Database();
    $pdo = $db->getPdo();
    $stmt = $pdo->prepare('INSERT INTO event_comments (user_id, event_id, comment) VALUES (?, ?, ?)');
    $stmt->execute([$user_id, $event_id, $comment]);
    return ['success' => true];
} 