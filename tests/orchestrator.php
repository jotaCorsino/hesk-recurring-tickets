<?php

declare(strict_types=1);

use TicketsRecorrentesHesk\BatchProcessor;
use TicketsRecorrentesHesk\Database;
use TicketsRecorrentesHesk\ExecutionItemRepository;
use TicketsRecorrentesHesk\ExecutionLeaseService;
use TicketsRecorrentesHesk\FileOrchestratorLock;
use TicketsRecorrentesHesk\ImmediateTransaction;
use TicketsRecorrentesHesk\MigrationRunner;
use TicketsRecorrentesHesk\Orchestrator;
use TicketsRecorrentesHesk\OrchestratorCliOptions;
use TicketsRecorrentesHesk\OrchestratorLock;
use TicketsRecorrentesHesk\RecurrenceExecutionRepository;
use TicketsRecorrentesHesk\RecurrenceRepository;
use TicketsRecorrentesHesk\RecurrenceScheduleCalculator;
use TicketsRecorrentesHesk\Scheduler;
use TicketsRecorrentesHesk\TicketGateway;

foreach ([
    'Database', 'MigrationRunner', 'RecurrenceValidator', 'RecurrenceRepository',
    'RecurrenceExecutionRepository', 'ExecutionItemRepository', 'ImmediateTransaction',
    'ExecutionLeaseService', 'RecurrenceScheduleCalculator', 'Scheduler', 'TicketGateway',
    'OperationalException', 'BatchProcessor', 'OrchestratorLock', 'FileOrchestratorLock',
    'Orchestrator', 'OrchestratorCliOptions',
] as $source) {
    require dirname(__DIR__) . "/src/{$source}.php";
}

final class OrchestratorFakeGateway implements TicketGateway
{
    /** @var array<string, array{id: int, trackid: string}> */
    public array $tickets = [];
    public int $createCalls = 0;
    public int $validateCalls = 0;
    public ?int $failCall = null;
    public ?int $crashAfterCreateCall = null;
    private int $sequence = 0;

    public function validate(array $definition): array
    {
        $this->validateCalls++;
        return ['valid' => true];
    }

    public function generateTrackingId(): string
    {
        return sprintf('ORC-%08d', ++$this->sequence);
    }

    public function findByTrackingId(string $trackingId): ?array
    {
        return $this->tickets[$trackingId] ?? null;
    }

    public function create(array $definition, string $trackingId): array
    {
        $call = ++$this->createCalls;

        if ($this->failCall === $call) {
            throw new RuntimeException('hesk_secret=segredo-que-nao-pode-vazar');
        }

        if (isset($this->tickets[$trackingId])) {
            return $this->tickets[$trackingId] + ['created_now' => false];
        }

        $ticket = ['id' => 1000 + count($this->tickets), 'trackid' => $trackingId];
        $this->tickets[$trackingId] = $ticket;

        if ($this->crashAfterCreateCall === $call) {
            $this->crashAfterCreateCall = null;
            throw new RuntimeException('token=credencial-sensivel');
        }

        return $ticket + ['created_now' => true];
    }
}

final class OrchestratorFakeLock implements OrchestratorLock
{
    public int $acquisitions = 0;
    public int $releases = 0;

    public function __construct(private readonly bool $available = true)
    {
    }

    public function acquire(): bool
    {
        $this->acquisitions++;
        return $this->available;
    }

    public function release(): void
    {
        $this->releases++;
    }
}

