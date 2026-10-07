<?php

declare(strict_types=1);

use TicketsRecorrentesHesk\Database;
use TicketsRecorrentesHesk\MigrationRunner;
use TicketsRecorrentesHesk\RecurrenceExecutionRepository;
use TicketsRecorrentesHesk\RecurrenceRepository;
use TicketsRecorrentesHesk\RecurrenceScheduleCalculator;
use TicketsRecorrentesHesk\Scheduler;
use TicketsRecorrentesHesk\SchedulerCliOptions;

require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/MigrationRunner.php';
require dirname(__DIR__) . '/src/RecurrenceValidator.php';
require dirname(__DIR__) . '/src/RecurrenceRepository.php';
require dirname(__DIR__) . '/src/RecurrenceExecutionRepository.php';
require dirname(__DIR__) . '/src/RecurrenceScheduleCalculator.php';
require dirname(__DIR__) . '/src/Scheduler.php';
require dirname(__DIR__) . '/src/SchedulerCliOptions.php';

$failures = [];
$assertions = 0;

$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$failures, &$assertions): void {
    $assertions++;

    if ($expected !== $actual) {
        $failures[] = sprintf(
            '%s: esperado %s, recebido %s',
            $message,
            var_export($expected, true),
            var_export($actual, true)
        );
    }
};

$assertTrue = static function (bool $condition, string $message) use (&$failures, &$assertions): void {
    $assertions++;

    if (!$condition) {
        $failures[] = $message;
    }
};

$assertThrows = static function (
    callable $callback,
    string $expectedClass,
    string $message,
) use (&$failures, &$assertions): void {
    $assertions++;

    try {
        $callback();
        $failures[] = "{$message}: nenhuma exceção foi lançada";
    } catch (Throwable $error) {
        if (!$error instanceof $expectedClass) {
            $failures[] = "{$message}: esperado {$expectedClass}, recebido " . $error::class;
        }
    }
};

$temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'tickets-recorrentes-sch-' . bin2hex(random_bytes(8));
$databasePath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'scheduler.sqlite';

if (!mkdir($temporaryDirectory, 0700, true) && !is_dir($temporaryDirectory)) {
    fwrite(STDERR, "Não foi possível criar o diretório temporário de testes.\n");
    exit(1);
}

$connection = null;
$database = null;

