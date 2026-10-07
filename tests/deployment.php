<?php

declare(strict_types=1);

use TicketsRecorrentesHesk\Database;
use TicketsRecorrentesHesk\DeploymentCliOptions;
use TicketsRecorrentesHesk\DeploymentDatabaseStatus;
use TicketsRecorrentesHesk\DeploymentPreflight;
use TicketsRecorrentesHesk\MigrationRunner;
use TicketsRecorrentesHesk\SqliteBackup;
use TicketsRecorrentesHesk\Web\AdminUrl;

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/MigrationRunner.php';
require_once dirname(__DIR__) . '/src/DeploymentCliOptions.php';
require_once dirname(__DIR__) . '/src/DeploymentPreflight.php';
require_once dirname(__DIR__) . '/src/DeploymentDatabaseStatus.php';
require_once dirname(__DIR__) . '/src/SqliteBackup.php';
require_once dirname(__DIR__) . '/src/Web/AdminUrl.php';

$failures = [];
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$assertions): void {
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
    }
};
$assertThrows = static function (callable $callback, string $message) use (&$failures, &$assertions): void {
    $assertions++;
    try {
        $callback();
        $failures[] = $message;
    } catch (Throwable) {
    }
};
$removeTree = static function (string $path) use (&$removeTree): void {
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $removeTree($path . '/' . $entry);
        }
    }
    rmdir($path);
};

$defaultUrl = new AdminUrl();
$assert($defaultUrl->to('index.php?page=recurrences') === 'index.php?page=recurrences', 'Base vazia deve preservar URLs locais relativas');
$assert($defaultUrl->cookiePath() === '/', 'Base vazia deve manter cookie no path raiz');
$subdirectoryUrl = new AdminUrl('/recurrent/');
$assert($subdirectoryUrl->basePath() === '/recurrent', 'Base path deve remover barra final');
$assert($subdirectoryUrl->to('assets/css/admin.css') === '/recurrent/assets/css/admin.css', 'Asset deve receber base path');
$assert($subdirectoryUrl->to('index.php?page=recurrence-form&id=1') === '/recurrent/index.php?page=recurrence-form&id=1', 'Formulário e query devem receber base path');
$assert($subdirectoryUrl->cookiePath() === '/recurrent/', 'Cookie próprio deve ficar limitado ao painel');
$assertThrows(static fn () => new AdminUrl('recurrent'), 'Base relativa deve ser recusada');
$assertThrows(static fn () => new AdminUrl('/recurrent/../storage'), 'Travessia no base path deve ser recusada');
$assertThrows(static fn () => $subdirectoryUrl->to('/admin/'), 'URL absoluta não deve ser aceita pelo gerador interno');

$temporary = sys_get_temp_dir() . '/tickets-deployment-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700, true);
mkdir($temporary . '/hesk/inc', 0700, true);
mkdir($temporary . '/logs', 0700, true);
file_put_contents($temporary . '/hesk/hesk_settings.inc.php', "<?php\n\$hesk_settings['hesk_version'] = '3.7.12';\n");
foreach (['common.inc.php', 'admin_functions.inc.php', 'email_functions.inc.php', 'posting_functions.inc.php'] as $file) {
    file_put_contents($temporary . '/hesk/inc/' . $file, "<?php\n");
}
symlink(dirname(__DIR__) . '/public', $temporary . '/recurrent');

$databasePath = $temporary . '/app.sqlite';
$connection = (new Database($databasePath))->connect();
$migrationFixture = $temporary . '/migrations';
mkdir($migrationFixture, 0700);
copy(dirname(__DIR__) . '/database/migrations/001_initial_schema.sql', $migrationFixture . '/001_initial_schema.sql');
(new MigrationRunner($connection, $migrationFixture))->migrate();
$connection = null;

$environment = [
    'APP_DB_PATH' => $databasePath,
    'HESK_PATH' => $temporary . '/hesk',
    'ADMIN_UI_PUBLIC_PATH' => $temporary . '/recurrent',
    'ADMIN_UI_BASE_PATH' => '/recurrent',
    'APP_LOG_PATH' => $temporary . '/logs',
    'ADMIN_UI_ENABLED' => '0',
    'ADMIN_UI_WRITE_ENABLED' => '0',
];
foreach ($environment as $name => $value) {
    putenv($name . '=' . $value);
}

