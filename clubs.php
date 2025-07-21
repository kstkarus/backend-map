<?php
// clubs.php

require_once __DIR__ . '/db.php';

function get_clubs() {
    $db = new Database();
    $pdo = $db->getPdo();
    $stmt = $pdo->query('SELECT * FROM clubs');
    $clubs = $stmt->fetchAll();
    // Получаем фотографии для всех клубов
    $club_ids = array_column($clubs, 'id');
    if (count($club_ids) > 0) {
        $in  = str_repeat('?,', count($club_ids) - 1) . '?';
        $photo_stmt = $pdo->prepare('SELECT club_id, image_url FROM club_photos WHERE club_id IN (' . $in . ')');
        $photo_stmt->execute($club_ids);
        $photos = $photo_stmt->fetchAll();
        // Группируем фотографии по club_id
        $photos_by_club = [];
        foreach ($photos as $photo) {
            $photos_by_club[$photo['club_id']][] = '/photo.php?file=' . urlencode($photo['image_url']);
        }
        // Добавляем фотографии к клубам
        foreach ($clubs as &$club) {
            $club['photos'] = $photos_by_club[$club['id']] ?? [];
        }
        unset($club);
    } else {
        foreach ($clubs as &$club) {
            $club['photos'] = [];
        }
        unset($club);
    }
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
    $photos = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $club['photos'] = array_map(function($img) { return '/photo.php?file=' . urlencode($img); }, $photos);
    // Получаем отзывы
    $stmt = $pdo->prepare('SELECT cr.*, u.name as user_name FROM club_reviews cr JOIN users u ON cr.user_id = u.id WHERE cr.club_id = ? ORDER BY cr.created_at DESC');
    $stmt->execute([$club_id]);
    $club['reviews'] = $stmt->fetchAll();
    return $club;
} 