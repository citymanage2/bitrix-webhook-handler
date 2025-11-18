<?php
/**
 * Bitrix24 Webhook Handler (Render PHP, без curl)
 * С встроенным просмотром логов через ?show_logs=secret_key_12345
 */

///////////////////////////////////////////////////////////////////////////////
// ПРОСМОТР ЛОГОВ (добавлено в начало для быстрого доступа)
///////////////////////////////////////////////////////////////////////////////

if (isset($_GET['show_logs']) && $_GET['show_logs'] === 'secret_key_12345') {
    header('Content-Type: text/plain; charset=utf-8');
    
    $logFile = '/tmp/render-b24.log';
    $altLog = __DIR__ . '/bitrix-webhook.log';
    
    echo "=== BITRIX24 WEBHOOK LOGS ===\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n";
    echo "Script: " . __FILE__ . "\n\n";
    
    if (file_exists($logFile)) {
        echo "=== LOG FILE: $logFile ===\n\n";
        echo file_get_contents($logFile);
    } elseif (file_exists($altLog)) {
        echo "=== ALTERNATIVE LOG: $altLog ===\n\n";
        echo file_get_contents($altLog);
    } else {
        echo "❌ No log files found!\n\n";
        echo "Checked locations:\n";
        echo "  - $logFile\n";
        echo "  - $altLog\n\n";
        echo "Current directory: " . getcwd() . "\n";
        echo "Directory contents:\n";
        print_r(scandir(__DIR__));
    }
    exit;
}

///////////////////////////////////////////////////////////////////////////////
// 1. НАСТРОЙКА WEBHOOK API BITRIX24
///////////////////////////////////////////////////////////////////////////////

$BX_INCOMING = 'https://b24-p60ult.bitrix24.ru/rest/42/2enlvyaqd1s0w238/';

// Целевая воронка и стадии
$TARGET_CATEGORY = 2;
$TARGET_STAGES   = ['C2:WON', 'C2:APOLOGY', 'C2:LOSE'];

// Логирование - пробуем оба варианта
$ENABLE_LOG = true;
$LOG_FILE   = '/tmp/render-b24.log';

// Если /tmp недоступен, пишем рядом со скриптом
if (!is_writable('/tmp')) {
    $LOG_FILE = __DIR__ . '/bitrix-webhook.log';
}

///////////////////////////////////////////////////////////////////////////////
// 2. ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
///////////////////////////////////////////////////////////////////////////////

function log_msg($msg) {
    global $ENABLE_LOG, $LOG_FILE;
    if (!$ENABLE_LOG) return;
    @file_put_contents($LOG_FILE, '['.date('Y-m-d H:i:s').'] '.$msg.PHP_EOL, FILE_APPEND);
}

/**
 * Вызов REST-метода Bitrix24 через file_get_contents
 */
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

    log_msg("Response from $method: " . substr($res, 0, 500));

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

/**
 * Находит все активные бизнес-процессы по сделке.
 */
function findWorkflowsByDeal($dealId) {
    log_msg("=== SEARCHING WORKFLOWS FOR DEAL #$dealId ===");

    try {
        // Вариант 1: Через bizproc.workflow.instances
        $instances = bx_call('bizproc.workflow.instances', [
            'select' => ['ID', 'MODULE_ID', 'ENTITY', 'DOCUMENT_ID', 'TEMPLATE_ID', 'STARTED', 'WORKFLOW_STATUS'],
            'filter' => [
                '=MODULE_ID' => 'crm',
                '=ENTITY'    => 'CCrmDocumentDeal',
            ],
        ]);

        log_msg('Total workflows found: ' . count($instances));
        log_msg('Raw workflows data: ' . json_encode($instances, JSON_UNESCAPED_UNICODE));

        $result = [];
        $targetDocId = 'DEAL_' . (int)$dealId;

        foreach ($instances as $wf) {
            $docId = $wf['DOCUMENT_ID'] ?? '';
            
            log_msg("Processing WF ID={$wf['ID']}, raw DOCUMENT_ID: " . json_encode($docId));
            
            // DOCUMENT_ID может быть массивом: ['crm', 'CCrmDocumentDeal', 'DEAL_123']
            if (is_array($docId)) {
                $docIdStr = end($docId); // Берём последний элемент
                log_msg("  -> Converted to: $docIdStr");
            } else {
                $docIdStr = $docId;
            }
            
            if ($docIdStr === $targetDocId) {
                $result[] = $wf;
                log_msg("  ✓ MATCHED! WF ID={$wf['ID']}, Status={$wf['WORKFLOW_STATUS']}");
            }
        }

        log_msg("Total matched workflows: " . count($result));
        return $result;

    } catch (Exception $e) {
        log_msg("ERROR in findWorkflowsByDeal: " . $e->getMessage());
        return [];
    }
}