$options = DeploymentCliOptions::parse(['deployment.php', 'check']);
$assert($options->dbPath === $databasePath && $options->heskPath === $temporary . '/hesk', 'CLI deve usar configuração do ambiente');
$assert($options->basePath === '/recurrent' && $options->publicPath === $temporary . '/recurrent', 'CLI deve carregar configuração Web');
$statusOptions = DeploymentCliOptions::parse(['deployment.php', 'database-status', '--db-path=' . $databasePath]);
$assert($statusOptions->command === 'database-status' && $statusOptions->dbPath === $databasePath, 'CLI deve reconhecer database-status');
$backupOptions = DeploymentCliOptions::parse(['deployment.php', 'backup', '--db-path=' . $databasePath, '--backup-path=' . $temporary . '/explicit.sqlite']);
$assert($backupOptions->command === 'backup' && $backupOptions->backupPath === $temporary . '/explicit.sqlite', 'CLI deve exigir e preservar destino explícito do backup');
$assertThrows(
    static fn () => DeploymentCliOptions::parse(['deployment.php', 'backup', '--db-path=' . $databasePath]),
    'CLI deve recusar backup sem --backup-path',
);

$beforeHash = hash_file('sha256', $databasePath);
$beforeFiles = scandir($temporary);
$results = (new DeploymentPreflight())->run($options);
$afterHash = hash_file('sha256', $databasePath);
$afterFiles = scandir($temporary);
$byCheck = [];
foreach ($results as $result) {
    $byCheck[$result['check']][] = $result;
}

$assert($beforeHash === $afterHash, 'Preflight não deve alterar o conteúdo do banco');
$assert(array_diff($afterFiles ?: [], $beforeFiles ?: []) === [], 'Preflight não deve criar artefatos');
$assert(($byCheck['hesk'][0]['status'] ?? null) === 'OK', 'Preflight deve reconhecer fixture HESK 3.7.12');
$assert(($byCheck['base_path'][0]['status'] ?? null) === 'OK', 'Preflight deve aceitar /recurrent');
$assert(($byCheck['public_path'][0]['status'] ?? null) === 'OK', 'Symlink público para public/ deve ser aceito');
$assert(($byCheck['migration_files'][0]['status'] ?? null) === 'OK', 'Preflight deve exigir migrations 001–004');
$assert(($byCheck['migration_state'][0]['status'] ?? null) === 'AVISO', 'Migrations 002–004 pendentes devem ser aviso preparatório');
$assert(str_contains($byCheck['migration_state'][0]['message'] ?? '', '002_execution_leases'), 'Aviso deve identificar migrations pendentes');
$assert(($byCheck['sqlite_integrity'][0]['status'] ?? null) === 'OK', 'Preflight deve validar integridade SQLite em modo somente leitura');
$assert(($byCheck['sqlite_journal_mode'][0]['status'] ?? null) === 'OK'
    && str_contains($byCheck['sqlite_journal_mode'][0]['message'] ?? '', 'journal_mode=wal'), 'Header 2/2 deve confirmar WAL sem usar PRAGMA journal_mode');
$assert((new Database($databasePath))->persistedJournalMode() === 'wal', 'Leitura passiva do header deve reconhecer WAL');

