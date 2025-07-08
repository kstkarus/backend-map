<?php
// seed.php

require_once __DIR__ . '/db.php';

$db = new Database();
$pdo = $db->getPdo();

// Очищаем таблицы
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$pdo->exec('TRUNCATE TABLE event_attendees');
$pdo->exec('TRUNCATE TABLE event_comments');
$pdo->exec('TRUNCATE TABLE event_photos');
$pdo->exec('TRUNCATE TABLE events');
$pdo->exec('TRUNCATE TABLE event_types');
$pdo->exec('TRUNCATE TABLE club_reviews');
$pdo->exec('TRUNCATE TABLE club_photos');
$pdo->exec('TRUNCATE TABLE clubs');
$pdo->exec('TRUNCATE TABLE users');
$pdo->exec('TRUNCATE TABLE cities');
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

// Пользователи
$users = [
    // user1: только обязательные поля
    ['user1@example.com', password_hash('password1', PASSWORD_DEFAULT), null, null, null, null, 'user'],
    // user2: все поля заполнены
    ['user2@example.com', password_hash('password2', PASSWORD_DEFAULT), 'Мария', 'Санкт-Петербург', '1995-05-10', '+79992223344', 'user'],
    // admin: только email, пароль, имя
    ['admin@example.com', password_hash('adminpass', PASSWORD_DEFAULT), 'Админ', null, null, null, 'admin'],
    // user3: email, пароль, имя, город
    ['user3@example.com', password_hash('password3', PASSWORD_DEFAULT), 'Алексей', 'Казань', null, null, 'user'],
    // user4: email, пароль, имя, дата рождения, телефон
    ['user4@example.com', password_hash('password4', PASSWORD_DEFAULT), 'Ольга', null, '1988-07-15', '+79995556677', 'user'],
];
foreach ($users as $u) {
    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, name, city, birthdate, phone, role, is_verified) VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
    $stmt->execute($u);
}

// Клубы
$clubs = [
    ['Night Club X', 'Лучший клуб города!', 55.751244, 37.618423, 4.5],
    ['Bar Y', 'Уютный бар для своих.', 55.760186, 37.618711, 4.0],
];
foreach ($clubs as $c) {
    $stmt = $pdo->prepare('INSERT INTO clubs (name, description, latitude, longitude, rating) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute($c);
}

// Типы мероприятий
$types = ['Вечеринка', 'Концерт', 'Квиз'];
foreach ($types as $t) {
    $stmt = $pdo->prepare('INSERT INTO event_types (name) VALUES (?)');
    $stmt->execute([$t]);
}

// Мероприятия
$events = [
    [1, 1, 'Большая вечеринка', 'Танцы до утра!', 55.751244, 37.618423, 'Москва', date('Y-m-d 21:00:00'), date('Y-m-d 23:59:59'), 1, 'approved'],
    [2, 2, 'Живой концерт', 'Выступление группы XYZ', 55.760186, 37.618711, 'Санкт-Петербург', date('Y-m-d 19:00:00'), date('Y-m-d 22:00:00'), 2, 'approved'],
];
foreach ($events as $e) {
    $stmt = $pdo->prepare('INSERT INTO events (club_id, creator_id, title, description, latitude, longitude, city, start_time, end_time, type_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute($e);
}

// Отзывы о клубах
$reviews = [
    [1, 1, 5, 'Очень понравилось!'],
    [2, 2, 4, 'Хорошее место!'],
];
foreach ($reviews as $r) {
    $stmt = $pdo->prepare('INSERT INTO club_reviews (user_id, club_id, rating, review) VALUES (?, ?, ?, ?)');
    $stmt->execute($r);
}

// Фото клубов (заглушки)
$photos = [
    [1, 'club1.jpg'],
    [2, 'club2.jpg'],
];
foreach ($photos as $p) {
    $stmt = $pdo->prepare('INSERT INTO club_photos (club_id, image_url) VALUES (?, ?)');
    $stmt->execute($p);
}

// Фото мероприятий (заглушки)
$event_photos = [
    [1, 'event1.jpg'],
    [2, 'event2.jpg'],
];
foreach ($event_photos as $p) {
    $stmt = $pdo->prepare('INSERT INTO event_photos (event_id, image_url) VALUES (?, ?)');
    $stmt->execute($p);
}

// Комментарии к мероприятиям
$comments = [
    [1, 1, 'Буду обязательно!'],
    [2, 2, 'Жду с нетерпением!'],
];
foreach ($comments as $c) {
    $stmt = $pdo->prepare('INSERT INTO event_comments (user_id, event_id, comment) VALUES (?, ?, ?)');
    $stmt->execute($c);
}

// Участники мероприятий
$attendees = [
    [1, 1],
    [2, 2],
];
foreach ($attendees as $a) {
    $stmt = $pdo->prepare('INSERT INTO event_attendees (user_id, event_id) VALUES (?, ?)');
    $stmt->execute($a);
}

// Города
$cities = [
    ['Москва', 1],
    ['Санкт-Петербург', 1],
    ['Казань', 1],
    ['Новосибирск', 0], // неактивный город
    ['Екатеринбург', 1],
];
foreach ($cities as $c) {
    $stmt = $pdo->prepare('INSERT INTO cities (name, is_active) VALUES (?, ?)');
    $stmt->execute($c);
}

echo "Seed успешно выполнен!\n"; 