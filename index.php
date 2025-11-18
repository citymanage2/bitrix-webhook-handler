<?php
// Включаем показ ошибок для отладки
error_reporting(E_ALL);
ini_set('display_errors', 0); // Отключаем вывод на экран, пишем только в лог
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
$TARGET_CATEGORY = 2;
$TARGET_STAGES   = ['C2:WON', 'C2:APOLOGY', 'C2:LOSE'];
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

function terminateWorkflow($wfId, $wfData = []) {
    log_msg("=== TERMINATING WORKFLOW ID=$wfId ===");
    
    // Метод 1: bizproc.workflow.terminate
    try {
        log_msg("Trying bizproc.workflow.terminate...");
        $result = bx_call('bizproc.workflow.terminate', [
            'ID' => $wfId,
            'STATUS' => 'Stopped by automation'
        ]);
        log_msg("SUCCESS: terminate result = " . json_encode($result));
        return true;
    } catch (Exception $e) {
        log_msg("FAILED terminate: " . $e->getMessage());
    }

    // Метод 2: bizproc.workflow.kill
    try {
        log_msg("Trying bizproc.workflow.kill...");
        $result = bx_call('bizproc.workflow.kill', [
            'ID' => $wfId
        ]);
        log_msg("SUCCESS: kill result = " . json_encode($result));
        return true;
    } catch (Exception $e) {
        log_msg("FAILED kill: " . $e->getMessage());
    }

    log_msg("ERROR: All methods failed for WF ID=$wfId");
    return false;
}

///////////////////////////////////////////////////////////////////////////////
// ОСНОВНОЙ ОБРАБОТЧИК
///////////////////////////////////////////////////////////////////////////////

try {
    log_msg("==================== NEW REQUEST ====================");
    log_msg("Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'unknown'));
    log_msg("URI: " . ($_SERVER['REQUEST_URI'] ?? 'unknown'));
    
    $raw = file_get_contents('php://input');
    log_msg("Payload length: " . strlen($raw));
    log_msg("Payload: " . $raw);

    if (strlen($raw) == 0) {
        log_msg("Empty payload - probably health check or browser request");
        http_response_code(200);
        echo "Webhook handler is ready";
        exit;
    }

    $payload = json_decode($raw, true);
    $dealId  = 0;

    if (isset($payload['data']['FIELDS']['ID'])) {
        $dealId = (int)$payload['data']['FIELDS']['ID'];
    } elseif (isset($payload['data']['ID'])) {
        $dealId = (int)$payload['data']['ID'];
    }

    if ($dealId <= 0) {
        log_msg("NO DEAL ID in payload - EXIT");
        http_response_code(204);
        exit;
    }

    log_msg("Processing DEAL #$dealId");

    $deal = bx_call('crm.deal.get', ['id' => $dealId]);

    $stage    = $deal['STAGE_ID'] ?? '';
    $category = (int)($deal['CATEGORY_ID'] ?? -1);

    log_msg("Deal: STAGE=$stage, CATEGORY=$category");

    if ($category !== $TARGET_CATEGORY || !in_array($stage, $TARGET_STAGES, true)) {
        log_msg("Not target category/stage - SKIP");
        http_response_code(204);
        exit;
    }

    log_msg("Conditions OK - START CLEANUP");

    //----------------------------------------------------------------------
    // ОСТАНАВЛИВАЕМ БИЗНЕС-ПРОЦЕССЫ
    //----------------------------------------------------------------------

    $workflows = findWorkflowsByDeal($dealId);

    if (!empty($workflows)) {
        log_msg("Found " . count($workflows) . " workflows to terminate");
        foreach ($workflows as $wf) {
            $success = terminateWorkflow($wf['ID'], $wf);
            if ($success) {
                log_msg("✓ Successfully terminated WF ID={$wf['ID']}");
            } else {
                log_msg("✗ Failed to terminate WF ID={$wf['ID']}");
            }
        }
    } else {
        log_msg("No active workflows found");
    }

    //----------------------------------------------------------------------
    // ЗАКРЫВАЕМ ЗАДАЧИ
    //----------------------------------------------------------------------

    $binding = 'D_'.$dealId;
    log_msg("=== CLOSING TASKS: $binding ===");

    $next = 0;
    $totalClosed = 0;
    
    do {
        $res = bx_call('tasks.task.list', [
            'select' => ['ID','TITLE','STATUS','UF_CRM_TASK'],
            'filter' => [
                '=UF_CRM_TASK' => $binding,
                '!=STATUS'     => 5,
            ],
            'start' => $next,
        ]);

        $tasks = $res['tasks'] ?? [];
        $next  = $res['next'] ?? -1;

        foreach ($tasks as $t) {
            $tId   = $t['id'];
            $title = $t['title'] ?? '';
            try {
                log_msg("Closing TASK ID=$tId ($title)");
                bx_call('tasks.task.update', [
                    'taskId'