$databaseStatus = new DeploymentDatabaseStatus();
$statusFilesBefore = glob($databasePath . '*') ?: [];
sort($statusFilesBefore);
$statusHashBefore = hash_file('sha256', $databasePath);
$status001 = $databaseStatus->inspect($databasePath, dirname(__DIR__) . '/database/migrations');
$statusFilesAfter = glob($databasePath . '*') ?: [];
sort($statusFilesAfter);
$assert($status001['path'] === realpath($databasePath), 'Status deve apresentar caminho resolvido');
$assert($status001['sha256'] === $statusHashBefore && $status001['size'] === filesize($databasePath), 'Status deve apresentar SHA-256 e tamanho corretos');
$assert($status001['journal_mode'] === 'wal' && $status001['integrity'] === ['ok'], 'Status deve confirmar WAL e integridade');
$assert($status001['foreign_key_failures'] === 0, 'Status deve confirmar foreign keys sem falhas');
$assert($status001['migrations']['applied'] === ['001_initial_schema'], 'Status deve identificar banco somente em 001');
$assert($status001['migrations']['pending'] === ['002_execution_leases', '003_execution_items', '004_execution_error_codes'], 'Status deve listar 002–004 pendentes');
$assert($status001['schema'] === [
    'base' => 'OK',
    'migration_002' => 'PENDENTE',
    'migration_003' => 'PENDENTE',
    'migration_004' => 'PENDENTE',
], 'Schema 001 válido deve aceitar 002–004 fisicamente pendentes');
$assert(!DeploymentDatabaseStatus::hasBlockers($status001), 'Estado pré-migração 001 coerente não deve ser bloqueado por migrations pendentes');
$assert($status001['counts']['recurrences'] === 0 && $status001['counts']['recurrence_executions'] === 0, 'Status deve contar tabelas existentes sem ler payloads');
$assert($status001['counts']['recurrence_execution_items'] === null, 'Ausência da tabela 003 deve ser estado esperado');
$assert($statusFilesAfter === $statusFilesBefore && hash_file('sha256', $databasePath) === $statusHashBefore, 'Status imutável não deve alterar banco nem criar auxiliares');

$fullDatabasePath = $temporary . '/full.sqlite';
$fullDatabase = new Database($fullDatabasePath);
$fullConnection = $fullDatabase->connect();
(new MigrationRunner($fullConnection, dirname(__DIR__) . '/database/migrations'))->migrate();
$fullConnection = null;
$fullDatabase = null;
$statusFull = $databaseStatus->inspect($fullDatabasePath, dirname(__DIR__) . '/database/migrations');
$assert($statusFull['migrations']['applied'] === ['001_initial_schema', '002_execution_leases', '003_execution_items', '004_execution_error_codes'], 'Status deve reconhecer 001–004 aplicadas');
$assert($statusFull['migrations']['pending'] === [] && $statusFull['counts']['recurrence_execution_items'] === 0, 'Status deve reconhecer tabela 003 presente e banco atualizado');
$assert($statusFull['schema'] === [
    'base' => 'OK',
    'migration_002' => 'OK',
    'migration_003' => 'OK',
    'migration_004' => 'OK',
], 'Schema 001–004 correto deve corresponder às migrations aplicadas');
$assert(!DeploymentDatabaseStatus::hasBlockers($statusFull), 'Banco íntegro e atualizado não deve ter bloqueios de status');

$recordMigration = static function (string $path, string $version): void {
    $checksum = hash_file('sha256', dirname(__DIR__) . '/database/migrations/' . $version . '.sql');
    if ($checksum === false) {
        throw new RuntimeException('Fixture não conseguiu calcular checksum.');
    }
    $database = new Database($path);
    $connection = $database->connectExistingWritable();
    $statement = $connection->prepare(
        'INSERT INTO schema_migrations (version, checksum, applied_at) VALUES (:version, :checksum, :applied_at)'
    );
    $statement->execute([
        'version' => $version,
        'checksum' => $checksum,
        'applied_at' => '2026-10-06T00:00:00Z',
    ]);
    $connection = null;
    $database = null;
};
$changeSchema = static function (string $path, string $sql): void {
    $database = new Database($path);
    $connection = $database->connectExistingWritable();
    $connection->exec($sql);
    $connection = null;
    $database = null;
};

$baseMissingPath = $temporary . '/base-missing.sqlite';
copy($databasePath, $baseMissingPath);
$changeSchema($baseMissingPath, 'PRAGMA foreign_keys = OFF; DROP TABLE recurrence_executions; DROP TABLE recurrences;');
$baseMissingStatus = $databaseStatus->inspect($baseMissingPath, dirname(__DIR__) . '/database/migrations');
$assert($baseMissingStatus['schema']['base'] === 'INCONSISTENTE' && DeploymentDatabaseStatus::hasBlockers($baseMissingStatus), 'Tabelas base 001 ausentes devem bloquear');

