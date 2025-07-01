<?php
// clubs.php

require_once __DIR__ . '/db.php';

function get_clubs() {
    $db = new Database();
    $pdo = $db->getPdo();
    $stmt = $pdo->query('SELECT * FROM clubs');
    $clubs = $stmt->fetchAll();
    return $clubs;
}

function get_club($club_id) {
    $db = new Database();
    $pdo = $db->getPdo();
    $stmt = $pdo->prepare('SELECT * FROM clubs WHERE id = ?');
    $stmt->execute([$club_id]);
    $club = $stmt->fetch();
    if (!$club) return null;
    // Получаем фотографии
    $stmt = $pdo->prepare('SELECT image_url FROM club_photos WHERE club_id = ?');
    $stmt->execute([$club_id]);
    $club['photos'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    // Получаем отзывы
    $stmt = $pdo->prepare('SELECT cr.*, u.name as user_name FROM club_reviews cr JOIN users u ON cr.user_id = u.id WHERE cr.club_id = ? ORDER BY cr.created_at DESC');
    $stmt->execute([$club_id]);
    $club['reviews'] = $stmt->fetchAll();
    return $club;
} 