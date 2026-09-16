<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Services\AuthorizationService;
use App\Services\DatabaseService;
use App\Services\FinTSService;
use Fhp\Action\GetBalance;
use Fhp\Action\GetDepotAufstellung;
use Fhp\Action\GetSEPAAccounts;
use Fhp\Action\GetStatementOfAccount;
use Fhp\BaseAction;
use Fhp\FinTs;
use Fhp\Model\NoPsd2TanMode;
use Fhp\Model\SEPAAccount;
use Fhp\Model\TanMode;
use Fhp\Model\TanRequest;
use Fhp\Options\Credentials;
use Fhp\Options\FinTsOptions;
use Fhp\Protocol\BPD;
use Fhp\Protocol\DialogInitialization;
use Monolog\Logger;

function verify(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException($description);
    }
}

function property(object $object, string $class, string $name, mixed $value): void
{
    (new ReflectionProperty($class, $name))->setValue($object, $value);
}

final class OfflineChallenge implements TanRequest
{
    public function __construct(private string $id) {}
    public function getProcessId(): string { return $this->id; }
    public function getChallenge(): ?string { return 'Offline fixture'; }
    public function getTanMediumName(): ?string { return null; }
    public function getChallengeHhdUc(): ?\Fhp\Syntax\Bin { return null; }
}

final class OfflineSaldo
{
    public function getAmount(): float { return 123.45; }
    public function getCurrency(): string { return 'EUR'; }
    public function getTimestamp(): ?DateTime { return null; }
}

final class OfflineBalance
{
    public function getGebuchterSaldo(): OfflineSaldo { return new OfflineSaldo(); }
}

final class OfflineDecoupledMode extends \Fhp\Segment\TAN\VerfahrensparameterZweiSchrittVerfahrenV7
{
    public function getName(): string { return 'Offline decoupled'; }
    public function needsTanMedium(): bool { return false; }
    public function isDecoupled(): bool { return true; }
    public function getMaxDecoupledChecks(): int { return 1; }
    public function getFirstDecoupledCheckDelaySeconds(): int { return 9; }
    public function getPeriodicDecoupledCheckDelaySeconds(): int { return 7; }
    public function allowsAutomatedPolling(): bool { return true; }
}

final class OfflineFinTs extends FinTs
{
    public static array $events = [];
    public static array $accounts = [];
    public static array $challengeTypes = [];
    public static ?string $throwOn = null;
    public static bool $decoupled = false;
    public static bool $pollComplete = true;
    public static bool $camtOnly = false;
    public function __construct() {}
    public function __destruct() {}
    public function getTanModes(): array { return [new NoPsd2TanMode()]; }
    public function selectTanMode($tanMode, $tanMedium = null) {}
    public function getSelectedTanMode(): ?TanMode { return self::$decoupled ? new OfflineDecoupledMode() : new NoPsd2TanMode(); }
    public function getBpd(): BPD
    {
        $bpd = new BPD();
        $bpd->parameters = self::$camtOnly ? ['HICAZS' => [1 => null]] : ['HIKAZS' => [7 => null]];
        return $bpd;
    }
    public function persist(bool $minimal = false): string { return 'offline-session'; }
    public function close() { self::$events[] = 'close'; }
    public function login(): DialogInitialization
    {
        $action = new DialogInitialization(new FinTsOptions(), Credentials::create('fixture', 'fixture'), null, null, 'offline', null);
        $this->execute($action);
        return $action;
    }
    public function execute(BaseAction $action)
    {
        $type = (new ReflectionClass($action))->getShortName();
        self::$events[] = $type;
        if (self::$throwOn === $type) {
            throw new RuntimeException('Dialog expired fixture');
        }
        if (in_array($type, self::$challengeTypes, true)) {
            property($action, BaseAction::class, 'tanRequest', new OfflineChallenge($type));
        } else {
            $this->complete($action);
        }
    }
    public function submitTan(BaseAction $action, string $tan)
    {
        self::$events[] = 'submit:' . (new ReflectionClass($action))->getShortName();
        $this->complete($action);
    }
    public function checkDecoupledSubmission(BaseAction $action): bool
    {
        self::$events[] = 'poll';
        if (!self::$pollComplete) {
            return false;
        }
        $this->complete($action);
        return true;
    }
    private function complete(BaseAction $action): void
    {
        property($action, BaseAction::class, 'tanRequest', null);
        property($action, BaseAction::class, 'isDone', true);
        if ($action instanceof GetSEPAAccounts) {
            property($action, GetSEPAAccounts::class, 'accounts', self::$accounts);
        } elseif ($action instanceof GetBalance) {
            property($action, GetBalance::class, 'response', [new OfflineBalance()]);
        } elseif ($action instanceof GetStatementOfAccount) {
            property($action, GetStatementOfAccount::class, 'statement', new \Fhp\Model\StatementOfAccount\StatementOfAccount());
        } elseif ($action instanceof GetDepotAufstellung) {
            property($action, GetDepotAufstellung::class, 'statement', new \Fhp\Model\StatementOfHoldings\StatementOfHoldings());
            property($action, GetDepotAufstellung::class, 'depotWert', 0.0);
        }
    }
}