$applied002MissingPath = $temporary . '/applied-002-missing.sqlite';
copy($databasePath, $applied002MissingPath);
$recordMigration($applied002MissingPath, '002_execution_leases');
$applied002Missing = $databaseStatus->inspect($applied002MissingPath, dirname(__DIR__) . '/database/migrations');
$assert($applied002Missing['schema']['migration_002'] === 'INCONSISTENTE' && DeploymentDatabaseStatus::hasBlockers($applied002Missing), '002 aplicada sem colunas e índice deve bloquear');

$pending002PartialPath = $temporary . '/pending-002-partial.sqlite';
copy($databasePath, $pending002PartialPath);
$changeSchema($pending002PartialPath, 'ALTER TABLE recurrence_executions ADD COLUMN attempt_count INTEGER NOT NULL DEFAULT 0');
$pending002Partial = $databaseStatus->inspect($pending002PartialPath, dirname(__DIR__) . '/database/migrations');
$assert($pending002Partial['schema']['migration_002'] === 'INCONSISTENTE' && DeploymentDatabaseStatus::hasBlockers($pending002Partial), '002 pendente com schema parcial deve bloquear');

$applied003MissingPath = $temporary . '/applied-003-missing.sqlite';
copy($databasePath, $applied003MissingPath);
$recordMigration($applied003MissingPath, '003_execution_items');
$applied003Missing = $databaseStatus->inspect($applied003MissingPath, dirname(__DIR__) . '/database/migrations');
$assert($applied003Missing['schema']['migration_003'] === 'INCONSISTENTE' && DeploymentDatabaseStatus::hasBlockers($applied003Missing), '003 aplicada sem tabela e índice deve bloquear');

$pending003PresentPath = $temporary . '/pending-003-present.sqlite';
copy($databasePath, $pending003PresentPath);
$migration003 = file_get_contents(dirname(__DIR__) . '/database/migrations/003_execution_items.sql');
$changeSchema($pending003PresentPath, is_string($migration003) ? $migration003 : '');
$pending003Present = $databaseStatus->inspect($pending003PresentPath, dirname(__DIR__) . '/database/migrations');
$assert($pending003Present['schema']['migration_003'] === 'INCONSISTENTE' && DeploymentDatabaseStatus::hasBlockers($pending003Present), '003 pendente com tabela já presente deve bloquear');

$through003Path = $temporary . '/through-003.sqlite';
$through003Migrations = $temporary . '/migrations-through-003';
mkdir($through003Migrations, 0700);
foreach (['001_initial_schema', '002_execution_leases', '003_execution_items'] as $version) {
    copy(dirname(__DIR__) . '/database/migrations/' . $version . '.sql', $through003Migrations . '/' . $version . '.sql');
}
$through003Database = new Database($through003Path);
$through003Connection = $through003Database->connect();
(new MigrationRunner($through003Connection, $through003Migrations))->migrate();
$through003Connection = null;
$through003Database = null;
$recordMigration($through003Path, '004_execution_error_codes');
$applied004Missing = $databaseStatus->inspect($through003Path, dirname(__DIR__) . '/database/migrations');
$assert($applied004Missing['schema']['migration_004'] === 'INCONSISTENTE' && DeploymentDatabaseStatus::hasBlockers($applied004Missing), '004 aplicada sem error_code nas duas tabelas deve bloquear');

$pending004PartialPath = $temporary . '/pending-004-partial.sqlite';
copy($through003Path, $pending004PartialPath);
$pending004Database = new Database($pending004PartialPath);
$pending004Connection = $pending004Database->connectExistingWritable();
$pending004Connection->exec("DELETE FROM schema_migrations WHERE version = '004_execution_error_codes'");
$pending004Connection->exec('ALTER TABLE recurrence_executions ADD COLUMN error_code TEXT NULL');
$pending004Connection = null;
$pending004Database = null;
$pending004Partial = $databaseStatus->inspect($pending004PartialPath, dirname(__DIR__) . '/database/migrations');
$assert($pending004Partial['schema']['migration_004'] === 'INCONSISTENTE' && DeploymentDatabaseStatus::hasBlockers($pending004Partial), '004 pendente com schema parcial deve bloquear');

