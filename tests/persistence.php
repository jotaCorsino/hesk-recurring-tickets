<?php

declare(strict_types=1);

use TicketsRecorrentesHesk\Database;
use TicketsRecorrentesHesk\MigrationRunner;
use TicketsRecorrentesHesk\RecurrenceCliOptions;
use TicketsRecorrentesHesk\RecurrenceExecutionRepository;
use TicketsRecorrentesHesk\RecurrenceRepository;

require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/MigrationRunner.php';
require dirname(__DIR__) . '/src/RecurrenceValidator.php';
require dirname(__DIR__) . '/src/RecurrenceRepository.php';
require dirname(__DIR__) . '/src/RecurrenceExecutionRepository.php';
require dirname(__DIR__) . '/src/RecurrenceCliOptions.php';

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
    ?string $expectedMessage = null,
) use (&$failures, &$assertions): void {
    $assertions++;

    try {
        $callback();
        $failures[] = "{$message}: nenhuma exceção foi lançada";
    } catch (Throwable $error) {
        if (!$error instanceof $expectedClass) {
            $failures[] = "{$message}: esperado {$expectedClass}, recebido " . $error::class;
            return;
        }

        if ($expectedMessage !== null && !str_contains($error->getMessage(), $expectedMessage)) {
            $failures[] = "{$message}: mensagem inesperada: {$error->getMessage()}";
        }
    }
};

$temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'tickets-recorrentes-cfg-' . bin2hex(random_bytes(8));
$databasePath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'test.sqlite';

if (!mkdir($temporaryDirectory, 0700, true) && !is_dir($temporaryDirectory)) {
    fwrite(STDERR, "Não foi possível criar o diretório temporário de testes.\n");
    exit(1);
}

$connection = null;
$database = null;

