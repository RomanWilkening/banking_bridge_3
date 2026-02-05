<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;

class DatabaseService
{
    private PDO $pdo;
    private string $dbPath;

    public function __construct(string $dbPath)
    {
        $this->dbPath = $dbPath;
        $this->connect();
        $this->initializeSchema();
    }

    private function connect(): void
    {
        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->pdo = new PDO('sqlite:' . $this->dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    private function initializeSchema(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS banks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                bank_code TEXT NOT NULL,
                fints_url TEXT NOT NULL,
                username TEXT NOT NULL,
                password TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                bank_id INTEGER NOT NULL,
                account_number TEXT NOT NULL,
                iban TEXT,
                bic TEXT,
                account_name TEXT,
                owner_name TEXT,
                currency TEXT DEFAULT 'EUR',
                balance REAL,
                balance_date DATETIME,
                is_active INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (bank_id) REFERENCES banks(id) ON DELETE CASCADE
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS fints_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                bank_id INTEGER NOT NULL,
                session_data TEXT,
                tan_mode TEXT,
                tan_medium TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME,
                FOREIGN KEY (bank_id) REFERENCES banks(id) ON DELETE CASCADE
            )
        ");
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    // Bank Methods
    public function getAllBanks(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM banks ORDER BY name");
        return $stmt->fetchAll();
    }

    public function getBankById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM banks WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function createBank(array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO banks (name, bank_code, fints_url, username, password) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['name'],
            $data['bank_code'],
            $data['fints_url'],
            $data['username'],
            $data['password']
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateBank(int $id, array $data): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE banks 
            SET name = ?, bank_code = ?, fints_url = ?, username = ?, password = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        return $stmt->execute([
            $data['name'],
            $data['bank_code'],
            $data['fints_url'],
            $data['username'],
            $data['password'],
            $id
        ]);
    }

    public function deleteBank(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM banks WHERE id = ?");
        return $stmt->execute([$id]);
    }

    // Account Methods
    public function getAccountsByBankId(int $bankId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM accounts WHERE bank_id = ? ORDER BY account_name");
        $stmt->execute([$bankId]);
        return $stmt->fetchAll();
    }

    public function upsertAccount(int $bankId, array $data): int
    {
        // Check if account exists
        $stmt = $this->pdo->prepare("SELECT id FROM accounts WHERE bank_id = ? AND account_number = ?");
        $stmt->execute([$bankId, $data['account_number']]);
        $existing = $stmt->fetch();

        if ($existing) {
            $stmt = $this->pdo->prepare("
                UPDATE accounts 
                SET iban = ?, bic = ?, account_name = ?, owner_name = ?, 
                    currency = ?, balance = ?, balance_date = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([
                $data['iban'] ?? null,
                $data['bic'] ?? null,
                $data['account_name'] ?? null,
                $data['owner_name'] ?? null,
                $data['currency'] ?? 'EUR',
                $data['balance'] ?? null,
                $data['balance_date'] ?? null,
                $existing['id']
            ]);
            return (int) $existing['id'];
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO accounts (bank_id, account_number, iban, bic, account_name, owner_name, currency, balance, balance_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $bankId,
            $data['account_number'],
            $data['iban'] ?? null,
            $data['bic'] ?? null,
            $data['account_name'] ?? null,
            $data['owner_name'] ?? null,
            $data['currency'] ?? 'EUR',
            $data['balance'] ?? null,
            $data['balance_date'] ?? null
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    // Session Methods
    public function saveFinTSSession(int $bankId, string $sessionData, ?string $tanMode = null, ?string $tanMedium = null): int
    {
        // Delete old sessions for this bank
        $stmt = $this->pdo->prepare("DELETE FROM fints_sessions WHERE bank_id = ?");
        $stmt->execute([$bankId]);

        // Store session for 90 days (PSD2 allows TAN-free access within this window)
        $stmt = $this->pdo->prepare("
            INSERT INTO fints_sessions (bank_id, session_data, tan_mode, tan_medium, expires_at)
            VALUES (?, ?, ?, ?, datetime('now', '+90 days'))
        ");
        $stmt->execute([$bankId, $sessionData, $tanMode, $tanMedium]);
        return (int) $this->pdo->lastInsertId();
    }

    public function getFinTSSession(int $bankId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM fints_sessions 
            WHERE bank_id = ? AND expires_at > datetime('now')
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([$bankId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function deleteFinTSSession(int $bankId): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM fints_sessions WHERE bank_id = ?");
        return $stmt->execute([$bankId]);
    }

    // Settings Methods
    public function getSetting(string $key, ?string $default = null): ?string
    {
        $stmt = $this->pdo->prepare("SELECT value FROM settings WHERE key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        return $result ? $result['value'] : $default;
    }

    public function setSetting(string $key, string $value): bool
    {
        $stmt = $this->pdo->prepare("
            INSERT OR REPLACE INTO settings (key, value, updated_at) 
            VALUES (?, ?, CURRENT_TIMESTAMP)
        ");
        return $stmt->execute([$key, $value]);
    }
}