$headerHandle = fopen($databasePath, 'c+b');
fseek($headerHandle, 18);
fwrite($headerHandle, "\x01\x01");
fclose($headerHandle);
$rollbackResults = (new DeploymentPreflight())->run($options);
$assert((new Database($databasePath))->persistedJournalMode() === 'rollback', 'Header 1/1 deve representar rollback journal');
$assert(count(array_filter($rollbackResults, static fn (array $item): bool => $item['check'] === 'sqlite_journal_mode' && $item['status'] === 'BLOQUEIO')) === 1, 'Rollback journal deve bloquear porque WAL é obrigatório');

$headerHandle = fopen($databasePath, 'c+b');
fseek($headerHandle, 18);
fwrite($headerHandle, "\x03\x03");
fclose($headerHandle);
$assertThrows(static fn () => (new Database($databasePath))->persistedJournalMode(), 'Header com versões desconhecidas deve falhar fechado');
$headerHandle = fopen($databasePath, 'c+b');
fseek($headerHandle, 18);
fwrite($headerHandle, "\x02\x02");
fclose($headerHandle);

$connection = (new Database($databasePath))->connectExistingWritable();
$connection->exec("UPDATE schema_migrations SET checksum = 'divergente' WHERE version = '001_initial_schema'");
$connection = null;
$divergent = (new DeploymentPreflight())->run($options);
$assert(DeploymentPreflight::hasBlockers($divergent), 'Checksum divergente deve bloquear a implantação');
$assert(count(array_filter($divergent, static fn (array $item): bool => $item['check'] === 'migration_state' && $item['status'] === 'BLOQUEIO')) === 1, 'Divergência deve ser classificada como bloqueio de migration');
$divergentStatus = $databaseStatus->inspect($databasePath, dirname(__DIR__) . '/database/migrations');
$assert($divergentStatus['migrations']['divergent'] === ['001_initial_schema'], 'Status deve apresentar migration divergente');
$assert(DeploymentDatabaseStatus::hasBlockers($divergentStatus), 'Migration divergente deve bloquear avanço operacional');

file_put_contents($databasePath . '-wal', 'wal pendente');
$walBlocked = (new DeploymentPreflight())->run($options);
$assert(count(array_filter($walBlocked, static fn (array $item): bool => $item['check'] === 'sqlite' && $item['status'] === 'BLOQUEIO')) === 1, 'WAL pendente deve bloquear fotografia imutável');
$assert(file_get_contents($databasePath . '-wal') === 'wal pendente', 'Preflight não deve alterar WAL pendente');
unlink($databasePath . '-wal');

$backupService = new SqliteBackup();
$sourceHashBeforeBackup = hash_file('sha256', $fullDatabasePath);
$backupPath = $temporary . '/valid-backup.sqlite';
$backup = $backupService->create(
    $fullDatabasePath,
    $backupPath,
    dirname(__DIR__),
    $temporary . '/recurrent',
    $temporary . '/hesk',
);
$assert(is_file($backupPath) && $backup['backup_path'] === $backupPath, 'Backup deve criar somente o destino explícito');
$assert(hash_file('sha256', $fullDatabasePath) === $sourceHashBeforeBackup && $backup['source_sha256'] === $sourceHashBeforeBackup, 'Backup deve preservar integralmente a origem');
$assert($backup['integrity'] === 'ok' && $backup['foreign_key_failures'] === 0, 'Backup deve ser validado por integridade e foreign keys');
$assert((fileperms($backupPath) & 0777) === 0600 && $backup['mode'] === '0600', 'Backup deve terminar com permissão 0600');
$assert((glob($backupPath . '*') ?: []) === [$backupPath], 'Backup concluído deve ser um único artefato sem WAL, SHM ou journal');
$backupStatus = $databaseStatus->inspect($backupPath, dirname(__DIR__) . '/database/migrations');
$assert($backupStatus['migrations']['pending'] === [] && $backupStatus['integrity'] === ['ok'], 'Snapshot deve conter schema e dados válidos da origem');
$assertThrows(
    static fn () => $backupService->create($fullDatabasePath, $backupPath, dirname(__DIR__)),
    'Destino existente deve ser recusado sem sobrescrita',
);

