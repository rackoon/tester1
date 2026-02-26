<?php

class Auth
{
    public function __construct(private PDO $pdo)
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function login(string $username, string $password): bool
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        $_SESSION['user'] = [
            'id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
        ];

        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public function requireRole(array $roles): void
    {
        $user = $this->user();
        if (!$user || !in_array($user['role'], $roles, true)) {
            http_response_code(403);
            echo 'Ligipääs puudub';
            exit;
        }
    }
}
