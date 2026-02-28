<?php

class Database
{
    private PDO $pdo;

    public function __construct(array $config)
    {
        $this->pdo = new PDO('sqlite:' . $config['db_path']);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->initialize();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function initialize(): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS access_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    subject_type TEXT NOT NULL,
    subject_value TEXT NOT NULL,
    schedule TEXT NOT NULL,
    zone TEXT NOT NULL,
    source TEXT NOT NULL DEFAULT 'local',
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS sip_clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    sip_server TEXT NOT NULL,
    sip_user TEXT NOT NULL,
    sip_password TEXT NOT NULL,
    ext_number TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    input_type TEXT NOT NULL,
    input_value TEXT NOT NULL,
    allowed INTEGER NOT NULL,
    zone TEXT,
    reason TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS app_settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
SQL;
        $this->pdo->exec($sql);

        $count = (int)$this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($count === 0) {
            $stmt = $this->pdo->prepare('INSERT INTO users(username,password_hash,role,created_at) VALUES (?,?,?,?)');
            $stmt->execute(['admin', password_hash('Tere1234', PASSWORD_DEFAULT), 'admin', date(DATE_ATOM)]);
        }
    }
}