mkdir($temporary . '/public-root', 0700);
$publicDestination = $temporary . '/public-root/forbidden.sqlite';
$assertThrows(
    static fn () => $backupService->create($fullDatabasePath, $publicDestination, dirname(__DIR__), $temporary . '/public-root'),
    'Destino dentro do ponto público deve ser recusado',
);
$assert(!file_exists($publicDestination), 'Destino público recusado não deve deixar arquivo');
$assertThrows(
    static fn () => $backupService->create($fullDatabasePath, 'relative-backup.sqlite', dirname(__DIR__)),
    'Destino relativo deve ser recusado',
);

$lateWalBackup = new class extends SqliteBackup {
    protected function assertFinalSourceState(string $source, string $walPath, string $expectedHash): string
    {
        file_put_contents($walPath, 'wal surgido na etapa final');
        return parent::assertFinalSourceState($source, $walPath, $expectedHash);
    }
};
$lateWalBackupPath = $temporary . '/late-wal-refused.sqlite';
$assertThrows(
    static fn () => $lateWalBackup->create($fullDatabasePath, $lateWalBackupPath, dirname(__DIR__)),
    'WAL surgido na verificação final deve rejeitar o snapshot',
);
$assert((glob($lateWalBackupPath . '*') ?: []) === [] && file_get_contents($fullDatabasePath . '-wal') === 'wal surgido na etapa final', 'Rejeição final por WAL deve remover backup e preservar evidência na origem');
unlink($fullDatabasePath . '-wal');

file_put_contents($fullDatabasePath . '-wal', 'wal pendente');
$walBackupPath = $temporary . '/wal-refused.sqlite';
$assertThrows(
    static fn () => $backupService->create($fullDatabasePath, $walBackupPath, dirname(__DIR__)),
    'WAL não vazio deve recusar backup sem checkpoint automático',
);
$assert(!file_exists($walBackupPath) && file_get_contents($fullDatabasePath . '-wal') === 'wal pendente', 'Recusa por WAL deve preservar origem e não criar backup');
unlink($fullDatabasePath . '-wal');

$invalidForeignKeyPath = $temporary . '/invalid-foreign-key.sqlite';
$invalidDatabase = new Database($invalidForeignKeyPath);
$invalidConnection = $invalidDatabase->connect();
(new MigrationRunner($invalidConnection, dirname(__DIR__) . '/database/migrations'))->migrate();
$invalidConnection->exec('PRAGMA foreign_keys = OFF');
$invalidConnection->exec(
    "INSERT INTO recurrence_execution_items
        (execution_id, item_index, status, creation_attempts, created_at, updated_at)
     VALUES (999, 1, 'pending', 0, '2026-10-06T00:00:00Z', '2026-10-06T00:00:00Z')"
);
$invalidConnection = null;
$invalidDatabase = null;
$incompletePath = $temporary . '/must-be-removed.sqlite';
$assertThrows(
    static fn () => $backupService->create($invalidForeignKeyPath, $incompletePath, dirname(__DIR__)),
    'Backup com foreign key inválida deve falhar fechado',
);
$assert((glob($incompletePath . '*') ?: []) === [], 'Falha de validação deve remover backup incompleto e auxiliares');

$invalidBaseOptions = DeploymentCliOptions::parse(['deployment.php', 'check', '--base-path=/recurrent/../storage']);
$invalidBase = (new DeploymentPreflight())->run($invalidBaseOptions);
$assert(count(array_filter($invalidBase, static fn (array $item): bool => $item['check'] === 'base_path' && $item['status'] === 'BLOQUEIO')) === 1, 'Base path inseguro deve bloquear');

foreach (array_keys($environment) as $name) {
    putenv($name);
}
$removeTree($temporary);

if ($failures !== []) {
    fwrite(STDERR, "Falhas nos testes de deployment:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Testes de deployment concluídos: {$assertions} asserções.\n");