$failures = [];
$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$failures, &$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        $failures[] = sprintf('%s: esperado %s, recebido %s', $message, var_export($expected, true), var_export($actual, true));
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$failures, &$assertions): void {
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
    }
};
$assertThrows = static function (callable $callback, string $message) use (&$failures, &$assertions): void {
    $assertions++;
    try {
        $callback();
        $failures[] = "{$message}: nenhuma exceção foi lançada";
    } catch (InvalidArgumentException) {
    }
};
$phpSubprocessCommand = static fn (array $arguments): array => array_merge(
    [PHP_BINARY, '-d', 'log_errors=1', '-d', 'error_log=/dev/stderr'],
    $arguments
);
$normalizePhpSubprocessStderr = static function (string $stderr): string {
    $lines = preg_split('/\R/', $stderr);

    if (!is_array($lines)) {
        return $stderr;
    }

    $unexpected = array_filter(
        $lines,
        static fn (string $line): bool => trim($line) !== ''
            && preg_match(
                "/^(?:(?:\\[[^\\r\\n]+\\]\\s+)?PHP\\s+)?Deprecated:\\s+Directive 'allow_url_include' is deprecated in Unknown on line 0$/D",
                trim($line)
            ) !== 1
    );

    return implode("\n", $unexpected);
};
$knownDeprecated = "PHP Deprecated: Directive 'allow_url_include' is deprecated in Unknown on line 0\n";
$timestampedDeprecated = "[06-Oct-2026 15:10:09 America/Sao_Paulo] PHP Deprecated:  Directive 'allow_url_include' is deprecated in Unknown on line 0\n";
$differentWarning = "PHP Warning: outro warning de ambiente em Unknown on line 0\n";
$arbitraryError = "Falha arbitrária produzida pela aplicação.\n";
$assertSame('', $normalizePhpSubprocessStderr($knownDeprecated), 'Normalizador remove warning conhecido sem timestamp');
$assertSame('', $normalizePhpSubprocessStderr($timestampedDeprecated), 'Normalizador remove warning conhecido com timestamp do cPanel');
$assertSame(trim($differentWarning), $normalizePhpSubprocessStderr($differentWarning), 'Normalizador preserva warning diferente');
$assertSame(trim($arbitraryError), $normalizePhpSubprocessStderr($arbitraryError), 'Normalizador preserva erro arbitrário');

$temporaryDirectory = sys_get_temp_dir() . '/tickets-recorrentes-orc-' . bin2hex(random_bytes(8));
$databasePath = $temporaryDirectory . '/orchestrator.sqlite';
$lockPath = $temporaryDirectory . '/orchestrator.lock';
$projectErrorLogPath = dirname(__DIR__) . '/error_log';
$projectErrorLogBefore = is_file($projectErrorLogPath)
    ? ['exists' => true, 'hash' => hash_file('sha256', $projectErrorLogPath)]
    : ['exists' => false, 'hash' => null];
mkdir($temporaryDirectory, 0700, true);
$connection = null;
$readConnection = null;

