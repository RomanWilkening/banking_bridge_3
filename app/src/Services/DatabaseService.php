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
        
        // Index for duplicate detection query (account + date + amount)
        $this->pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_transactions_dedup 
            ON transactions(account_id, booking_date, amount)
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
        
        // Add mqtt_export column for MQTT publishing
        if (!in_array('mqtt_export', $columnNames)) {
            $this->pdo->exec("ALTER TABLE accounts ADD COLUMN mqtt_export INTEGER DEFAULT 0");
        }
        
        // Add linked_depot_id for linking accounts to depots
        if (!in_array('linked_depot_id', $columnNames)) {
            $this->pdo->exec("ALTER TABLE accounts ADD COLUMN linked_depot_id INTEGER REFERENCES accounts(id)");
        }
        
        // Add custom_name for user-defined names
        if (!in_array('custom_name', $columnNames)) {
            $this->pdo->exec("ALTER TABLE accounts ADD COLUMN custom_name TEXT");
        }
        
        // Create PayPal accounts table if it doesn't exist
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS paypal_accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT,
                api_username TEXT NOT NULL,
                api_password TEXT NOT NULL,
                api_signature TEXT NOT NULL,
                balance REAL,
                currency TEXT DEFAULT 'EUR',
                last_sync DATETIME,
                mqtt_export INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Create PayPal transactions table if it doesn't exist
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS paypal_transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                paypal_account_id INTEGER NOT NULL,
                transaction_id TEXT NOT NULL,
                timestamp DATETIME,
                type TEXT,
                email TEXT,
                name TEXT,
                status TEXT,
                amount REAL,
                fee_amount REAL,
                net_amount REAL,
                currency TEXT DEFAULT 'EUR',
                subject TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (paypal_account_id) REFERENCES paypal_accounts(id) ON DELETE CASCADE,
                UNIQUE(paypal_account_id, transaction_id)
            )
        ");
        
        // Index for PayPal transactions
        $this->pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_paypal_transactions_account 
            ON paypal_transactions(paypal_account_id, timestamp DESC)
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

    // PayPal Account Methods
    public function getAllPayPalAccounts(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM paypal_accounts ORDER BY name");
        return $stmt->fetchAll();
    }

    public function getPayPalAccountById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM paypal_accounts WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function createPayPalAccount(array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO paypal_accounts (name, email, api_username, api_password, api_signature, currency) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['name'],
            $data['email'] ?? null,
            $data['api_username'],
            $data['api_password'],
            $data['api_signature'],
            $data['currency'] ?? 'EUR'
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updatePayPalAccount(int $id, array $data): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE paypal_accounts 
            SET name = ?, email = ?, api_username = ?, api_password = ?, api_signature = ?, 
                currency = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        return $stmt->execute([
            $data['name'],
            $data['email'] ?? null,
            $data['api_username'],
            $data['api_password'],
            $data['api_signature'],
            $data['currency'] ?? 'EUR',
            $id
        ]);
    }

    public function deletePayPalAccount(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM paypal_accounts WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function updatePayPalAccountBalance(int $id, float $balance, ?string $lastSync = null): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE paypal_accounts 
            SET balance = ?, last_sync = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        return $stmt->execute([$balance, $lastSync ?? date('Y-m-d H:i:s'), $id]);
    }

    public function setPayPalAccountMqttExport(int $id, bool $enabled): bool
    {
        $stmt = $this->pdo->prepare("UPDATE paypal_accounts SET mqtt_export = ? WHERE id = ?");
        return $stmt->execute([$enabled ? 1 : 0, $id]);
    }

    // PayPal Transaction Methods
    public function savePayPalTransaction(int $paypalAccountId, array $data): int
    {
        // Check if transaction already exists
        $existingStmt = $this->pdo->prepare("
            SELECT id FROM paypal_transactions 
            WHERE paypal_account_id = ? AND transaction_id = ?
            LIMIT 1
        ");
        $existingStmt->execute([$paypalAccountId, $data['transaction_id']]);
        
        if ($existingStmt->fetch()) {
            return 0; // Already exists
        }
        
        $stmt = $this->pdo->prepare("
            INSERT INTO paypal_transactions (
                paypal_account_id, transaction_id, timestamp, type, email, name,
                status, amount, fee_amount, net_amount, currency, subject
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $paypalAccountId,
            $data['transaction_id'],
            $data['timestamp'] ?? null,
            $data['type'] ?? null,
            $data['email'] ?? null,
            $data['name'] ?? null,
            $data['status'] ?? null,
            $data['amount'] ?? 0,
            $data['fee_amount'] ?? 0,
            $data['net_amount'] ?? 0,
            $data['currency'] ?? 'EUR',
            $data['subject'] ?? null
        ]);
        
        return 1;
    }

    public function savePayPalTransactions(int $paypalAccountId, array $transactions): array
    {
        $newCount = 0;
        foreach ($transactions as $tx) {
            $newCount += $this->savePayPalTransaction($paypalAccountId, $tx);
        }
        return ['new' => $newCount, 'total' => count($transactions)];
    }

    public function getPayPalTransactions(int $paypalAccountId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM paypal_transactions 
            WHERE paypal_account_id = ? 
            ORDER BY timestamp DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$paypalAccountId, $limit, $offset]);
        return $stmt->fetchAll();
    }

    public function getPayPalTransactionCount(int $paypalAccountId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM paypal_transactions WHERE paypal_account_id = ?");
        $stmt->execute([$paypalAccountId]);
        $result = $stmt->fetch();
        return (int) ($result['count'] ?? 0);
    }

    public function getMqttEnabledPayPalAccounts(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM paypal_accounts WHERE mqtt_export = 1");
        return $stmt->fetchAll();
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

        // Base64-encode the session data to safely store binary data
        // phpFinTS persist() returns serialized data that may contain binary content
        $encodedSessionData = base64_encode($sessionData);

        // Store session for 90 days (PSD2 allows TAN-free access within this window)
        $stmt = $this->pdo->prepare("
            INSERT INTO fints_sessions (bank_id, session_data, tan_mode, tan_medium, expires_at)
            VALUES (?, ?, ?, ?, datetime('now', '+90 days'))
        ");
        $stmt->execute([$bankId, $encodedSessionData, $tanMode, $tanMedium]);
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
        
        if ($result && !empty($result['session_data'])) {
            // Decode the base64-encoded session data
            $decoded = base64_decode($result['session_data'], true);
            if ($decoded !== false) {
                $result['session_data'] = $decoded;
            }
            // If decode fails, the data might be in old format (not base64) - keep as is
        }
        
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
        
        // Fallback: Create hash from normalized transaction details
        // Normalize name: lowercase, trim, remove multiple spaces
        $name = $data['name'] ?? '';
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/\s+/', ' ', $name); // Multiple spaces -> single space
        $name = substr($name, 0, 50); // Truncate to avoid minor variations at end
        
        // Normalize date to Y-m-d format
        $date = $data['booking_date'] ?? '';
        if ($date && strtotime($date)) {
            $date = date('Y-m-d', strtotime($date));
        }
        
        // Amount with fixed precision
        $amount = number_format((float)($data['amount'] ?? 0), 2, '.', '');
        
        return md5($accountId . ':' . $date . ':' . $amount . ':' . $name);
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
     * Returns 1 if new, 0 if skipped (duplicate)
     */
    public function saveTransaction(int $accountId, array $data): int
    {
        // Normalize the data for consistent comparison
        $bookingDate = $data['booking_date'] ?? null;
        if ($bookingDate && strtotime($bookingDate)) {
            $bookingDate = date('Y-m-d', strtotime($bookingDate));
        }
        
        $amount = round((float)($data['amount'] ?? 0), 2);
        $name = trim($data['name'] ?? '');
        $nameLower = mb_strtolower($name);
        
        // First: Check if an identical transaction already exists (data-based check)
        // Uses idx_transactions_dedup index for fast lookup on (account_id, booking_date, amount)
        // Then filters by name in PHP for exact match (avoids LOWER() on DB side which can't use index)
        $existingStmt = $this->pdo->prepare("
            SELECT id, name FROM transactions 
            WHERE account_id = ? 
              AND booking_date = ? 
              AND amount = ?
        ");
        $existingStmt->execute([$accountId, $bookingDate, $amount]);
        
        // Check name match in PHP (faster than DB-side LOWER for small result sets)
        while ($row = $existingStmt->fetch()) {
            $existingName = mb_strtolower(trim($row['name'] ?? ''));
            if ($existingName === $nameLower) {
                // Transaction already exists - skip
                return 0;
            }
        }
        // No duplicate found - not an exact match
        
        // Generate a unique transaction ID for new transactions
        $transactionId = $this->generateTransactionId($accountId, $data);

        // Insert new transaction (use INSERT OR IGNORE as extra safety)
        $stmt = $this->pdo->prepare("
            INSERT OR IGNORE INTO transactions (
                account_id, transaction_id, booking_date, valuta_date, amount, currency,
                name, description, iban, bic, mandate_id, creditor_id, 
                end_to_end_id, booking_text, prima_nota
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $accountId,
            $transactionId,
            $bookingDate,
            $data['valuta_date'] ?? null,
            $amount,
            $data['currency'] ?? 'EUR',
            $name,
            $data['description'] ?? null,
            $data['iban'] ?? null,
            $data['bic'] ?? null,
            $data['mandate_id'] ?? null,
            $data['creditor_id'] ?? null,
            $data['end_to_end_id'] ?? null,
            $data['booking_text'] ?? null,
            $data['prima_nota'] ?? null
        ]);
        
        // Return 1 if a row was actually inserted
        return $stmt->rowCount() > 0 ? 1 : 0;
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

    /**
     * Find duplicate transactions across all accounts or for a specific account
     * Duplicates are identified by: same account, date, amount, name, and description
     */
    public function findDuplicateTransactions(?int $accountId = null): array
    {
        $sql = "
            SELECT 
                t1.id,
                t1.account_id,
                t1.booking_date,
                t1.amount,
                t1.name,
                t1.description,
                t1.transaction_id,
                t1.created_at,
                a.account_name,
                a.iban,
                b.name as bank_name
            FROM transactions t1
            INNER JOIN (
                SELECT 
                    account_id, 
                    booking_date, 
                    amount, 
                    COALESCE(name, '') as name, 
                    COALESCE(description, '') as description,
                    COUNT(*) as cnt,
                    MIN(id) as keep_id
                FROM transactions
                " . ($accountId ? "WHERE account_id = ?" : "") . "
                GROUP BY account_id, booking_date, amount, COALESCE(name, ''), COALESCE(description, '')
                HAVING COUNT(*) > 1
            ) t2 ON t1.account_id = t2.account_id 
                AND t1.booking_date = t2.booking_date 
                AND t1.amount = t2.amount 
                AND COALESCE(t1.name, '') = t2.name 
                AND COALESCE(t1.description, '') = t2.description
            LEFT JOIN accounts a ON t1.account_id = a.id
            LEFT JOIN banks b ON a.bank_id = b.id
            ORDER BY t1.account_id, t1.booking_date DESC, t1.amount, t1.id
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($accountId ? [$accountId] : []);
        return $stmt->fetchAll();
    }

    /**
     * Get summary of duplicate transactions
     */
    public function getDuplicateSummary(?int $accountId = null): array
    {
        $sql = "
            SELECT 
                account_id,
                booking_date,
                amount,
                COALESCE(name, '') as name,
                COALESCE(description, '') as description,
                COUNT(*) as duplicate_count,
                MIN(id) as keep_id
            FROM transactions
            " . ($accountId ? "WHERE account_id = ?" : "") . "
            GROUP BY account_id, booking_date, amount, COALESCE(name, ''), COALESCE(description, '')
            HAVING COUNT(*) > 1
            ORDER BY duplicate_count DESC, booking_date DESC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($accountId ? [$accountId] : []);
        $results = $stmt->fetchAll();
        
        $totalDuplicates = 0;
        $totalToRemove = 0;
        foreach ($results as $row) {
            $totalDuplicates += $row['duplicate_count'];
            $totalToRemove += $row['duplicate_count'] - 1; // Keep one of each
        }
        
        return [
            'groups' => $results,
            'total_duplicate_groups' => count($results),
            'total_duplicate_transactions' => $totalDuplicates,
            'total_to_remove' => $totalToRemove
        ];
    }

    /**
     * Remove duplicate transactions, keeping the oldest entry (lowest ID) of each duplicate group
     * Returns the number of removed transactions
     */
    public function removeDuplicateTransactions(?int $accountId = null): int
    {
        // First, find all IDs to delete (all duplicates except the one with lowest ID in each group)
        $sql = "
            DELETE FROM transactions
            WHERE id IN (
                SELECT t1.id
                FROM transactions t1
                INNER JOIN (
                    SELECT 
                        account_id, 
                        booking_date, 
                        amount, 
                        COALESCE(name, '') as name, 
                        COALESCE(description, '') as description,
                        MIN(id) as keep_id
                    FROM transactions
                    " . ($accountId ? "WHERE account_id = ?" : "") . "
                    GROUP BY account_id, booking_date, amount, COALESCE(name, ''), COALESCE(description, '')
                    HAVING COUNT(*) > 1
                ) t2 ON t1.account_id = t2.account_id 
                    AND t1.booking_date = t2.booking_date 
                    AND t1.amount = t2.amount 
                    AND COALESCE(t1.name, '') = t2.name 
                    AND COALESCE(t1.description, '') = t2.description
                    AND t1.id != t2.keep_id
            )
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($accountId ? [$accountId] : []);
        return $stmt->rowCount();
    }

    /**
     * Regenerate transaction IDs for all transactions
     * Useful after changing the hash algorithm
     */
    public function regenerateTransactionIds(?int $accountId = null): int
    {
        $sql = "SELECT id, account_id, booking_date, amount, name, description, end_to_end_id, prima_nota 
                FROM transactions" . ($accountId ? " WHERE account_id = ?" : "");
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($accountId ? [$accountId] : []);
        $transactions = $stmt->fetchAll();
        
        $updateStmt = $this->pdo->prepare("UPDATE transactions SET transaction_id = ? WHERE id = ?");
        $count = 0;
        
        foreach ($transactions as $tx) {
            $newId = $this->generateTransactionId($tx['account_id'], [
                'booking_date' => $tx['booking_date'],
                'amount' => $tx['amount'],
                'name' => $tx['name'],
                'description' => $tx['description'],
                'end_to_end_id' => $tx['end_to_end_id'],
                'prima_nota' => $tx['prima_nota']
            ]);
            $updateStmt->execute([$newId, $tx['id']]);
            $count++;
        }
        
        return $count;
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
    
    // MQTT Methods
    
    /**
     * Get all accounts that have MQTT export enabled
     * Includes bank information for context
     */
    public function getMqttEnabledAccounts(): array
    {
        $stmt = $this->pdo->query("
            SELECT a.*, b.name as bank_name, b.bank_code
            FROM accounts a
            JOIN banks b ON a.bank_id = b.id
            WHERE a.mqtt_export = 1
            ORDER BY b.name, a.account_name
        ");
        return $stmt->fetchAll();
    }
    
    /**
     * Set MQTT export flag for an account
     */
    public function setAccountMqttExport(int $accountId, bool $enabled): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE accounts SET mqtt_export = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        return $stmt->execute([$enabled ? 1 : 0, $accountId]);
    }
    
    /**
     * Get account with bank information
     */
    public function getAccountWithBank(int $accountId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT a.*, b.name as bank_name, b.bank_code
            FROM accounts a
            JOIN banks b ON a.bank_id = b.id
            WHERE a.id = ?
        ");
        $stmt->execute([$accountId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }
    
    // Rename Methods
    
    /**
     * Rename a bank
     */
    public function renameBank(int $bankId, string $newName): bool
    {
        $stmt = $this->pdo->prepare("UPDATE banks SET name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        return $stmt->execute([trim($newName), $bankId]);
    }
    
    /**
     * Rename an account (sets custom_name)
     */
    public function renameAccount(int $accountId, ?string $customName): bool
    {
        $stmt = $this->pdo->prepare("UPDATE accounts SET custom_name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        return $stmt->execute([$customName ? trim($customName) : null, $accountId]);
    }
    
    /**
     * Get display name for account (custom_name or account_name)
     */
    public function getAccountDisplayName(array $account): string
    {
        return $account['custom_name'] ?? $account['account_name'] ?? 'Konto';
    }
    
    // Depot Linking Methods
    
    /**
     * Link an account to a depot
     */
    public function linkAccountToDepot(int $accountId, ?int $depotId): bool
    {
        $stmt = $this->pdo->prepare("UPDATE accounts SET linked_depot_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        return $stmt->execute([$depotId, $accountId]);
    }
    
    /**
     * Get accounts linked to a depot
     */
    public function getLinkedAccounts(int $depotId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT a.*, b.name as bank_name
            FROM accounts a
            JOIN banks b ON a.bank_id = b.id
            WHERE a.linked_depot_id = ?
            ORDER BY COALESCE(a.custom_name, a.account_name)
        ");
        $stmt->execute([$depotId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get all depots for linking dropdown (excluding the account itself)
     */
    public function getDepotsForLinking(int $excludeAccountId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT a.id, COALESCE(a.custom_name, a.account_name) as name, 
                   a.account_number, a.sub_account, b.name as bank_name
            FROM accounts a
            JOIN banks b ON a.bank_id = b.id
            WHERE a.account_type = 'depot' AND a.id != ?
            ORDER BY b.name, COALESCE(a.custom_name, a.account_name)
        ");
        $stmt->execute([$excludeAccountId]);
        return $stmt->fetchAll();
    }
    
    // Depot API Methods
    
    /**
     * Get all depot accounts with bank information
     */
    public function getAllDepots(): array
    {
        $stmt = $this->pdo->query("
            SELECT a.*, b.name as bank_name, b.bank_code,
                   COALESCE(a.custom_name, a.account_name) as display_name
            FROM accounts a
            JOIN banks b ON a.bank_id = b.id
            WHERE a.account_type = 'depot'
            ORDER BY b.name, COALESCE(a.custom_name, a.account_name)
        ");
        return $stmt->fetchAll();
    }
    
    /**
     * Get depot total value including linked accounts
     */
    public function getDepotTotalValueWithLinked(int $depotId): array
    {
        // Get securities value
        $securitiesValue = $this->getDepotTotalValue($depotId) ?? 0;
        
        // Get linked accounts value
        $linkedAccounts = $this->getLinkedAccounts($depotId);
        $linkedValue = 0;
        foreach ($linkedAccounts as $account) {
            if ($account['balance'] !== null) {
                $linkedValue += (float) $account['balance'];
            }
        }
        
        return [
            'securities_value' => $securitiesValue,
            'linked_accounts_value' => $linkedValue,
            'total_value' => $securitiesValue + $linkedValue,
            'linked_accounts_count' => count($linkedAccounts)
        ];
    }
    
    /**
     * Get all securities holdings across all depots
     */
    public function getAllSecuritiesHoldings(): array
    {
        $stmt = $this->pdo->query("
            SELECT h.*, a.account_name as depot_name, a.account_number as depot_number,
                   b.name as bank_name, a.id as depot_id
            FROM securities_holdings h
            JOIN accounts a ON h.account_id = a.id
            JOIN banks b ON a.bank_id = b.id
            ORDER BY b.name, a.account_name, h.name
        ");
        return $stmt->fetchAll();
    }
    
    /**
     * Get securities holdings with depot and bank info
     */
    public function getSecuritiesHoldingsWithContext(int $accountId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT h.*, a.account_name as depot_name, a.account_number as depot_number,
                   b.name as bank_name
            FROM securities_holdings h
            JOIN accounts a ON h.account_id = a.id
            JOIN banks b ON a.bank_id = b.id
            WHERE h.account_id = ?
            ORDER BY h.total_value DESC
        ");
        $stmt->execute([$accountId]);
        return $stmt->fetchAll();
    }
}
