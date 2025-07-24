<?php
// clubs.php

require_once __DIR__ . '/db.php';

function get_clubs($filters = []) {
    $db = new Database();
    $pdo = $db->getPdo();
    $where = [];
    $params = [];
    if (!empty($filters['city'])) {
        $where[] = 'city = ?';
        $params[] = $filters['city'];
    }
    $sql = 'SELECT * FROM clubs';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    // Сортировка
    $sort = $filters['sort'] ?? null;
    $order = '';
    if ($sort === 'rating_desc') $order = 'rating DESC';
    elseif ($sort === 'rating_asc') $order = 'rating ASC';
    elseif ($sort === 'price_desc') $order = 'price_level DESC';
    elseif ($sort === 'price_asc') $order = 'price_level ASC';
    elseif ($sort === 'name_asc') $order = 'name ASC';
    elseif ($sort === 'name_desc') $order = 'name DESC';
    if ($order) {
        $sql .= ' ORDER BY ' . $order;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $clubs = $stmt->fetchAll();
    // Получаем фотографии для всех клубов
    $club_ids = array_column($clubs, 'id');
    // Получаем схему и хост
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base_url = $scheme . '://' . $host;
    if (count($club_ids) > 0) {
        $in  = str_repeat('?,', count($club_ids) - 1) . '?';
        $photo_stmt = $pdo->prepare('SELECT club_id, image_url FROM club_photos WHERE club_id IN (' . $in . ')');
        $photo_stmt->execute($club_ids);
        $photos = $photo_stmt->fetchAll();
        // Группируем фотографии по club_id
        $photos_by_club = [];
        foreach ($photos as $photo) {
            $photos_by_club[$photo['club_id']][] = $base_url . '/photo.php?file=' . urlencode($photo['image_url']);
        }
        // Добавляем фотографии к клубам
        foreach ($clubs as &$club) {
            $club['photos'] = $photos_by_club[$club['id']] ?? [];
            // Новые поля для обратной совместимости
            $club['address'] = $club['address'] ?? null;
            $club['price_level'] = $club['price_level'] ?? null;
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
    // Получаем схему и хост
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base_url = $scheme . '://' . $host;
    // Получаем фотографии
    $stmt = $pdo->prepare('SELECT image_url FROM club_photos WHERE club_id = ?');
    $stmt->execute([$club_id]);
    $photos = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $club['photos'] = array_map(function($img) use ($base_url) { return $base_url . '/photo.php?file=' . urlencode($img); }, $photos);
    // Новые поля для обратной совместимости
    $club['address'] = $club['address'] ?? null;
    $club['price_level'] = $club['price_level'] ?? null;
    // Получаем отзывы
    $stmt = $pdo->prepare('SELECT cr.*, u.name as user_name FROM club_reviews cr JOIN users u ON cr.user_id = u.id WHERE cr.club_id = ? ORDER BY cr.created_at DESC');
    $stmt->execute([$club_id]);
    $club['reviews'] = $stmt->fetchAll();
    return $club;
}

function add_club_favorite($user_id, $club_id) {
    $db = new Database();
    $pdo = $db->getPdo();
    // Проверка: клуб существует?
    $stmt = $pdo->prepare('SELECT id FROM clubs WHERE id = ?');
    $stmt->execute([$club_id]);
    if (!$stmt->fetch()) {
        return ['error' => 'Клуб не найден'];
    }
    // Уже в избранном?
    $stmt = $pdo->prepare('SELECT id FROM club_favorites WHERE user_id = ? AND club_id = ?');
    $stmt->execute([$user_id, $club_id]);
    if ($stmt->fetch()) {
        return ['error' => 'Клуб уже в избранном'];
    }
    $stmt = $pdo->prepare('INSERT INTO club_favorites (user_id, club_id) VALUES (?, ?)');
    $stmt->execute([$user_id, $club_id]);
    return ['success' => true];
}

function remove_club_favorite($user_id, $club_id) {
    $db = new Database();
    $pdo = $db->getPdo();
    $stmt = $pdo->prepare('DELETE FROM club_favorites WHERE user_id = ? AND club_id = ?');
    $stmt->execute([$user_id, $club_id]);
    return ['success' => true];
}

function get_user_favorite_clubs($user_id) {
    $db = new Database();
    $pdo = $db->getPdo();
    $stmt = $pdo->prepare('SELECT club_id FROM club_favorites WHERE user_id = ?');
    $stmt->execute([$user_id]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) return [];
    // Получаем клубы по id
    $in = str_repeat('?,', count($ids) - 1) . '?';
    $stmt = $pdo->prepare('SELECT * FROM clubs WHERE id IN (' . $in . ')');
    $stmt->execute($ids);
    $clubs = $stmt->fetchAll();
    // Добавляем фото и прочее (копируем из get_clubs)
    $club_ids = array_column($clubs, 'id');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base_url = $scheme . '://' . $host;
    if (count($club_ids) > 0) {
        $in2  = str_repeat('?,', count($club_ids) - 1) . '?';
        $photo_stmt = $pdo->prepare('SELECT club_id, image_url FROM club_photos WHERE club_id IN (' . $in2 . ')');
        $photo_stmt->execute($club_ids);
        $photos = $photo_stmt->fetchAll();
        $photos_by_club = [];
        foreach ($photos as $photo) {
            $photos_by_club[$photo['club_id']][] = $base_url . '/photo.php?file=' . urlencode($photo['image_url']);
        }
        foreach ($clubs as &$club) {
            $club['photos'] = $photos_by_club[$club['id']] ?? [];
            $club['address'] = $club['address'] ?? null;
            $club['price_level'] = $club['price_level'] ?? null;
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