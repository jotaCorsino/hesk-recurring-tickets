<?php

declare(strict_types=1);

use TicketsRecorrentesHesk\Web\AdminUi;

require_once dirname(__DIR__) . '/src/Web/AdminUi.php';
require_once dirname(__DIR__) . '/src/Web/AdminUrl.php';
require_once dirname(__DIR__) . '/src/Web/AdminAuthenticator.php';
require_once dirname(__DIR__) . '/src/Web/AuthenticatedStaff.php';
require_once dirname(__DIR__) . '/src/Web/HeskStaffRuntime.php';
require_once dirname(__DIR__) . '/src/Web/HeskStaffAuthenticator.php';
require_once dirname(__DIR__) . '/src/Web/NativeHeskStaffRuntime.php';
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/MigrationRunner.php';
require_once dirname(__DIR__) . '/src/RecurrenceValidator.php';
require_once dirname(__DIR__) . '/src/RecurrenceRepository.php';
require_once dirname(__DIR__) . '/src/OperationalErrorCatalog.php';
require_once dirname(__DIR__) . '/src/ImmediateTransaction.php';
require_once dirname(__DIR__) . '/src/HeskCatalogProvider.php';
require_once dirname(__DIR__) . '/src/HeskReferenceData.php';
require_once dirname(__DIR__) . '/src/HeskReferenceValidationException.php';
require_once dirname(__DIR__) . '/src/HeskRecurrenceValidator.php';
require_once dirname(__DIR__) . '/src/NativeHeskCatalogProvider.php';
require_once dirname(__DIR__) . '/src/Web/AdminDataProvider.php';
require_once dirname(__DIR__) . '/src/Web/AdminRecurrenceWriter.php';
require_once dirname(__DIR__) . '/src/Web/AdminSession.php';
require_once dirname(__DIR__) . '/src/Web/HeskCatalogViewModel.php';
require_once dirname(__DIR__) . '/src/Web/RecurrenceViewModel.php';
require_once dirname(__DIR__) . '/src/Web/ExecutionHistoryViewModel.php';
require_once dirname(__DIR__) . '/src/Web/SystemStatusViewModel.php';

$page = $_GET['page'] ?? 'recurrences';
$mode = $_GET['mode'] ?? 'new';
$recurrenceId = $_GET['id'] ?? null;
$categoryId = $_GET['category_id'] ?? null;
$uiEnabled = getenv('ADMIN_UI_ENABLED') === '1';
$authenticator = $adminAuthenticator ?? null;
$response = (new AdminUi(
    $uiEnabled,
    writeEnabled: getenv('ADMIN_UI_WRITE_ENABLED') === '1',
    writer: $adminRecurrenceWriter ?? null,
    authenticator: $authenticator,
    catalogProvider: $adminCatalogProvider ?? null,
))->respond(
    is_string($page) ? $page : '',
    is_string($mode) ? $mode : '',
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    is_string($recurrenceId) ? $recurrenceId : null,
    $_POST,
    is_string($categoryId) ? $categoryId : null,
);

http_response_code($response['status']);
header('Content-Type: ' . $response['content_type']);
if (isset($response['location'])) {
    header('Location: ' . $response['location'], true, 303);
}
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");

echo $response['body'];
