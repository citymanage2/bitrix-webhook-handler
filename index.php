<?php
/**
 * Bitrix24 Webhook Handler для Render
 * Останавливает ТОЛЬКО бизнес-процессы при закрытии сделки
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

///////////////////////////////////////////////////////////////////////////////
// ПРОСМОТР ЛОГОВ
///////////////////////////////////////////////////////////////////////////////

if (isset($_GET['show_logs']) && $_GET['show_logs'] === 'secret_key_12345') {
    header('Content-Type: text/plain; charset=utf-8');
    
    $logFile = '/tmp/render-b24.log';
    
    echo "=== BITRIX24 WEBHOOK LOGS ===\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n\n";
    
    if (file_exists($logFile)) {
        echo file_get_contents($logFile);
    } else {
        echo "❌ No log file found at: $logFile\n";
    }
    exit;
}

///////////////////////////////////////////////////////////////////////////////
// НАСТРОЙКА
///////////////////////////////////////////////////////////////////////////////

$BX_INCOMING = 'https://b24-p60ult.bitrix24.ru/rest/42/2enlvyaqd1s0w238/';

// Целевая воронка и стадии
$TARGET_CATEGORY = 2;
$TARGET_STAGES   = [
    'C2:WON',           // Успешно реализовано
    'C2:APOLOGY',       // Извинились
    'C2:LOSE',          // Проиграли
];

// Логирование
$ENABLE_LOG = true;
$LOG_FILE   = '/tmp/render-b24.log';

///////////////////////////////////////////////////////////////////////////////
// ФУНКЦИИ
///////////////////////////////////////////////////////////////////////////////

function log_msg($msg) {
    global $ENABLE_LOG, $LOG_FILE;
    if (!$ENABLE_LOG) return;
    @file_put_contents($LOG_FILE, '['.date('Y-m-d H:i:s').'] '.$msg.PHP_EOL, FILE_APPEND);
}

function bx_call($method, $params = []) {
    global $BX_INCOMING;

    $url  = $BX_INCOMING . $method . '.json';
    $data = http_build_query($params);

    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n"
                       . "Content-Length: " . strlen($data) . "\r\n",
            'content' => $data,
            'timeout' => 20,
        ]
    ];

    $context = stream_context_create($opts);
    $res = @file_get_contents($url, false, $context);

    if ($res === false) {
        $err = error_get_last();
        log_msg("HTTP ERROR: " . json_encode($err));
        throw new RuntimeException("HTTP REQUEST FAILED: " . ($err['message'] ?? 'unknown error'));
    }

    $json = json_decode($res, true);
    if (!is_array($json)) {
        throw new RuntimeException("INVALID JSON: ".substr($res, 0, 200));
    }

    if (!empty($json['error'])) {
        log_msg("B24 API ERROR: " . json_encode($json));
        throw new RuntimeException("B24 REST ERROR: {$json['error']} - {$json['error_description']}");
    }

    return $json['result'] ?? $json;
}

function findWorkflowsByDeal($dealId) {
    log_msg("=== SEARCHING WORKFLOWS FOR DEAL #$dealId ===");

    try {
        $instances = bx_call('bizproc.workflow.instances', [
            'select' => ['ID', 'MODULE_ID', 'ENTITY', 'DOCUMENT_ID', 'TEMPLATE_ID', 'STARTED', 'WORKFLOW_STATUS'],
            'filter' => [
                '=MODULE_ID' => 'crm',
                '=ENTITY'    => 'CCrmDocumentDeal',
            ],
        ]);

        log_msg('Total workflows found: ' . count($instances));

        $result = [];
        $targetDocId = 'DEAL_' . (int)$dealId;

        foreach ($instances as $wf) {
            $docId = $wf['DOCUMENT_ID'] ?? '';
            
            if (is_array($docId)) {
                $docIdStr = end($docId);
            } else {
                $docIdStr = $docId;
            }
            
            if ($docIdStr === $targetDocId) {
                $result[] = $wf;
                log_msg("✓ MATCHED WF ID={$wf['ID']}");
            }
        }

        log_msg("Total matched workflows: " . count($result));
        return $result;

    } catch (Exception $e) {
        log_msg("ERROR in findWorkflowsByDeal: " . $e->getMessage());
        return [];
    }
}

function terminateWorkflow($wfId) {
    log_msg("Terminating WF ID=$wfId");
    
    try {
        $result = bx_call('bizproc.workflow.terminate', [
            'ID' => $wfId,
            'STATUS' => 'Stopped by automation'
        ]);
        log_msg("✓ Terminated WF ID=$wfId");
        return true;
    } catch (Exception $e) {
        log_msg("✗ Failed to terminate WF ID=$wfId: " . $e->getMessage());
        return false;
    }
}

///////////////////////////////////////////////////////////////////////////////
// ОСНОВНОЙ ОБРАБОТЧИК
///////////////////////////////////////////////////////////////////////////////

try {
    log_msg("==================== NEW REQUEST ====================");
    
    $raw = file_get_contents('php://input');

    if (strlen($raw) == 0) {
        http_response_code(200);
        echo "Webhook handler is ready";
        exit;
    }

    parse_str($raw, $payload);

    $dealId = (int)($payload['data']['FIELDS']['ID'] ?? 0);

    if ($dealId <= 0) {
        log_msg("NO DEAL ID - EXIT");
        http_response_code(204);
        exit;
    }

    log_msg("Processing DEAL #$dealId");

    $deal = bx_call('crm.deal.get', ['id' => $dealId]);

    $stage    = $deal['STAGE_ID'] ?? '';
    $category = (int)($deal['CATEGORY_ID'] ?? -1);

    log_msg("Deal: STAGE=$stage, CATEGORY=$category");

    if ($category !== $TARGET_CATEGORY || !in_array($stage, $TARGET_STAGES, true)) {
        log_msg("Not target stage - SKIP");
        http_response_code(204);
        exit;
    }

    log_msg("Target stage reached - TERMINATE WORKFLOWS");

    // Останавливаем все БП
    $workflows = findWorkflowsByDeal($dealId);
    $terminated = 0;

    foreach ($workflows as $wf) {
        if (terminateWorkflow($wf['ID'])) {
            $terminated++;
        }
    }

    log_msg("DONE: Terminated $terminated workflows");

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'dealId'  => $dealId,
        'workflowsTerminated' => $terminated,
    ]);

} catch (Throwable $e) {
    log_msg("ERROR: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
}