/**
 * Останавливает бизнес-процесс (несколько методов)
 */
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

    // Метод 3: Через полный массив DOCUMENT_ID
    if (!empty($wfData['DOCUMENT_ID'])) {
        try {
            log_msg("Trying with full DOCUMENT_ID array...");
            $result = bx_call('bizproc.workflow.terminate', [
                'DOCUMENT_ID' => $wfData['DOCUMENT_ID'],
                'ID' => $wfId
            ]);
            log_msg("SUCCESS: terminate with DOCUMENT_ID = " . json_encode($result));
            return true;
        } catch (Exception $e) {
            log_msg("FAILED terminate with DOCUMENT_ID: " . $e->getMessage());
        }
    }

    log_msg("ERROR: All methods failed for WF ID=$wfId");
    return false;
}


///////////////////////////////////////////////////////////////////////////////
// 3. ОСНОВНОЙ ОБРАБОТЧИК
///////////////////////////////////////////////////////////////////////////////

try {
    log_msg("==================== NEW REQUEST ====================");
    log_msg("Script file: " . __FILE__);
    log_msg("Log file: " . $LOG_FILE);
    log_msg("Request method: " . ($_SERVER['REQUEST_METHOD'] ?? 'unknown'));
    log_msg("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'unknown'));
    
    // Читаем тело запроса от исходящего вебхука Bitrix24
    $raw = file_get_contents('php://input');
    log_msg("RAW payload length: " . strlen($raw));
    log_msg("RAW: ".$raw);

    $payload = json_decode($raw, true);
    $dealId  = 0;

    // Получаем ID сделки из разных возможных структур
    if (isset($payload['data']['FIELDS']['ID'])) {
        $dealId = (int)$payload['data']['FIELDS']['ID'];
    } elseif (isset($payload['data']['ID'])) {
        $dealId = (int)$payload['data']['ID'];
    }

    if ($dealId <= 0) {
        log_msg("NO DEAL ID — EXIT");
        http_response_code(204);
        exit;
    }

    log_msg("Processing DEAL #$dealId");

    // --- 4. Получаем данные сделки ---
    $deal = bx_call('crm.deal.get', ['id' => $dealId]);

    $stage    = $deal['STAGE_ID'] ?? '';
    $category = (int)($deal['CATEGORY_ID'] ?? -1);

    log_msg("Deal: STAGE=$stage, CATEGORY=$category");

    global $TARGET_CATEGORY, $TARGET_STAGES;

    // Проверяем, что сделка в нужной воронке и на нужной стадии
    if ($category !== $TARGET_CATEGORY || !in_array($stage, $TARGET_STAGES, true)) {
        log_msg("Not our category/stage — SKIP");
        http_response_code(204);
        exit;
    }

    log_msg("Conditions OK — CLEANUP start");

    //----------------------------------------------------------------------
    // 5. СНАЧАЛА ОСТАНАВЛИВАЕМ ВСЕ АКТИВНЫЕ БИЗНЕС-ПРОЦЕССЫ СДЕЛКИ
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
        log_msg("No active workflows for this deal");
    }

    //----------------------------------------------------------------------
    // 6. ПОСЛЕ ЭТОГО ЗАКРЫВАЕМ ВСЕ ЗАДАЧИ, ПРИВЯЗАННЫЕ К СДЕЛКЕ
    //----------------------------------------------------------------------

    $binding = 'D_'.$dealId;
    log_msg("=== CLOSING TASKS FOR BINDING: $binding ===");

    $next = 0;
    $totalClosed = 0;
    
    do {
        $res = bx_call('tasks.task.list', [
            'select' => ['ID','TITLE','STATUS','UF_CRM_TASK'],
            'filter' => [
                '=UF_CRM_TASK' => $binding,
                '!=STATUS'     => 5, // Completed
            ],
            'start' => $next,
        ]);

        $tasks = $res['tasks'] ?? [];
        $next  = $res['next'] ?? -1;

        log_msg("Batch: found " . count($tasks) . " tasks");

        foreach ($tasks as $t) {
            $tId   = $t['id'];
            $title = $t['title'] ?? '';
            try {
                log_msg("Closing TASK ID=$tId ($title)");
                bx_call('tasks.task.update', [
                    'taskId' => $tId,
                    'fields' => ['STATUS' => 5],
                ]);
                $totalClosed++;
            } catch (Exception $e) {
                log_msg("ERROR closing task $tId: " . $e->getMessage());
            }
        }
    } while ($next != -1);

    log_msg("Total tasks closed: $totalClosed");
    log_msg("CLEANUP DONE for deal $dealId");

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'dealId'  => $dealId,
        'stage'   => $stage,
        'workflowsTerminated' => count($workflows),
        'tasksClosed' => $totalClosed,
    ]);

} catch (Throwable $e) {
    log_msg("FATAL ERROR: ".$e->getMessage());
    log_msg("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
}
```

Теперь:

1. **Загрузите этот файл** на Render
2. **Запустите вебхук** из Bitrix24 (переведите сделку в финальную стадию)
3. **Откройте в браузере**:
```
   https://ваш-домен.onrender.com/?show_logs=secret_key_12345