final class OfflineService extends FinTSService
{
    protected function createClient(FinTsOptions $options, Credentials $credentials, ?string $persisted): FinTs
    {
        return new OfflineFinTs();
    }
}

$directory = __DIR__ . '/.fints-authorization-' . getmypid();
mkdir($directory, 0700);
try {
    $db = new DatabaseService($directory . '/banking.db');
    $bank = $db->createBank(['name' => 'Offline', 'bank_code' => '00000000', 'fints_url' => 'https://invalid.invalid',
        'username' => 'fixture', 'password' => 'fixture']);
    $checking = $db->upsertAccount($bank, ['account_number' => '1234', 'iban' => 'DE00000000000000000000',
        'account_name' => 'Personal name', 'account_type' => 'checking']);
    $depot = $db->upsertAccount($bank, ['account_number' => '5678', 'account_name' => 'ZZ Depot', 'account_type' => 'depot']);
    $db->setAccountBackgroundSync($checking, false);
    $db->setAccountTanManualApproval($checking, true);
    $db->saveSecuritiesHoldings($depot, [['name' => 'Old holding', 'quantity' => 1, 'total_value' => 10, 'currency' => 'EUR']]);
    foreach ([['1234', 'DE00000000000000000000'], ['5678', null]] as [$number, $iban]) {
        $account = new SEPAAccount();
        $account->setAccountNumber($number);
        $account->setIban($iban);
        $account->setBic('FIXTURE');
        OfflineFinTs::$accounts[] = $account;
    }
    $logger = new Logger('offline-test');
    $service = new OfflineService($logger);
    $service->setProductId('offline-fixture');
    $auth = new AuthorizationService($db, $service);

    // Every legacy/public network entry point fails before even client construction.
    $config = $db->getBankById($bank);
    foreach ([
        $service->testConnection($config),
        $service->getAccounts($config, 'expired-session'),
        $service->fetchAccountBalances($config),
        $service->syncAll($config, $db->getAccountsByBankId($bank), 'recent-session'),
        $service->getTransactions($config, OfflineFinTs::$accounts[0]),
        $service->syncAccountTransactions($config, '1234', new DateTime('-1 day'), new DateTime()),
        $service->getDepotHoldings($config, '5678'),
        $service->getTanModes($config),
        $service->getBankCapabilities($config),
        $service->submitTan($config, 'stale', 'untrusted', 'unused'),
        $service->checkDecoupledStatus($config, 'stale', 'untrusted'),
    ] as $blocked) {
        verify($blocked['reason'] === FinTSService::BACKGROUND_BLOCK_REASON, 'Unattended request was not blocked');
        verify($blocked['needs_tan'] === false, 'Blocked request falsely claimed a TAN was initiated');
    }
    verify(OfflineFinTs::$events === [], 'Unattended request reached client');
    try {
        $service->init($config);
        throw new RuntimeException('Unattended initialization was allowed');
    } catch (RuntimeException $e) {
        verify($e->getMessage() === FinTSService::BACKGROUND_BLOCK_REASON, 'Initialization bypassed the central guard');
    }
    $auth->blocked($bank);
    verify($db->getBankAuthorizationState($bank)['status'] === 'required', 'Required state not persisted');

    OfflineFinTs::$challengeTypes = ['DialogInitialization', 'GetSEPAAccounts', 'GetBalance', 'GetStatementOfAccount', 'GetDepotAufstellung'];
    $result = $auth->authorize($bank, 'browser-a', 'request-one');
    verify(!empty($result['needs_tan']), 'Login TAN missing');
    verify(OfflineFinTs::$events === ['DialogInitialization'], 'Continued after login TAN');
    verify($db->getFinTSSession($bank) === null, 'Pending data leaked into technical session');
    verify($db->getBankAuthorizationState($bank)['authenticated_at'] === null, 'Pending session marked authenticated');
    $before = OfflineFinTs::$events;
    $duplicate = $auth->authorize($bank, 'browser-a', 'request-one');
    verify($duplicate['operation_id'] === $result['operation_id'] && OfflineFinTs::$events === $before, 'Duplicate authorize was replayed');
    verify($auth->authorize($bank, 'browser-b', 'request-two')['reason'] === 'authorization_owned_by_other_browser', 'Other browser stole pending operation');
    verify(!isset($auth->state($bank, 'browser-b')['operation_id']), 'Other browser can read operation token');
    verify($auth->resume($bank, 'browser-a', 'incorrect-token', '123456', false)['reason'] === 'authorization_operation_mismatch', 'Mismatched operation accepted');

    $expected = ['GetSEPAAccounts', 'GetBalance', 'GetStatementOfAccount', 'GetDepotAufstellung'];
    foreach ($expected as $type) {
        $previous = $result['operation_id'];
        $result = $auth->resume($bank, 'browser-a', $previous, '123456', false);
        verify(!empty($result['needs_tan']), 'Expected next TAN for ' . $type . ': ' . json_encode($result));
        verify(end(OfflineFinTs::$events) === $type, 'Continued after TAN for ' . $type);
        verify($result['operation_id'] !== $previous, 'New challenge reused old operation token');
        $before = OfflineFinTs::$events;
        verify($auth->resume($bank, 'browser-a', $previous, '123456', false)['reason'] === 'authorization_operation_mismatch', 'Duplicate TAN replayed into next action');
        verify(OfflineFinTs::$events === $before, 'Mismatched TAN performed traffic');
    }
    verify($db->getAccountById($checking)['balance'] === 123.45, 'Partial balance not saved before later TAN');
    $result = $auth->resume($bank, 'browser-a', $result['operation_id'], '123456', false);
    verify($result['success'] === true, 'Full manual sync failed: ' . json_encode($result));
    verify($result['stats']['balances_updated'] === 1, 'Partial balance counted twice');
    verify($db->getSecuritiesHoldings($depot) === [], 'Successful empty depot not saved');
    verify($db->getAccountById($depot)['balance'] == 0, 'Empty depot retained old balance');
    verify($db->getBankAuthorizationState($bank)['status'] === 'authorized', 'Successful authorization not persisted');
    verify($auth->state($bank, 'browser-a')['authorization_expiry_is_bank_guarantee'] === false, 'Local authorization maximum advertised as bank guarantee');
    verify($db->getAccountById($checking)['account_name'] === 'Personal name', 'Account metadata overwritten');
    verify((int) $db->getAccountById($checking)['background_sync_enabled'] === 0, 'Background account preference overwritten');
    verify((int) $db->getAccountById($checking)['tan_manual_approval'] === 1, 'Legacy account flag overwritten');
    $before = OfflineFinTs::$events;
    verify($auth->authorize($bank, 'browser-a', 'request-one')['success'] === true, 'Completed duplicate lost result');
    verify(OfflineFinTs::$events === $before, 'Completed duplicate made traffic');
    $auth->blocked($bank);
    verify($db->getBankAuthorizationState($bank)['status'] === 'authorized', 'Background block erased factual successful auth');

    $result = $auth->authorize($bank, 'browser-a', 'request-cancel');
    $before = OfflineFinTs::$events;
    verify($auth->cancel($bank, 'browser-b', $result['operation_id'])['success'] === false, 'Other browser cancelled operation');
    verify($auth->cancel($bank, 'browser-a', $result['operation_id'])['success'], 'Cancellation failed');
    verify(OfflineFinTs::$events === $before, 'Cancellation sent bank traffic');
    verify($db->getPdo()->query('SELECT payload FROM fints_authorization_operations')->fetchColumn() === null, 'Cancellation retained sensitive continuation');

    $result = $auth->authorize($bank, 'browser-a', 'request-expire');
    $db->getPdo()->exec('UPDATE fints_authorization_operations SET expires_at = 0');
    $before = OfflineFinTs::$events;
    verify($auth->resume($bank, 'browser-a', $result['operation_id'], '123456', false)['reason'] === 'authorization_expired', 'Expired operation resumed');
    verify(OfflineFinTs::$events === $before, 'Expiry sent bank traffic');
    verify($db->getBankAuthorizationState($bank)['reason'] === 'authorization_expired', 'Expiry state not persisted');

    $handle = fopen($directory . '/fints-bank-' . $bank . '.lock', 'c');
    flock($handle, LOCK_EX);
    verify($auth->authorize($bank, 'browser-a', 'request-locked')['reason'] === 'authorization_busy', 'Per-bank lock not enforced');
    flock($handle, LOCK_UN);
    fclose($handle);

    OfflineFinTs::$throwOn = 'DialogInitialization';
    $before = count(OfflineFinTs::$events);
    $result = $auth->authorize($bank, 'browser-a', 'request-failure');
    verify(!$result['success'], 'Failed login reported success');
    verify(count(OfflineFinTs::$events) === $before + 1, 'Failed login retried with fresh session');
    verify($db->getBankAuthorizationState($bank)['status'] === 'error', 'Failed authorization not persisted');

    OfflineFinTs::$throwOn = null;
    OfflineFinTs::$decoupled = true;
    OfflineFinTs::$pollComplete = false;
    OfflineFinTs::$challengeTypes = ['DialogInitialization'];
    $result = $auth->authorize($bank, 'browser-a', 'request-poll-limit');
    verify($result['tan_request']['poll_interval'] === 9, 'Bank polling delay ignored');
    $before = OfflineFinTs::$events;
    $auth->resume($bank, 'browser-a', $result['operation_id'], null, true);
    verify(OfflineFinTs::$events === $before, 'Poll throttle made network call');
    $db->getPdo()->exec('UPDATE fints_authorization_operations SET next_poll_at = 0');
    $result = $auth->resume($bank, 'browser-a', $result['operation_id'], null, true);
    verify(!empty($result['needs_tan']), 'Pending decoupled action lost');
    $db->getPdo()->exec('UPDATE fints_authorization_operations SET next_poll_at = 0');
    $before = OfflineFinTs::$events;
    verify($auth->resume($bank, 'browser-a', $result['operation_id'], null, true)['reason'] === 'authorization_poll_limit', 'Bank poll maximum ignored');
    verify(OfflineFinTs::$events === $before, 'Exceeded poll maximum made network call');

    OfflineFinTs::$pollComplete = true;
    OfflineFinTs::$camtOnly = true;
    OfflineFinTs::$challengeTypes = ['DialogInitialization', 'GetSEPAAccounts', 'GetStatementOfAccountXML'];
    $result = $auth->authorize($bank, 'browser-a', 'request-decoupled-camt');
    $start = count(OfflineFinTs::$events);
    for ($i = 0; $i < 3; $i++) {
        verify(!empty($result['needs_tan']), 'Expected decoupled continuation');
        $db->getPdo()->exec('UPDATE fints_authorization_operations SET next_poll_at = 0');
        $result = $auth->resume($bank, 'browser-a', $result['operation_id'], null, true);
    }
    verify($result['success'], 'Decoupled CAMT continuation failed: ' . json_encode($result));
    $events = array_slice(OfflineFinTs::$events, $start);
    verify(count(array_filter($events, fn ($event) => $event === 'close')) === 1 && end($events) === 'close',
        'Empty CAMT prematurely closed dialog before depot');

    $result = $auth->authorize($bank, 'browser-a', 'request-crash');
    $db->getPdo()->exec("UPDATE fints_authorization_operations SET status = 'running', payload = NULL, response = NULL");
    $before = OfflineFinTs::$events;
    verify($auth->authorize($bank, 'browser-a', 'request-crash-retry')['reason'] === 'authorization_busy', 'Uncertain operation automatically replayed after crash');
    verify(OfflineFinTs::$events === $before, 'Crash retry made network call');

    $_SESSION = [];
    $api = new \App\Controllers\ApiController($db, $service, new \App\Services\MqttService($logger, $db), $logger);
    $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/');
    $response = new \Slim\Psr7\Response();
    foreach ([
        $api->cachedBankAccounts($request, $response, ['id' => $bank]),
        $api->backgroundBankSync($request, new \Slim\Psr7\Response(), ['id' => $bank]),
        $api->backgroundAccountSync($request, new \Slim\Psr7\Response(), ['id' => $checking]),
        $api->getTanSessionInfo($request, new \Slim\Psr7\Response(), ['id' => $bank]),
    ] as $safeResponse) {
        verify($safeResponse->getStatusCode() === 200, 'Safe API request failed');
    }
    $auto = $api->runAutoSync($request, new \Slim\Psr7\Response());
    $autoData = json_decode((string) $auto->getBody(), true);
    verify($autoData['stats']['banks_synced'] === 0 && $autoData['stats']['banks_skipped'] === 1, 'Auto-sync API falsely reported successful FinTS sync');
    verify(OfflineFinTs::$events === $before, 'Ordinary API made FinTS traffic');
    $invalid = $api->setAccountBackgroundSync($request->withParsedBody(['enabled' => 'false']), new \Slim\Psr7\Response(), ['id' => $checking]);
    verify($invalid->getStatusCode() === 400, 'Ambiguous account preference accepted');
    $missingKey = $api->authorizeBank($request->withParsedBody([]), new \Slim\Psr7\Response(), ['id' => $bank]);
    verify($missingKey->getStatusCode() === 400, 'Authorize accepted request without idempotency key');

    echo "FinTS authorization regression tests passed (offline; no bank requests).\n";
} finally {
    unset($db, $auth, $service);
    foreach (glob($directory . '/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}