try {
    $database = new Database($databasePath);
    $connection = $database->connect();
    $diagnostics = $database->diagnostics();

    $assertTrue(is_file($databasePath), 'A conexão deve criar o banco no caminho temporário');
    $assertSame(1, $diagnostics['foreign_keys'], 'As chaves estrangeiras devem estar habilitadas');
    $assertSame('wal', $diagnostics['journal_mode'], 'O banco deve usar journal_mode WAL');

    $migrations = new MigrationRunner($connection, dirname(__DIR__) . '/database/migrations');
    $assertSame(
        ['001_initial_schema', '002_execution_leases', '003_execution_items', '004_execution_error_codes'],
        $migrations->migrate(),
        'A primeira execução deve aplicar as migrations disponíveis'
    );
    $assertSame([], $migrations->migrate(), 'A segunda execução das migrations deve ser idempotente');
    $migrations->assertUpToDate();

    $assertSame(
        4,
        (int) $connection->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn(),
        'As migrations devem ser registradas uma única vez cada'
    );

    $tables = $connection->query(
        "SELECT name FROM sqlite_master
         WHERE type = 'table' AND name IN (
            'recurrences', 'recurrence_executions', 'recurrence_execution_items'
         )
         ORDER BY name"
    )->fetchAll(PDO::FETCH_COLUMN);
    $assertSame(
        ['recurrence_execution_items', 'recurrence_executions', 'recurrences'],
        $tables,
        'As três tabelas de domínio devem existir'
    );

    $definition = [
        'name' => 'Preventiva trimestral - Workstations',
        'enabled' => true,
        'timezone' => 'America/Sao_Paulo',
        'interval_value' => 3,
        'interval_unit' => 'month',
        'next_run_at' => '2027-01-15T09:00:00-03:00',
        'quantity' => 1,
        'customer_id' => 100,
        'category_id' => 5,
        'priority_name' => 'Baixa',
        'status_id' => 0,
        'owner_id' => 4,
        'openedby_id' => 4,
        'subject' => '[PREVENTIVA] Manutenção de workstation',
        'message' => 'Executar a manutenção preventiva conforme checklist vigente.',
        'notify_customer' => false,
        'custom_fields' => [
            'custom7' => 'Presencial',
            'custom15' => 'EXAMPLE_COMPANY',
        ],
    ];

    $recurrences = new RecurrenceRepository($connection);
    $created = $recurrences->create($definition);

    $assertSame(1, $created['id'], 'A primeira recorrência deve receber ID 1');
    $assertSame('2027-01-15T12:00:00Z', $created['next_run_at'], 'next_run_at deve ser persistido em UTC');
    $assertSame($definition['custom_fields'], $created['custom_fields'], 'Custom fields devem sobreviver ao JSON round-trip');
    $assertSame($created, $recurrences->findById(1), 'findById deve reler a recorrência criada');
    $assertSame([$created], $recurrences->findAll(), 'findAll deve listar a recorrência criada');

    $definitionWithDefaults = $definition;
    unset(
        $definitionWithDefaults['enabled'],
        $definitionWithDefaults['notify_customer'],
        $definitionWithDefaults['custom_fields']
    );
    $definitionWithDefaults['name'] = 'Recorrência com padrões seguros';
    $withDefaults = $recurrences->create($definitionWithDefaults);
    $assertSame(true, $withDefaults['enabled'], 'enabled deve usar true como padrão');
    $assertSame(false, $withDefaults['notify_customer'], 'notify_customer deve usar false como padrão');
    $assertSame([], $withDefaults['custom_fields'], 'custom_fields deve aceitar um mapa vazio');

    $rawCustomFields = $connection->query(
        'SELECT custom_fields_json FROM recurrences WHERE id = 2'
    )->fetchColumn();
    $assertSame('{}', $rawCustomFields, 'O mapa vazio deve ser persistido como objeto JSON');

    $updated = $recurrences->update(1, [
        'name' => 'Preventiva semestral - Workstations',
        'interval_value' => 6,
        'custom_fields' => ['custom16' => 'PAT-001'],
    ]);
    $assertSame('Preventiva semestral - Workstations', $updated['name'] ?? null, 'update deve alterar o nome');
    $assertSame(6, $updated['interval_value'] ?? null, 'update deve alterar o intervalo');
    $assertSame(['custom16' => 'PAT-001'], $updated['custom_fields'] ?? null, 'update deve substituir custom fields');

    $disabled = $recurrences->setEnabled(1, false);
    $assertSame(false, $disabled['enabled'] ?? null, 'disable deve desabilitar a recorrência');
    $enabled = $recurrences->setEnabled(1, true);
    $assertSame(true, $enabled['enabled'] ?? null, 'enable deve reabilitar a recorrência');
    $assertSame(null, $recurrences->findById(999), 'findById deve retornar null para ID inexistente');
    $assertSame(null, $recurrences->update(999, ['name' => 'Inexistente']), 'update deve retornar null para ID inexistente');
    $assertSame(null, $recurrences->setEnabled(999, false), 'setEnabled deve retornar null para ID inexistente');

    foreach ([
        'timezone inválido' => ['timezone' => 'Brasil/Sao_Paulo'],
        'intervalo zero' => ['interval_value' => 0],
        'unidade inválida' => ['interval_unit' => 'hour'],
        'quantidade zero' => ['quantity' => 0],
        'data sem timezone' => ['next_run_at' => '2027-01-15T09:00:00'],
        'data de calendário inválida' => ['next_run_at' => '2027-02-31T09:00:00-03:00'],
        'custom field inválido' => ['custom_fields' => ['cliente' => 'EXAMPLE_COMPANY']],
        'valor de custom field inválido' => ['custom_fields' => ['custom7' => 7]],
    ] as $scenario => $change) {
        $assertThrows(
            static fn () => $recurrences->create(array_merge($definition, $change)),
            DomainException::class,
            "Validação deve rejeitar {$scenario}"
        );
    }

    $executions = new RecurrenceExecutionRepository($connection);
    $execution = $executions->create([
        'recurrence_id' => 1,
        'scheduled_for' => '2027-07-15T09:00:00-03:00',
        'status' => 'pending',
        'expected_count' => 1,
        'created_count' => 0,
    ]);

    $assertSame(1, $execution['id'], 'A primeira execução deve receber ID 1');
    $assertSame('2027-07-15T12:00:00Z', $execution['scheduled_for'], 'scheduled_for deve ser persistido em UTC');
    $assertSame(0, $execution['attempt_count'], 'Uma execution nova deve começar sem tentativas');
    $assertSame(null, $execution['lease_owner'], 'Uma execution nova deve começar sem owner de lease');
    $assertSame(null, $execution['lease_expires_at'], 'Uma execution nova deve começar sem expiração de lease');
    $assertSame(null, $execution['last_attempt_at'], 'Uma execution nova deve começar sem tentativa registrada');
    $assertTrue(!array_key_exists('lease_token', $execution), 'Consultas genéricas não devem expor lease_token');
    $assertSame($execution, $executions->findById(1), 'A execução deve ser consultável por ID');
    $assertSame([$execution], $executions->findByRecurrence(1), 'A execução deve ser consultável por recorrência');

    $assertThrows(
        static fn () => $executions->create([
            'recurrence_id' => 1,
            'scheduled_for' => '2027-07-15T12:00:00Z',
            'status' => 'pending',
            'expected_count' => 1,
        ]),
        PDOException::class,
        'A restrição estrutural deve impedir execução duplicada'
    );

    $assertThrows(
        static fn () => $executions->create([
            'recurrence_id' => 999,
            'scheduled_for' => '2027-08-15T12:00:00Z',
            'status' => 'pending',
            'expected_count' => 1,
        ]),
        PDOException::class,
        'A chave estrangeira deve rejeitar recorrência inexistente'
    );

    $assertThrows(
        static fn () => $connection->exec('DELETE FROM recurrences WHERE id = 1'),
        PDOException::class,
        'ON DELETE RESTRICT deve preservar recorrências com histórico'
    );

    $cli = RecurrenceCliOptions::parse(
        ['recurrence.php', 'list', '--db-path=C:\\tmp\\recurrences.sqlite'],
        'C:\\tmp\\from-env.sqlite'
    );
    $assertSame('C:\\tmp\\recurrences.sqlite', $cli->dbPath, '--db-path deve prevalecer sobre APP_DB_PATH');
    $assertSame(
        'C:\\tmp\\from-env.sqlite',
        RecurrenceCliOptions::parse(['recurrence.php', 'list'], 'C:\\tmp\\from-env.sqlite')->dbPath,
        'APP_DB_PATH deve ser aceito'
    );

    $assertThrows(
        static fn () => RecurrenceCliOptions::parse(['recurrence.php', 'show', '--id=0'], null),
        InvalidArgumentException::class,
        'A CLI deve rejeitar IDs não positivos'
    );
} finally {
    $connection = null;
    $database = null;

    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm', $databasePath . '-journal'] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    @rmdir($temporaryDirectory);
}

if ($failures !== []) {
    fwrite(STDERR, "TESTES DE PERSISTÊNCIA FALHARAM\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "TESTES DE PERSISTÊNCIA OK ({$assertions} asserções)\n";
