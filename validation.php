<?php
// validation.php

/**
 * Валидация email адреса
 */
function validate_email($email) {
    if (empty($email)) {
        return ['valid' => false, 'error' => 'Email обязателен для заполнения'];
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['valid' => false, 'error' => 'Введите корректный email адрес'];
    }
    
    if (strlen($email) > 255) {
        return ['valid' => false, 'error' => 'Email не должен превышать 255 символов'];
    }
    
    return ['valid' => true];
}

/**
 * Валидация пароля
 */
function validate_password($password) {
    if (empty($password)) {
        return ['valid' => false, 'error' => 'Пароль обязателен для заполнения'];
    }
    
    if (strlen($password) < 8) {
        return ['valid' => false, 'error' => 'Пароль должен содержать минимум 8 символов'];
    }
    
    if (strlen($password) > 128) {
        return ['valid' => false, 'error' => 'Пароль не должен превышать 128 символов'];
    }
    
    // Проверка на наличие хотя бы одной буквы и цифры
    if (!preg_match('/[a-zA-Z]/', $password)) {
        return ['valid' => false, 'error' => 'Пароль должен содержать хотя бы одну букву'];
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        return ['valid' => false, 'error' => 'Пароль должен содержать хотя бы одну цифру'];
    }
    
    return ['valid' => true];
}

/**
 * Валидация имени пользователя
 */
function validate_name($name) {
    if (empty($name)) {
        return ['valid' => false, 'error' => 'Имя обязательно для заполнения'];
    }
    
    if (strlen($name) < 2) {
        return ['valid' => false, 'error' => 'Имя должно содержать минимум 2 символа'];
    }
    
    if (strlen($name) > 255) {
        return ['valid' => false, 'error' => 'Имя не должно превышать 255 символов'];
    }
    
    // Проверка на допустимые символы (буквы, пробелы, дефисы, апострофы)
    if (!preg_match('/^[a-zA-Zа-яА-ЯёЁ\s\'-]+$/u', $name)) {
        return ['valid' => false, 'error' => 'Имя может содержать только буквы, пробелы, дефисы и апострофы'];
    }
    
    return ['valid' => true];
}

/**
 * Валидация города
 */
function validate_city($city) {
    if (empty($city)) {
        return ['valid' => false, 'error' => 'Город обязателен для заполнения'];
    }
    
    if (strlen($city) < 2) {
        return ['valid' => false, 'error' => 'Название города должно содержать минимум 2 символа'];
    }
    
    if (strlen($city) > 100) {
        return ['valid' => false, 'error' => 'Название города не должно превышать 100 символов'];
    }
    
    // Проверка на допустимые символы
    if (!preg_match('/^[a-zA-Zа-яА-ЯёЁ\s\'-]+$/u', $city)) {
        return ['valid' => false, 'error' => 'Название города может содержать только буквы, пробелы, дефисы и апострофы'];
    }
    
    return ['valid' => true];
}

/**
 * Валидация даты рождения
 */
function validate_birthdate($birthdate) {
    if (empty($birthdate)) {
        return ['valid' => false, 'error' => 'Дата рождения обязательна для заполнения'];
    }
    
    // Проверка формата даты
    $date = DateTime::createFromFormat('Y-m-d', $birthdate);
    if (!$date || $date->format('Y-m-d') !== $birthdate) {
        return ['valid' => false, 'error' => 'Введите корректную дату в формате ГГГГ-ММ-ДД'];
    }
    
    // Проверка возраста (минимум 13 лет, максимум 120 лет)
    $today = new DateTime();
    $age = $today->diff($date)->y;
    
    if ($age < 13) {
        return ['valid' => false, 'error' => 'Вам должно быть не менее 13 лет для регистрации'];
    }
    
    if ($age > 120) {
        return ['valid' => false, 'error' => 'Проверьте корректность даты рождения'];
    }
    
    return ['valid' => true];
}

/**
 * Валидация телефона (опционально)
 */
function validate_phone($phone) {
    if (empty($phone)) {
        return ['valid' => true]; // Телефон опциональный
    }
    
    // Убираем все символы кроме цифр
    $clean_phone = preg_replace('/[^0-9]/', '', $phone);
    
    if (strlen($clean_phone) < 10) {
        return ['valid' => false, 'error' => 'Введите корректный номер телефона'];
    }
    
    if (strlen($clean_phone) > 15) {
        return ['valid' => false, 'error' => 'Номер телефона слишком длинный'];
    }
    
    return ['valid' => true, 'clean_phone' => $clean_phone];
}

/**
 * Комплексная валидация данных регистрации
 */
function validate_registration_data($data) {
    $errors = [];
    
    // Валидация email
    $email_validation = validate_email($data['email'] ?? '');
    if (!$email_validation['valid']) {
        $errors['email'] = $email_validation['error'];
    }
    
    // Валидация пароля
    $password_validation = validate_password($data['password'] ?? '');
    if (!$password_validation['valid']) {
        $errors['password'] = $password_validation['error'];
    }
    
    // Валидация имени
    $name_validation = validate_name($data['name'] ?? '');
    if (!$name_validation['valid']) {
        $errors['name'] = $name_validation['error'];
    }
    
    // Валидация города
    $city_validation = validate_city($data['city'] ?? '');
    if (!$city_validation['valid']) {
        $errors['city'] = $city_validation['error'];
    }
    
    // Валидация даты рождения
    $birthdate_validation = validate_birthdate($data['birthdate'] ?? '');
    if (!$birthdate_validation['valid']) {
        $errors['birthdate'] = $birthdate_validation['error'];
    }
    
    // Валидация телефона
    $phone_validation = validate_phone($data['phone'] ?? '');
    if (!$phone_validation['valid']) {
        $errors['phone'] = $phone_validation['error'];
    }
    
    if (!empty($errors)) {
        return [
            'valid' => false,
            'errors' => $errors,
            'message' => 'Проверьте правильность заполнения полей'
        ];
    }
    
    return ['valid' => true];
}

/**
 * Валидация данных входа
 */
function validate_login_data($data) {
    $errors = [];
    
    // Валидация email
    $email_validation = validate_email($data['email'] ?? '');
    if (!$email_validation['valid']) {
        $errors['email'] = $email_validation['error'];
    }
    
    // Валидация пароля
    if (empty($data['password'])) {
        $errors['password'] = 'Пароль обязателен для заполнения';
    }
    
    if (!empty($errors)) {
        return [
            'valid' => false,
            'errors' => $errors,
            'message' => 'Проверьте правильность заполнения полей'
        ];
    }
    
    return ['valid' => true];
} 