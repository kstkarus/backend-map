<?php
// db.php

class Database {
    private $pdo;

    public function __construct() {
        $config = require __DIR__ . '/config.php';
        $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset={$config['db_charset']}";
        try {
            $this->pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            // Логируем подробности, но не показываем их пользователю в проде
            $log_dir = __DIR__ . '/logs';
            if (!is_dir($log_dir)) { @mkdir($log_dir, 0777, true); }
            @file_put_contents($log_dir . '/app_errors.log', '[' . date('c') . "] DB connection error: " . $e->getMessage() . "\n", FILE_APPEND);
            $isDev = false;
            $env = getenv('APP_ENV');
            if ($env === false && isset($_SERVER['APP_ENV'])) { $env = $_SERVER['APP_ENV']; }
            if (strtolower((string)$env) === 'dev') { $isDev = true; }
            $payload = ['error' => 'Internal server error'];
            if ($isDev) { $payload['debug'] = 'Database connection failed: ' . $e->getMessage(); }
            die(json_encode($payload));
        }
    }

    public function getPdo() {
        return $this->pdo;
    }
} 