try {
    $database = new Database($databasePath);
    $connection = $database->connect();
    (new MigrationRunner($connection, dirname(__DIR__) . '/database/migrations'))->migrate();

    $recurrences = new RecurrenceRepository($connection);
    $executions = new RecurrenceExecutionRepository($connection);
    $calculator = new RecurrenceScheduleCalculator();
    $scheduler = new Scheduler($connection, $recurrences, $executions, $calculator);
    $now = new DateTimeImmutable('2026-10-02T16:00:00Z');

    $definition = [
        'name' => 'Teste do scheduler',
        'enabled' => true,
        'timezone' => 'America/Sao_Paulo',
        'interval_value' => 1,
        'interval_unit' => 'day',
        'next_run_at' => '2026-10-01T12:00:00Z',
        'quantity' => 3,
        'customer_id' => 100,
        'category_id' => 5,
        'priority_name' => 'Baixa',
        'status_id' => 0,
        'owner_id' => 4,
        'openedby_id' => 4,
        'subject' => '[TESTE] Scheduler',
        'message' => 'Teste isolado do scheduler.',
        'notify_customer' => false,
        'custom_fields' => ['custom15' => 'EXAMPLE_COMPANY'],
    ];

    $createRecurrence = static fn (array $changes): array => $recurrences->create(
        array_merge($definition, $changes)
    );

    // Cálculo civil e datas de borda.
    $assertSame(
        '2026-10-03T12:00:00Z',
        $calculator->calculate('2026-10-02T12:00:00Z', 'America/Sao_Paulo', 1, 'day'),
        'Intervalo diário deve funcionar'
    );
    $assertSame(
        '2026-10-09T12:00:00Z',
        $calculator->calculate('2026-10-02T12:00:00Z', 'America/Sao_Paulo', 1, 'week'),
        'Intervalo semanal deve funcionar'
    );
    $assertSame(
        '2027-02-28T12:00:00Z',
        $calculator->calculate('2027-01-31T12:00:00Z', 'America/Sao_Paulo', 1, 'month'),
        '31 de janeiro deve ajustar para o último dia de fevereiro'
    );
    $assertSame(
        '2027-04-30T12:00:00Z',
        $calculator->calculate('2027-03-31T12:00:00Z', 'America/Sao_Paulo', 1, 'month'),
        '31 de março deve ajustar para 30 de abril'
    );
    $assertSame(
        '2025-02-28T12:00:00Z',
        $calculator->calculate('2024-02-29T12:00:00Z', 'America/Sao_Paulo', 1, 'year'),
        '29 de fevereiro deve ajustar para 28 no ano não bissexto'
    );
    $assertSame(
        '2018-03-15T12:00:00Z',
        $calculator->calculate('2018-02-15T11:00:00Z', 'America/Sao_Paulo', 1, 'month'),
        'Mudança histórica de offset deve preservar 09:00 no horário local'
    );
    $localResult = (new DateTimeImmutable('2018-03-15T12:00:00Z'))
        ->setTimezone(new DateTimeZone('America/Sao_Paulo'));
    $assertSame('09:00:00', $localResult->format('H:i:s'), 'Hora, minuto e segundo locais devem ser preservados');

    // Seleção de vencidas e dry run.
    $future = $createRecurrence([
        'name' => 'Futura',
        'next_run_at' => '2026-10-03T12:00:00Z',
    ]);
    $disabled = $createRecurrence([
        'name' => 'Desabilitada',
        'enabled' => false,
        'next_run_at' => '2026-09-01T12:00:00Z',
    ]);
    $due = $createRecurrence([
        'name' => 'Vencida',
        'next_run_at' => '2026-10-01T12:00:00Z',
    ]);

    $dueRows = $recurrences->findDue('2026-10-02T16:00:00Z');
    $assertSame([(int) $due['id']], array_column($dueRows, 'id'), 'Somente a recorrência ativa e vencida deve ser selecionada');
    $assertTrue(!in_array($future['id'], array_column($dueRows, 'id'), true), 'Recorrência futura não deve ser selecionada');
    $assertTrue(!in_array($disabled['id'], array_column($dueRows, 'id'), true), 'Recorrência desabilitada não deve ser selecionada');

    $beforeCheck = $recurrences->findById((int) $due['id']);
    $check = $scheduler->check($now);
    $assertSame(1, $check['due_count'], 'Check deve informar uma recorrência vencida');
    $assertSame(1, count($check['candidates']), 'Check deve calcular uma candidata');
    $assertSame([], $executions->findByRecurrence((int) $due['id']), 'Check não deve criar execution');
    $assertSame($beforeCheck, $recurrences->findById((int) $due['id']), 'Check não deve alterar a recorrência');

    // Run normal e atomicidade do caminho de sucesso.
    $run = $scheduler->run($now);
    $assertSame(1, count($run['processed']), 'Run deve processar a recorrência vencida');
    $assertSame(0, count($run['errors']), 'Run normal não deve produzir erro');
    $dueExecutions = $executions->findByRecurrence((int) $due['id']);
    $assertSame(1, count($dueExecutions), 'Run deve criar uma execution');
    $assertSame('pending', $dueExecutions[0]['status'], 'Execution deve começar pending');
    $assertSame(3, $dueExecutions[0]['expected_count'], 'expected_count deve receber quantity');
    $assertSame(0, $dueExecutions[0]['created_count'], 'created_count deve começar em zero');
    $assertSame('2026-10-02T12:00:00Z', $recurrences->findById((int) $due['id'])['next_run_at'], 'Run deve avançar next_run_at');
    $recurrences->setEnabled((int) $due['id'], false);

    // Falha no update deve reverter também a execution.
    $rollbackRecurrence = $createRecurrence([
        'name' => 'Rollback forçado',
        'next_run_at' => '2026-09-10T12:00:00Z',
    ]);
    $rollbackId = (int) $rollbackRecurrence['id'];
    $connection->exec(
        "CREATE TRIGGER fail_scheduler_update
         BEFORE UPDATE OF next_run_at ON recurrences
         WHEN OLD.id = {$rollbackId}
         BEGIN
             SELECT RAISE(ABORT, 'forced scheduler rollback');
         END"
    );
    $rollbackRun = $scheduler->run($now);
    $assertSame(1, count($rollbackRun['errors']), 'Falha no avanço deve ser reportada');
    $assertSame([], $executions->findByRecurrence($rollbackId), 'Rollback não deve deixar execution criada');
    $assertSame(
        '2026-09-10T12:00:00Z',
        $recurrences->findById($rollbackId)['next_run_at'],
        'Rollback não deve avançar a recorrência'
    );
    $connection->exec('DROP TRIGGER fail_scheduler_update');
    $recurrences->setEnabled($rollbackId, false);

    // Duplicidade preexistente deve ser explícita e conservadora.
    $duplicateRecurrence = $createRecurrence([
        'name' => 'Duplicada',
        'next_run_at' => '2026-09-20T12:00:00Z',
    ]);
    $duplicateId = (int) $duplicateRecurrence['id'];
    $existingExecution = $executions->create([
        'recurrence_id' => $duplicateId,
        'scheduled_for' => '2026-09-20T12:00:00Z',
        'expected_count' => 3,
    ]);
    $duplicateRun = $scheduler->run($now);
    $assertSame(1, count($duplicateRun['skipped']), 'Execution preexistente deve ser ignorada claramente');
    $assertSame('execution_already_exists', $duplicateRun['skipped'][0]['reason'], 'Motivo da duplicidade deve ser estruturado');
    $assertSame($existingExecution['id'], $duplicateRun['skipped'][0]['execution_id'], 'Resultado deve identificar a execution existente');
    $assertSame(
        '2026-09-20T12:00:00Z',
        $recurrences->findById($duplicateId)['next_run_at'],
        'Duplicidade não deve avançar silenciosamente a recorrência'
    );
    $assertThrows(
        static fn () => $executions->create([
            'recurrence_id' => $duplicateId,
            'scheduled_for' => '2026-09-20T12:00:00Z',
            'expected_count' => 3,
        ]),
        PDOException::class,
        'A constraint UNIQUE deve continuar impedindo duplicação'
    );
    $recurrences->setEnabled($duplicateId, false);

    // Catch-up conservador: uma competência por recorrência em cada run.
    $catchUp = $createRecurrence([
        'name' => 'Catch-up mensal',
        'interval_unit' => 'month',
        'next_run_at' => '2026-01-15T12:00:00Z',
        'quantity' => 1,
    ]);
    $catchUpId = (int) $catchUp['id'];
    $firstCatchUp = $scheduler->run(new DateTimeImmutable('2026-04-20T12:00:00Z'));
    $assertSame(1, count($firstCatchUp['processed']), 'Primeiro run deve processar só uma competência antiga');
    $assertSame('2026-02-15T12:00:00Z', $recurrences->findById($catchUpId)['next_run_at'], 'Primeiro run deve avançar apenas até fevereiro');
    $assertSame(1, count($executions->findByRecurrence($catchUpId)), 'Primeiro run deve criar uma única execution de catch-up');
    $secondCatchUp = $scheduler->run(new DateTimeImmutable('2026-04-20T12:00:00Z'));
    $assertSame(1, count($secondCatchUp['processed']), 'Segundo run deve processar a próxima competência antiga');
    $assertSame('2026-03-15T12:00:00Z', $recurrences->findById($catchUpId)['next_run_at'], 'Segundo run deve avançar apenas até março');
    $assertSame(2, count($executions->findByRecurrence($catchUpId)), 'Segundo run deve totalizar duas executions distintas');
    $recurrences->setEnabled($catchUpId, false);

    // Limite global conta recorrências, não tickets.
    $limitedIds = [];

    for ($index = 1; $index <= 3; $index++) {
        $limited = $createRecurrence([
            'name' => "Limitada {$index}",
            'interval_unit' => 'year',
            'next_run_at' => '2026-09-01T12:00:00Z',
            'quantity' => 50,
        ]);
        $limitedIds[] = (int) $limited['id'];
    }

    $limitedRun = $scheduler->run($now, 2);
    $assertSame(2, count($limitedRun['processed']), 'Limit 2 deve processar somente duas recorrências');
    $assertSame(0, count($executions->findByRecurrence($limitedIds[2])), 'A terceira recorrência deve permanecer sem execution');
    $assertSame('2026-09-01T12:00:00Z', $recurrences->findById($limitedIds[2])['next_run_at'], 'A terceira recorrência não deve avançar');

    // Opções da CLI e limites de segurança.
    $help = SchedulerCliOptions::parse(['scheduler.php'], null);
    $assertSame('help', $help->command, 'Sem argumentos a CLI deve mostrar ajuda');
    $parsed = SchedulerCliOptions::parse(
        ['scheduler.php', 'check', '--db-path=C:\\tmp\\scheduler.sqlite', '--limit=1000'],
        'C:\\tmp\\from-env.sqlite'
    );
    $assertSame('check', $parsed->command, 'CLI deve aceitar check');
    $assertSame(1000, $parsed->limit, 'CLI deve aceitar o limite máximo');
    $assertSame('C:\\tmp\\scheduler.sqlite', $parsed->dbPath, '--db-path deve prevalecer sobre APP_DB_PATH');
    $assertSame(
        'C:\\tmp\\from-env.sqlite',
        SchedulerCliOptions::parse(['scheduler.php', 'run'], 'C:\\tmp\\from-env.sqlite')->dbPath,
        'Scheduler deve aceitar APP_DB_PATH'
    );
    $assertThrows(
        static fn () => SchedulerCliOptions::parse(['scheduler.php', 'run', '--limit=0'], null),
        InvalidArgumentException::class,
        'CLI deve bloquear limit zero'
    );
    $assertThrows(
        static fn () => SchedulerCliOptions::parse(['scheduler.php', 'run', '--limit=1001'], null),
        InvalidArgumentException::class,
        'CLI deve bloquear limit acima de 1000'
    );
} finally {
    unset($createRecurrence, $scheduler, $recurrences, $executions);
    $connection = null;
    $database = null;

    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm', $databasePath . '-journal'] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    @rmdir($temporaryDirectory);
}

$assertTrue(!is_dir($temporaryDirectory), 'O diretório temporário deve ser removido ao final');

if ($failures !== []) {
    fwrite(STDERR, "TESTES DO SCHEDULER FALHARAM\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "TESTES DO SCHEDULER OK ({$assertions} asserções)\n";
