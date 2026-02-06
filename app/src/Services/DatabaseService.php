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
                account_type TEXT DEFAULT 'checking',
                sub_account TEXT,
                currency TEXT DEFAULT 'EUR',
                balance REAL,
                balance_date DATETIME,
                is_active INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (bank_id) REFERENCES banks(id) ON DELETE CASCADE
            )
        ");
        
        // Securities holdings for depot accounts
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS securities_holdings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL,
                isin TEXT,
                wkn TEXT,
                name TEXT NOT NULL,
                quantity REAL NOT NULL,
                currency TEXT DEFAULT 'EUR',
                current_price REAL,
                purchase_price REAL,
                total_value REAL,
                profit_loss REAL,
                profit_loss_percent REAL,
                price_date DATETIME,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
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

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL,
                transaction_id TEXT,
                booking_date DATE,
                valuta_date DATE,
                amount REAL NOT NULL,
                currency TEXT DEFAULT 'EUR',
                name TEXT,
                description TEXT,
                iban TEXT,
                bic TEXT,
                mandate_id TEXT,
                creditor_id TEXT,
                end_to_end_id TEXT,
                booking_text TEXT,
                prima_nota TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
                UNIQUE(account_id, transaction_id)
            )
        ");

        $this->pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_transactions_account_date 
            ON transactions(account_id, booking_date DESC)
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS bank_capabilities (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                bank_id INTEGER NOT NULL UNIQUE,
                bank_name_from_bpd TEXT,
                bpd_version INTEGER,
                supports_psd2 INTEGER DEFAULT 0,
                mt940_versions TEXT,
                camt_versions TEXT,
                balance_versions TEXT,
                mt940_supported INTEGER DEFAULT 0,
                camt_supported INTEGER DEFAULT 0,
                transactions_supported INTEGER DEFAULT 0,
                supports_balance INTEGER DEFAULT 0,
                supports_sepa_accounts INTEGER DEFAULT 0,
                tan_modes TEXT,
                all_parameters TEXT,
                read_capabilities TEXT,
                transfer_capabilities TEXT,
                direct_debit_capabilities TEXT,
                last_updated DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (bank_id) REFERENCES banks(id) ON DELETE CASCADE
            )
        ");
        
        // Activity log for tracking sync operations
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS activity_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                bank_id INTEGER,
                account_id INTEGER,
                action TEXT NOT NULL,
                status TEXT NOT NULL,
                message TEXT,
                details TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (bank_id) REFERENCES banks(id) ON DELETE CASCADE,
                FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
            )
        ");
        
        $this->pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_activity_log_bank 
            ON activity_log(bank_id, created_at DESC)
        ");
        
        // Run migrations for existing databases
        $this->runMigrations();
    }
    
    /**
     * Run migrations for existing databases
     */
    private function runMigrations(): void
    {
        // Add account_type column if it doesn't exist
        $columns = $this->pdo->query("PRAGMA table_info(accounts)")->fetchAll();
        $columnNames = array_column($columns, 'name');
        
        if (!in_array('account_type', $columnNames)) {
            $this->pdo->exec("ALTER TABLE accounts ADD COLUMN account_type TEXT DEFAULT 'checking'");
        }
        
        if (!in_array('sub_account', $columnNames)) {
            $this->pdo->exec("ALTER TABLE accounts ADD COLUMN sub_account TEXT");
        }
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
        // Check if account exists (consider sub_account for uniqueness)
        $subAccount = $data['sub_account'] ?? null;
        if ($subAccount) {
            $stmt = $this->pdo->prepare("SELECT id FROM accounts WHERE bank_id = ? AND account_number = ? AND sub_account = ?");
            $stmt->execute([$bankId, $data['account_number'], $subAccount]);
        } else {
            $stmt = $this->pdo->prepare("SELECT id FROM accounts WHERE bank_id = ? AND account_number = ? AND (sub_account IS NULL OR sub_account = '')");
            $stmt->execute([$bankId, $data['account_number']]);
        }
        $existing = $stmt->fetch();

        if ($existing) {
            $stmt = $this->pdo->prepare("
                UPDATE accounts 
                SET iban = ?, bic = ?, account_name = ?, owner_name = ?, account_type = ?, sub_account = ?,
                    currency = ?, balance = ?, balance_date = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([
                $data['iban'] ?? null,
                $data['bic'] ?? null,
                $data['account_name'] ?? null,
                $data['owner_name'] ?? null,
                $data['account_type'] ?? 'checking',
                $data['sub_account'] ?? null,
                $data['currency'] ?? 'EUR',
                $data['balance'] ?? null,
                $data['balance_date'] ?? null,
                $existing['id']
            ]);
            return (int) $existing['id'];
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO accounts (bank_id, account_number, iban, bic, account_name, owner_name, account_type, sub_account, currency, balance, balance_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $bankId,
            $data['account_number'],
            $data['iban'] ?? null,
            $data['bic'] ?? null,
            $data['account_name'] ?? null,
            $data['owner_name'] ?? null,
            $data['account_type'] ?? 'checking',
            $data['sub_account'] ?? null,
            $data['currency'] ?? 'EUR',
            $data['balance'] ?? null,
            $data['balance_date'] ?? null
        ]);
        return (int) $this->pdo->lastInsertId();
    }
    
    /**
     * Get depot accounts for a bank
     */
    public function getDepotsByBankId(int $bankId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM accounts WHERE bank_id = ? AND account_type = 'depot' ORDER BY account_name");
        $stmt->execute([$bankId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get regular (non-depot) accounts for a bank
     */
    public function getRegularAccountsByBankId(int $bankId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM accounts WHERE bank_id = ? AND (account_type IS NULL OR account_type != 'depot') ORDER BY account_name");
        $stmt->execute([$bankId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Save securities holdings for a depot
     */
    public function saveSecuritiesHoldings(int $accountId, array $holdings): int
    {
        // Delete existing holdings
        $stmt = $this->pdo->prepare("DELETE FROM securities_holdings WHERE account_id = ?");
        $stmt->execute([$accountId]);
        
        $count = 0;
        foreach ($holdings as $holding) {
            $stmt = $this->pdo->prepare("
                INSERT INTO securities_holdings 
                (account_id, isin, wkn, name, quantity, currency, current_price, purchase_price, 
                 total_value, profit_loss, profit_loss_percent, price_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $accountId,
                $holding['isin'] ?? null,
                $holding['wkn'] ?? null,
                $holding['name'],
                $holding['quantity'],
                $holding['currency'] ?? 'EUR',
                $holding['current_price'] ?? null,
                $holding['purchase_price'] ?? null,
                $holding['total_value'] ?? null,
                $holding['profit_loss'] ?? null,
                $holding['profit_loss_percent'] ?? null,
                $holding['price_date'] ?? null
            ]);
            $count++;
        }
        
        return $count;
    }
    
    /**
     * Get securities holdings for an account
     */
    public function getSecuritiesHoldings(int $accountId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM securities_holdings 
            WHERE account_id = ? 
            ORDER BY total_value DESC
        ");
        $stmt->execute([$accountId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get total depot value
     */
    public function getDepotTotalValue(int $accountId): ?float
    {
        $stmt = $this->pdo->prepare("SELECT SUM(total_value) as total FROM securities_holdings WHERE account_id = ?");
        $stmt->execute([$accountId]);
        $result = $stmt->fetch();
        return $result ? (float) $result['total'] : null;
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

    // Transaction Methods
    public function getAccountById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM accounts WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Generate a unique transaction ID for deduplication
     * Uses stable fields that don't change between syncs
     */
    private function generateTransactionId(int $accountId, array $data): string
    {
        // Priority: Use bank-provided unique identifiers if available
        if (!empty($data['end_to_end_id']) && $data['end_to_end_id'] !== 'NOTPROVIDED') {
            return md5($accountId . ':e2e:' . $data['end_to_end_id']);
        }
        
        if (!empty($data['prima_nota'])) {
            return md5($accountId . ':pn:' . $data['prima_nota'] . ':' . ($data['booking_date'] ?? ''));
        }
        
        // Fallback: Create hash from transaction details
        // Use only stable fields (amount, date, truncated name)
        $name = substr($data['name'] ?? '', 0, 50); // Truncate name to avoid minor variations
        return md5(
            $accountId . ':' .
            ($data['booking_date'] ?? '') . ':' .
            number_format((float)($data['amount'] ?? 0), 2, '.', '') . ':' .
            $name
        );
    }

    /**
     * Check if a transaction already exists
     */
    public function transactionExists(int $accountId, string $transactionId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM transactions WHERE account_id = ? AND transaction_id = ? LIMIT 1");
        $stmt->execute([$accountId, $transactionId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Save a single transaction (insert or update)
     * Returns 1 if new, 0 if updated existing
     */
    public function saveTransaction(int $accountId, array $data): int
    {
        // Generate a unique transaction ID
        $transactionId = $data['transaction_id'] ?? $this->generateTransactionId($accountId, $data);
        
        // Check if this is a new transaction
        $isNew = !$this->transactionExists($accountId, $transactionId);

        $stmt = $this->pdo->prepare("
            INSERT OR REPLACE INTO transactions (
                account_id, transaction_id, booking_date, valuta_date, amount, currency,
                name, description, iban, bic, mandate_id, creditor_id, 
                end_to_end_id, booking_text, prima_nota
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $accountId,
            $transactionId,
            $data['booking_date'] ?? null,
            $data['valuta_date'] ?? null,
            $data['amount'] ?? 0,
            $data['currency'] ?? 'EUR',
            $data['name'] ?? null,
            $data['description'] ?? null,
            $data['iban'] ?? null,
            $data['bic'] ?? null,
            $data['mandate_id'] ?? null,
            $data['creditor_id'] ?? null,
            $data['end_to_end_id'] ?? null,
            $data['booking_text'] ?? null,
            $data['prima_nota'] ?? null
        ]);
        
        return $isNew ? 1 : 0;
    }

    /**
     * Save multiple transactions
     * Returns array with counts: ['new' => X, 'updated' => Y, 'total' => Z]
     */
    public function saveTransactions(int $accountId, array $transactions): array
    {
        $newCount = 0;
        $updatedCount = 0;
        
        foreach ($transactions as $transaction) {
            $result = $this->saveTransaction($accountId, $transaction);
            if ($result === 1) {
                $newCount++;
            } else {
                $updatedCount++;
            }
        }
        
        return [
            'new' => $newCount,
            'updated' => $updatedCount,
            'total' => $newCount + $updatedCount
        ];
    }

    public function getTransactionsByAccountId(int $accountId, int $limit = 30, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM transactions 
            WHERE account_id = ? 
            ORDER BY booking_date DESC, id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$accountId, $limit, $offset]);
        return $stmt->fetchAll();
    }

    public function getTransactionCount(int $accountId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM transactions WHERE account_id = ?");
        $stmt->execute([$accountId]);
        $result = $stmt->fetch();
        return (int) ($result['count'] ?? 0);
    }

    public function getLatestTransactionDate(int $accountId): ?string
    {
        $stmt = $this->pdo->prepare("
            SELECT MAX(booking_date) as latest FROM transactions WHERE account_id = ?
        ");
        $stmt->execute([$accountId]);
        $result = $stmt->fetch();
        return $result['latest'] ?? null;
    }

    public function updateAccountBalance(int $accountId, float $balance, ?string $balanceDate = null): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE accounts 
            SET balance = ?, balance_date = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        return $stmt->execute([$balance, $balanceDate ?? date('Y-m-d H:i:s'), $accountId]);
    }

    // Bank Capabilities Methods
    public function saveBankCapabilities(int $bankId, array $capabilities): bool
    {
        $stmt = $this->pdo->prepare("
            INSERT OR REPLACE INTO bank_capabilities (
                bank_id, bank_name_from_bpd, bpd_version, supports_psd2,
                mt940_versions, camt_versions, balance_versions,
                mt940_supported, camt_supported, transactions_supported,
                supports_balance, supports_sepa_accounts,
                tan_modes, all_parameters,
                read_capabilities, transfer_capabilities, direct_debit_capabilities,
                last_updated
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        
        return $stmt->execute([
            $bankId,
            $capabilities['bank_name'] ?? null,
            $capabilities['bpd_version'] ?? null,
            ($capabilities['supports_psd2'] ?? false) ? 1 : 0,
            json_encode($capabilities['mt940_versions'] ?? []),
            json_encode($capabilities['camt_versions'] ?? []),
            json_encode($capabilities['balance_versions'] ?? []),
            ($capabilities['mt940_supported'] ?? false) ? 1 : 0,
            ($capabilities['camt_supported'] ?? false) ? 1 : 0,
            ($capabilities['transactions_supported'] ?? false) ? 1 : 0,
            ($capabilities['supports_balance'] ?? false) ? 1 : 0,
            ($capabilities['supports_sepa_accounts'] ?? false) ? 1 : 0,
            json_encode($capabilities['tan_modes'] ?? []),
            json_encode($capabilities['all_parameters'] ?? []),
            json_encode($capabilities['read'] ?? []),
            json_encode($capabilities['transfers'] ?? []),
            json_encode($capabilities['direct_debits'] ?? []),
        ]);
    }

    public function getBankCapabilities(int $bankId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM bank_capabilities WHERE bank_id = ?");
        $stmt->execute([$bankId]);
        $result = $stmt->fetch();
        
        if (!$result) {
            return null;
        }

        // Decode JSON fields
        $result['mt940_versions'] = json_decode($result['mt940_versions'] ?? '[]', true) ?: [];
        $result['camt_versions'] = json_decode($result['camt_versions'] ?? '[]', true) ?: [];
        $result['balance_versions'] = json_decode($result['balance_versions'] ?? '[]', true) ?: [];
        $result['tan_modes'] = json_decode($result['tan_modes'] ?? '[]', true) ?: [];
        $result['all_parameters'] = json_decode($result['all_parameters'] ?? '[]', true) ?: [];
        $result['read'] = json_decode($result['read_capabilities'] ?? '[]', true) ?: [];
        $result['transfers'] = json_decode($result['transfer_capabilities'] ?? '[]', true) ?: [];
        $result['direct_debits'] = json_decode($result['direct_debit_capabilities'] ?? '[]', true) ?: [];
        
        // Convert integers back to booleans
        $result['supports_psd2'] = (bool) $result['supports_psd2'];
        $result['mt940_supported'] = (bool) $result['mt940_supported'];
        $result['camt_supported'] = (bool) $result['camt_supported'];
        $result['transactions_supported'] = (bool) $result['transactions_supported'];
        $result['supports_balance'] = (bool) $result['supports_balance'];
        $result['supports_sepa_accounts'] = (bool) $result['supports_sepa_accounts'];

        return $result;
    }

    public function deleteBankCapabilities(int $bankId): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM bank_capabilities WHERE bank_id = ?");
        return $stmt->execute([$bankId]);
    }
    
    // Activity Log Methods
    
    /**
     * Log an activity
     */
    public function logActivity(
        string $action, 
        string $status, 
        ?string $message = null, 
        ?int $bankId = null, 
        ?int $accountId = null,
        ?array $details = null
    ): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO activity_log (bank_id, account_id, action, status, message, details)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $bankId,
            $accountId,
            $action,
            $status,
            $message,
            $details ? json_encode($details) : null
        ]);
        
        // Keep only last 500 entries per bank to avoid bloat
        if ($bankId) {
            $this->pdo->exec("
                DELETE FROM activity_log 
                WHERE bank_id = {$bankId} 
                AND id NOT IN (
                    SELECT id FROM activity_log 
                    WHERE bank_id = {$bankId} 
                    ORDER BY created_at DESC 
                    LIMIT 500
                )
            ");
        }
        
        return (int) $this->pdo->lastInsertId();
    }
    
    /**
     * Get activity log for a bank
     */
    public function getActivityLog(int $bankId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare("
            SELECT al.*, a.account_name, a.iban
            FROM activity_log al
            LEFT JOIN accounts a ON al.account_id = a.id
            WHERE al.bank_id = ?
            ORDER BY al.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$bankId, $limit]);
        $results = $stmt->fetchAll();
        
        // Decode JSON details
        foreach ($results as &$row) {
            if ($row['details']) {
                $row['details'] = json_decode($row['details'], true);
            }
        }
        
        return $results;
    }
    
    /**
     * Clear activity log for a bank
     */
    public function clearActivityLog(int $bankId): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM activity_log WHERE bank_id = ?");
        return $stmt->execute([$bankId]);
    }
}