try {
    $database = new Database($databasePath);
    $connection = $database->connect();
    (new MigrationRunner($connection, dirname(__DIR__) . '/database/migrations'))->migrate();
    $recurrences = new RecurrenceRepository($connection);
    $executions = new RecurrenceExecutionRepository($connection);
    $items = new ExecutionItemRepository($connection);
    $leases = new ExecutionLeaseService($executions, new ImmediateTransaction($connection));
    $scheduler = new Scheduler($connection, $recurrences, $executions, new RecurrenceScheduleCalculator());
    $now = new DateTimeImmutable('2026-10-06T15:00:00Z');
    $clock = static fn (): DateTimeImmutable => $now;
    $gateway = new OrchestratorFakeGateway();
    $factoryCalls = 0;
    $factory = static function () use (&$factoryCalls, $recurrences, $executions, $items, $leases, $gateway, $clock): BatchProcessor {
        $factoryCalls++;
        return new BatchProcessor($recurrences, $executions, $items, $leases, $gateway, $clock);
    };
    $definition = [
        'name' => 'Orquestrador', 'enabled' => false, 'timezone' => 'UTC',
        'interval_value' => 1, 'interval_unit' => 'day', 'next_run_at' => '2026-10-01T12:00:00Z',
        'quantity' => 1, 'customer_id' => 100, 'category_id' => 5, 'priority_name' => 'Baixa',
        'status_id' => 0, 'owner_id' => 4, 'openedby_id' => 4, 'subject' => 'Teste',
        'message' => 'Teste do orquestrador', 'notify_customer' => false, 'custom_fields' => [],
    ];
    $createRecurrence = static fn (array $changes = []): array => $recurrences->create(array_merge($definition, $changes));
    $createExecution = static fn (int $recurrenceId, string $scheduledFor): array => $executions->create([
        'recurrence_id' => $recurrenceId,
        'scheduled_for' => $scheduledFor,
        'expected_count' => 1,
    ]);
    $newOrchestrator = static fn (
        OrchestratorLock $lock,
        ?Closure $customFactory = null,
        ?Closure $orchestratorClock = null,
    ): Orchestrator => new Orchestrator(
        $scheduler,
        $executions,
        $leases,
        $lock,
        $customFactory ?? $factory,
        $orchestratorClock ?? $clock
    );

    // check é estritamente somente leitura, sem lock, bootstrap ou materialização.
    $due = $createRecurrence(['enabled' => true, 'name' => 'Vencida no check']);
    $pendingRecurrence = $createRecurrence(['name' => 'Pending anterior']);
    $pending = $createExecution((int) $pendingRecurrence['id'], '2026-10-01T10:00:00Z');
    $beforeRecurrence = $recurrences->findById((int) $due['id']);
    $beforeExecution = $executions->findById((int) $pending['id']);
    $checkLock = new OrchestratorFakeLock();
    $check = $newOrchestrator($checkLock)->check($now, 10);
    $assertSame(1, $check['due_recurrences'], 'Check conta recorrência vencida');
    $assertSame(1, $check['potential_executions'], 'Check conta execução potencial');
    $assertSame($beforeRecurrence, $recurrences->findById((int) $due['id']), 'Check não altera recorrência');
    $assertSame($beforeExecution, $executions->findById((int) $pending['id']), 'Check não altera execution');
    $assertSame(0, $checkLock->acquisitions, 'Check não abre lock');
    $assertSame(0, $factoryCalls, 'Check não carrega worker/HESK');
    $assertSame([], $items->findByExecution((int) $pending['id']), 'Check não materializa itens');

    // Um ciclo agenda a vencida e processa tanto ela quanto a pending antiga.
    $runLock = new OrchestratorFakeLock();
    $run = $newOrchestrator($runLock)->run($now, 10, 10, 'test:1', 300);
    $assertSame(1, $run['scheduled'], 'Run agenda a recorrência vencida');
    $assertSame(2, $run['processed'], 'Run processa pending anterior e recém-agendada no mesmo ciclo');
    $assertSame(2, $run['succeeded'], 'As duas executions concluem');
    $assertSame('succeeded', $executions->findById((int) $pending['id'])['status'], 'Pending anterior foi concluída');
    $assertSame(1, $runLock->releases, 'Lock é liberado após sucesso');
    $recurrences->setEnabled((int) $due['id'], false);

    // Seleção finita respeita limite e ordem; terminais e lease ativo ficam fora.
    $limited = $createRecurrence(['name' => 'Limite']);
    $limitedIds = [];
    foreach (['2026-10-02T01:00:00Z', '2026-10-02T02:00:00Z', '2026-10-02T03:00:00Z'] as $scheduledFor) {
        $limitedIds[] = (int) $createExecution((int) $limited['id'], $scheduledFor)['id'];
    }
    $limitedRun = $newOrchestrator(new OrchestratorFakeLock())->run($now, 10, 2, 'test:limit', 300);
    $assertSame(2, $limitedRun['selected'], 'Limite seleciona exatamente duas');
    $assertSame('succeeded', $executions->findById($limitedIds[0])['status'], 'Primeira por ordem é processada');
    $assertSame('succeeded', $executions->findById($limitedIds[1])['status'], 'Segunda por ordem é processada');
    $assertSame('pending', $executions->findById($limitedIds[2])['status'], 'Terceira permanece pending');

    $statuses = $createRecurrence(['name' => 'Estados']);
    $terminalIds = [];
    foreach (['succeeded', 'failed', 'partial'] as $index => $status) {
        $execution = $createExecution((int) $statuses['id'], sprintf('2026-09-%02dT00:00:00Z', $index + 1));
        $terminalIds[$status] = (int) $execution['id'];
        $connection->prepare('UPDATE recurrence_executions SET status = :status WHERE id = :id')->execute(['status' => $status, 'id' => $execution['id']]);
    }
    $active = $createExecution((int) $statuses['id'], '2026-09-04T00:00:00Z');
    $leases->claimById((int) $active['id'], 'active', $now, 300);
    $eligibleBefore = $executions->countClaimable($now->format('Y-m-d\TH:i:s\Z'));
    $assertSame(1, $eligibleBefore, 'Só a terceira execution limitada permanece elegível');
    $terminalRun = $newOrchestrator(new OrchestratorFakeLock())->run($now, 10, 10, 'test:terminal', 300);
    $assertSame('succeeded', $executions->findById($terminalIds['succeeded'])['status'], 'Succeeded não é reprocessada');
    $assertSame('failed', $executions->findById($terminalIds['failed'])['status'], 'Failed exige retry explícito');
    $assertSame('partial', $executions->findById($terminalIds['partial'])['status'], 'Partial exige retry explícito');
    $assertSame('running', $executions->findById((int) $active['id'])['status'], 'Lease ativo é ignorado');
    $connection->prepare(
        "UPDATE recurrence_executions
         SET status = 'failed', lease_token = NULL, lease_owner = NULL, lease_expires_at = NULL
         WHERE id = :id"
    )->execute(['id' => $active['id']]);

    // Lease expirado pode ser retomado.
    $expired = $createExecution((int) $statuses['id'], '2026-09-05T00:00:00Z');
    $leases->claimById((int) $expired['id'], 'expired-owner', new DateTimeImmutable('2026-10-06T14:00:00Z'), 30);
    $expiredRun = $newOrchestrator(new OrchestratorFakeLock())->run($now, 10, 10, 'takeover', 300);
    $assertSame('succeeded', $executions->findById((int) $expired['id'])['status'], 'Lease expirado é retomado e concluído');
    $assertSame(2, $executions->findById((int) $expired['id'])['attempt_count'], 'Takeover registra segunda tentativa');

    // Lock ocupado impede scheduler e worker; release ocorre em exceção.
    $blockedDue = $createRecurrence(['enabled' => true, 'name' => 'Bloqueada por lock']);
    $factoryBeforeBusy = $factoryCalls;
    $busy = $newOrchestrator(new OrchestratorFakeLock(false))->run($now, 10, 10, 'busy', 300);
    $assertSame(Orchestrator::EXIT_LOCK_BUSY, $busy['exit_code'], 'Lock ocupado possui exit code próprio');
    $assertSame([], $executions->findByRecurrence((int) $blockedDue['id']), 'Lock ocupado não executa scheduler');
    $assertSame($factoryBeforeBusy, $factoryCalls, 'Lock ocupado não inicializa worker');
    $recurrences->setEnabled((int) $blockedDue['id'], false);
    $exceptionLock = new OrchestratorFakeLock();
    $newOrchestrator($exceptionLock)->run($now, 0, 10, 'exception', 300);
    $assertSame(1, $exceptionLock->releases, 'Lock é liberado quando scheduler lança exceção');

    // Erro de uma recorrência no scheduler é isolado e reportado.
    $badSchedule = $createRecurrence(['enabled' => true, 'name' => 'Timezone corrompida']);
    $connection->prepare('UPDATE recurrences SET timezone = :timezone WHERE id = :id')->execute(['timezone' => 'Invalid/Timezone', 'id' => $badSchedule['id']]);
    $schedulerError = $newOrchestrator(new OrchestratorFakeLock())->run($now, 10, 10, 'scheduler-error', 300);
    $assertSame(1, $schedulerError['scheduler_errors'], 'Erro do scheduler é contabilizado');
    $assertSame(Orchestrator::EXIT_OPERATIONAL_FAILURE, $schedulerError['exit_code'], 'Erro do scheduler produz falha operacional');
    $recurrences->setEnabled((int) $badSchedule['id'], false);

    // Falha de uma execução não impede as demais e o relatório não vaza detalhes.
    $processing = $createRecurrence(['name' => 'Falhas isoladas']);
    $firstFailure = $createExecution((int) $processing['id'], '2026-10-03T01:00:00Z');
    $secondSuccess = $createExecution((int) $processing['id'], '2026-10-03T02:00:00Z');
    $gateway->failCall = $gateway->createCalls + 1;
    $processingRun = $newOrchestrator(new OrchestratorFakeLock())->run($now, 10, 10, 'processing', 300);
    $assertSame('failed', $executions->findById((int) $firstFailure['id'])['status'], 'Falha fica registrada na execution afetada');
    $assertSame('succeeded', $executions->findById((int) $secondSuccess['id'])['status'], 'Execution seguinte continua e conclui');
    $serialized = json_encode($processingRun, JSON_THROW_ON_ERROR);
    $assertTrue(!str_contains($serialized, 'hesk_secret'), 'Relatório não expõe segredo técnico');
    $assertTrue(!str_contains($serialized, 'credencial'), 'Relatório não expõe credencial');
    $assertTrue(!str_contains($serialized, 'lease_token'), 'Relatório não expõe lease token');
    $gateway->failCall = null;

    // Crash depois da criação é reconciliado após retry explícito, sem ticket duplicado.
    $retryRecurrence = $createRecurrence(['name' => 'Reconciliação']);
    $retryExecution = $createExecution((int) $retryRecurrence['id'], '2026-10-04T00:00:00Z');
    $gateway->crashAfterCreateCall = $gateway->createCalls + 1;
    $newOrchestrator(new OrchestratorFakeLock())->run($now, 10, 10, 'crash', 300);
    $assertSame('failed', $executions->findById((int) $retryExecution['id'])['status'], 'Crash controlado finaliza como failed');
    $ticketCount = count($gateway->tickets);
    $retry = $leases->retry((int) $retryExecution['id'], $now->modify('+1 second'));
    $assertSame(true, $retry['retried'], 'Retry explícito devolve execução a pending');
    $retryRun = $newOrchestrator(new OrchestratorFakeLock())->run($now->modify('+2 seconds'), 10, 10, 'retry', 300);
    $assertSame('succeeded', $executions->findById((int) $retryExecution['id'])['status'], 'Retry reconcilia e conclui');
    $assertSame($ticketCount, count($gateway->tickets), 'Reconciliação não duplica ticket HESK');
    $assertSame(1, $retryRun['tickets_reconciled'], 'Relatório registra reconciliação');

    // Seleção e cada claim consultam o clock no instante da operação.
    $clockRecurrence = $createRecurrence(['name' => 'Relógio por claim']);
    $selectionExecution = $createExecution((int) $clockRecurrence['id'], '2026-10-04T00:00:00Z');
    $leases->claimById((int) $selectionExecution['id'], 'selection-clock', $now, 1800);
    $clockExecutionA = $createExecution((int) $clockRecurrence['id'], '2026-10-04T01:00:00Z');
    $clockExecutionB = $createExecution((int) $clockRecurrence['id'], '2026-10-04T02:00:00Z');
    $currentOperationTime = $now;
    $operationTimes = [
        new DateTimeImmutable('2026-10-06T16:00:00Z'), // seleção
        new DateTimeImmutable('2026-10-06T16:00:30Z'), // takeover elegível só no horário da seleção
        new DateTimeImmutable('2026-10-06T16:01:00Z'), // primeiro claim
        new DateTimeImmutable('2026-10-06T16:11:00Z'), // segundo claim
    ];
    $advancingClock = static function () use (&$operationTimes, &$currentOperationTime): DateTimeImmutable {
        $currentOperationTime = array_shift($operationTimes)
            ?? throw new RuntimeException('Clock do teste foi consultado além do esperado.');
        return $currentOperationTime;
    };
    $operationProcessorFactory = static fn (): BatchProcessor => new BatchProcessor(
        $recurrences,
        $executions,
        $items,
        $leases,
        $gateway,
        static fn (): DateTimeImmutable => $currentOperationTime
    );
    $clockRun = $newOrchestrator(
        new OrchestratorFakeLock(),
        $operationProcessorFactory,
        $advancingClock
    )->run($now, 10, 10, 'clock-claims', 300);
    $assertSame('2026-10-06T15:00:00Z', $clockRun['now_utc'], 'Resumo preserva o horário inicial do ciclo');
    $assertSame(3, $clockRun['succeeded'], 'Ciclo seleciona takeover pelo horário atual e conclui as três executions');
    $assertSame(
        '2026-10-06T16:00:30Z',
        $executions->findById((int) $selectionExecution['id'])['last_attempt_at'],
        'Seleção posterior ao scheduler considera lease que expirou durante o ciclo'
    );
    $assertSame(
        '2026-10-06T16:01:00Z',
        $executions->findById((int) $clockExecutionA['id'])['last_attempt_at'],
        'Primeiro claim usa o horário atual daquele claim'
    );
    $assertSame(
        '2026-10-06T16:11:00Z',
        $executions->findById((int) $clockExecutionB['id'])['last_attempt_at'],
        'Execution posterior não reutiliza o início do ciclo'
    );
    $assertSame([], $operationTimes, 'Clock foi consultado para seleção e para cada claim');

    // O finish de recuperação consulta novamente o clock no instante da falha.
    $finishClockExecution = $createExecution((int) $clockRecurrence['id'], '2026-10-04T03:00:00Z');
    $finishTimes = [
        new DateTimeImmutable('2026-10-06T17:00:00Z'), // seleção
        new DateTimeImmutable('2026-10-06T17:01:00Z'), // claim
        new DateTimeImmutable('2026-10-06T17:04:00Z'), // finish de recuperação
    ];
    $finishClock = static function () use (&$finishTimes): DateTimeImmutable {
        return array_shift($finishTimes)
            ?? throw new RuntimeException('Clock de finish foi consultado além do esperado.');
    };
    $throwingProcessorFactory = static fn (): BatchProcessor => new BatchProcessor(
        $recurrences,
        $executions,
        $items,
        $leases,
        $gateway,
        static fn (): DateTimeImmutable => throw new RuntimeException('Falha fora do tratamento interno.')
    );
    $finishRun = $newOrchestrator(
        new OrchestratorFakeLock(),
        $throwingProcessorFactory,
        $finishClock
    )->run($now, 10, 10, 'clock-finish', 300);
    $finishedExecution = $executions->findById((int) $finishClockExecution['id']);
    $assertSame(1, $finishRun['failed'], 'Exceção inesperada é contabilizada');
    $assertSame('2026-10-06T17:01:00Z', $finishedExecution['last_attempt_at'], 'Claim da falha usa seu horário atual');
    $assertSame('2026-10-06T17:04:00Z', $finishedExecution['finished_at'], 'Finish de recuperação usa o horário atual da falha');
    $assertSame('ORCHESTRATOR_PROCESSING_ERROR', $finishedExecution['error_code'], 'Finish registra código seguro');
    $assertSame([], $finishTimes, 'Clock foi consultado novamente no finish de recuperação');

    // Factory/HESK só é chamada se existir candidato e seu erro libera o lock.
    $noCandidateCalls = 0;
    $noCandidateFactory = static function () use (&$noCandidateCalls): BatchProcessor {
        $noCandidateCalls++;
        throw new RuntimeException('não deveria carregar');
    };
    $noCandidate = $newOrchestrator(new OrchestratorFakeLock(), $noCandidateFactory)->run($now, 10, 10, 'idle', 300);
    $assertSame(0, $noCandidateCalls, 'Sem candidatos o bootstrap HESK não é carregado');
    $assertSame(Orchestrator::EXIT_SUCCESS, $noCandidate['exit_code'], 'Ciclo ocioso é sucesso');
    $bootFailureExecution = $createExecution((int) $processing['id'], '2026-10-05T00:00:00Z');
    $bootFailureLock = new OrchestratorFakeLock();
    $bootFailure = $newOrchestrator($bootFailureLock, static fn (): BatchProcessor => throw new RuntimeException('senha=secreta'))->run($now, 10, 10, 'boot', 300);
    $assertSame('pending', $executions->findById((int) $bootFailureExecution['id'])['status'], 'Falha de bootstrap ocorre antes do claim');
    $assertSame(1, $bootFailureLock->releases, 'Falha de bootstrap libera lock');
    $assertTrue(!str_contains(json_encode($bootFailure), 'secreta'), 'Falha de bootstrap é sanitizada');

    // Concorrência real entre processos no namespace do lock de arquivos.
    $holderScript = $temporaryDirectory . '/hold-lock.php';
    file_put_contents($holderScript, '<?php require ' . var_export(dirname(__DIR__) . '/src/OrchestratorLock.php', true) . '; require ' . var_export(dirname(__DIR__) . '/src/FileOrchestratorLock.php', true) . '; $l=new TicketsRecorrentesHesk\\FileOrchestratorLock($argv[1]); if(!$l->acquire()){exit(2);} echo "locked\\n"; flush(); usleep(1500000);');
    $pipes = [];
    $process = proc_open(
        $phpSubprocessCommand([$holderScript, $lockPath]),
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes
    );
    $assertTrue(is_resource($process), 'Processo concorrente deve iniciar');
    $signal = is_resource($process) ? fgets($pipes[1]) : false;
    $assertSame("locked\n", $signal, 'Processo concorrente confirma aquisição');
    $contender = new FileOrchestratorLock($lockPath);
    $assertSame(false, $contender->acquire(), 'Segunda instância não adquire lock ocupado');
    $holderStderr = '';
    $holderExit = -1;
    if (is_resource($process)) {
        fclose($pipes[0]);
        fclose($pipes[1]);
        $holderStderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $holderExit = proc_close($process);
    }
    $assertSame(0, $holderExit, 'Processo concorrente encerra normalmente');
    $assertSame('', $normalizePhpSubprocessStderr($holderStderr), 'Processo concorrente não produz stderr inesperado');
    $assertSame(true, $contender->acquire(), 'Lock é liberado após término da primeira instância');
    $contender->release();

    // Contrato da CLI e exit codes.
    $parsedCheck = OrchestratorCliOptions::parse(['orchestrator.php', 'check', '--scheduler-limit=12'], $databasePath, null, null);
    $assertSame('check', $parsedCheck->command, 'CLI aceita check sem HESK');
    $parsedRun = OrchestratorCliOptions::parse(['orchestrator.php', 'run', '--execution-limit=3'], $databasePath, '/opt/hesk', $lockPath);
    $assertSame(3, $parsedRun->executionLimit, 'CLI aceita limite de executions');
    $assertSame(10, $parsedRun->schedulerLimit, 'CLI usa limite conservador padrão');
    $assertThrows(static fn () => OrchestratorCliOptions::parse(['orchestrator.php', 'run'], $databasePath, null, null), 'Run exige caminho HESK');
    $assertThrows(static fn () => OrchestratorCliOptions::parse(['orchestrator.php', 'run', '--execution-limit=101'], $databasePath, '/opt/hesk', null), 'CLI bloqueia limite excessivo');
    $assertTrue(count(array_unique([Orchestrator::EXIT_SUCCESS, Orchestrator::EXIT_OPERATIONAL_FAILURE, Orchestrator::EXIT_USAGE_OR_CONFIGURATION, Orchestrator::EXIT_LOCK_BUSY])) === 4, 'Exit codes são inequívocos');

    // A CLI check abre uma base separada em mode=ro e preserva o arquivo byte a byte.
    $checkDatabasePath = $temporaryDirectory . '/check-cli.sqlite';
    $checkDatabase = new Database($checkDatabasePath);
    $checkConnection = $checkDatabase->connect();
    (new MigrationRunner($checkConnection, dirname(__DIR__) . '/database/migrations'))->migrate();
    $checkRecurrences = new RecurrenceRepository($checkConnection);
    $checkExecutions = new RecurrenceExecutionRepository($checkConnection);
    $checkRecurrence = $checkRecurrences->create(array_merge($definition, [
        'enabled' => true,
        'name' => 'Check CLI',
    ]));
    $checkExecutions->create([
        'recurrence_id' => $checkRecurrence['id'],
        'scheduled_for' => '2026-10-01T00:00:00Z',
        'expected_count' => 1,
    ]);
    $checkConnection->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    unset($checkExecutions, $checkRecurrences, $checkConnection, $checkDatabase);
    gc_collect_cycles();
    $checkHashBefore = hash_file('sha256', $checkDatabasePath);
    $checkLockPath = $temporaryDirectory . '/check-cli.lock';
    $previousLockEnvironment = getenv('ORCHESTRATOR_LOCK_PATH');
    $checkEnvironment = getenv();
    $checkEnvironment = is_array($checkEnvironment) ? $checkEnvironment : [];
    $checkEnvironment['ORCHESTRATOR_LOCK_PATH'] = $checkLockPath;
    $checkPipes = [];
    $checkProcess = proc_open(
        $phpSubprocessCommand([
            dirname(__DIR__) . '/bin/orchestrator.php',
            'check',
            '--db-path=' . $checkDatabasePath,
            '--scheduler-limit=10',
        ]),
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $checkPipes,
        null,
        $checkEnvironment
    );
    $checkStdout = is_resource($checkProcess) ? stream_get_contents($checkPipes[1]) : '';
    $checkStderr = is_resource($checkProcess) ? stream_get_contents($checkPipes[2]) : '';
    if (is_resource($checkProcess)) {
        fclose($checkPipes[0]); fclose($checkPipes[1]); fclose($checkPipes[2]);
    }
    $checkExit = is_resource($checkProcess) ? proc_close($checkProcess) : -1;
    $assertSame(0, $checkExit, 'CLI check conclui com sucesso');
    $assertSame('', $normalizePhpSubprocessStderr($checkStderr), 'CLI check não produz stderr inesperado');
    $assertTrue(str_contains($checkStdout, 'Recorrências vencidas: 1'), 'CLI check informa recorrências vencidas');
    $assertTrue(str_contains($checkStdout, 'Execuções potencialmente elegíveis: 1'), 'CLI check informa executions elegíveis');
    $assertSame($checkHashBefore, hash_file('sha256', $checkDatabasePath), 'CLI check preserva bytes do SQLite');
    $assertTrue(!is_file($checkLockPath), 'CLI check não cria o lock temporário configurado');
    $assertSame($previousLockEnvironment, getenv('ORCHESTRATOR_LOCK_PATH'), 'CLI check preserva o ambiente original do processo pai');

    // O stdout de run inclui todas as contagens operacionais seguras.
    $runDatabasePath = $temporaryDirectory . '/run-cli.sqlite';
    $runDatabase = new Database($runDatabasePath);
    $runConnection = $runDatabase->connect();
    (new MigrationRunner($runConnection, dirname(__DIR__) . '/database/migrations'))->migrate();
    $runConnection->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    unset($runConnection, $runDatabase);
    gc_collect_cycles();
    $runPipes = [];
    $runProcess = proc_open(
        $phpSubprocessCommand([
            dirname(__DIR__) . '/bin/orchestrator.php',
            'run',
            '--db-path=' . $runDatabasePath,
            '--hesk-path=/caminho/nao-carregado',
            '--lock-path=' . $temporaryDirectory . '/run-cli.lock',
        ]),
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $runPipes
    );
    $runStdout = is_resource($runProcess) ? stream_get_contents($runPipes[1]) : '';
    $runStderr = is_resource($runProcess) ? stream_get_contents($runPipes[2]) : '';
    if (is_resource($runProcess)) {
        fclose($runPipes[0]); fclose($runPipes[1]); fclose($runPipes[2]);
    }
    $runExit = is_resource($runProcess) ? proc_close($runProcess) : -1;
    $assertSame(0, $runExit, 'CLI run ociosa conclui sem carregar HESK');
    $assertSame('', $normalizePhpSubprocessStderr($runStderr), 'CLI run ociosa não produz stderr inesperado');
    $assertTrue(str_contains($runStdout, 'Ignoradas pelo scheduler: 0'), 'CLI imprime ignoradas pelo scheduler');
    $assertTrue(str_contains($runStdout, 'Erros do scheduler: 0'), 'CLI imprime erros do scheduler');
    $assertTrue(str_contains($runStdout, 'Recusadas por claim/lease: 0'), 'CLI imprime recusas de claim/lease');
} finally {
    unset($newOrchestrator, $factory, $scheduler, $leases, $items, $executions, $recurrences, $connection, $database);
    gc_collect_cycles();
    foreach (glob($temporaryDirectory . '/*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($temporaryDirectory);
}

$assertTrue(!is_dir($temporaryDirectory), 'Diretório temporário deve ser removido');
$projectErrorLogAfter = is_file($projectErrorLogPath)
    ? ['exists' => true, 'hash' => hash_file('sha256', $projectErrorLogPath)]
    : ['exists' => false, 'hash' => null];
$assertSame($projectErrorLogBefore, $projectErrorLogAfter, 'Subprocessos não devem criar ou alterar error_log no repositório');

if ($failures !== []) {
    fwrite(STDERR, "TESTES DEP-001D FALHARAM\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "TESTES DEP-001D OK ({$assertions} asserções)\n";
