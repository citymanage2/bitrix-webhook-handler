<?php
/**
 * Bitrix24 Webhook Handler (Render PHP, без curl)
 * ------------------------------------------------
 * Сценарий:
 *  - Render принимает исходящий вебхук Bitrix24 (onCrmDealUpdate)
 *  - По ID сделки получает её поля
 *  - Если:
 *        CATEGORY_ID == 2
 *        STAGE_ID in [C2:WON, C2:APOLOGY, C2:LOSE]
 *    то:
 *        1) Находит и останавливает все активные БП по сделке
 *        2) Закрывает все задачи, привязанные к сделке (UF_CRM_TASK = D_<ID>)
 */

///////////////////////////////////////////////////////////////////////////////
// 1. НАСТРОЙКА WEBHOOK API BITRIX24
///////////////////////////////////////////////////////////////////////////////

$BX_INCOMING = 'https://b24-p60ult.bitrix24.ru/rest/42/2enlvyaqd1s0w238/';   // ← ЗАМЕНИТЬ!!!

// Целевая воронка и стадии
$TARGET_CATEGORY = 2;
$TARGET_STAGES   = ['C2:WON', 'C2:APOLOGY', 'C2:LOSE'];

// Логирование
$ENABLE_LOG = true;
$LOG_FILE   = '/tmp/render-b24.log';


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
        throw new RuntimeException("HTTP REQUEST FAILED: " . ($err['message'] ?? 'unknown error'));
    }

    $json = json_decode($res, true);
    if (!is_array($json)) {
        throw new RuntimeException("INVALID JSON: ".substr($res, 0, 200));
    }

    if (!empty($json['error'])) {
        throw new RuntimeException("B24 REST ERROR: {$json['error']} - {$json['error_description']}");
    }

    return $json['result'];
}

/**
 * Находит все активные бизнес-процессы по сделке.
 * DOCUMENT_ID у процессов: ['crm', 'CCrmDocumentDeal', 'DEAL_<ID>']
 */
function findWorkflowsByDeal($dealId) {
    $targetDocId = 'DEAL_' . (int)$dealId;

    log_msg("Looking for workflows for DOCUMENT_ID = $targetDocId");

    // Берём все активные CRM-процессы по сделкам
    $instances = bx_call('bizproc.workflow.instances', [
        'select' => ['ID', 'MODULE_ID', 'ENTITY', 'DOCUMENT_ID', 'TEMPLATE_ID', 'STARTED'],
        'filter' => [
            '=MODULE_ID' => 'crm',
            '=ENTITY'    => 'CCrmDocumentDeal',
        ],
    ]);

    log_msg('Raw workflows: ' . json_encode($instances, JSON_UNESCAPED_UNICODE));

    $result = [];

    foreach ($instances as $wf) {
        $docId = $wf['DOCUMENT_ID'] ?? '';
        
        // DOCUMENT_ID может быть массивом: ['crm', 'CCrmDocumentDeal', 'DEAL_123']
        if (is_array($docId)) {
            $docId = end($docId); // Берём последний элемент массива
        }
        
        log_msg("Checking workflow ID={$wf['ID']}, DOCUMENT_ID=$docId");
        
        if ($docId === $targetDocId) {
            $result[] = $wf;
            log_msg("✓ Matched workflow ID={$wf['ID']}");
        }
    }

    log_msg("Matched workflows count = " . count($result));
    return $result;
}


///////////////////////////////////////////////////////////////////////////////
// 3. ОСНОВНОЙ ОБРАБОТЧИК
///////////////////////////////////////////////////////////////////////////////

try {
    // Читаем тело запроса от исходящего вебхука Bitrix24
    $raw = file_get_contents('php://input');
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
        foreach ($workflows as $wf) {
            $wfId = $wf['ID'];
            log_msg("Terminate WF ID=$wfId (TEMPLATE_ID={$wf['TEMPLATE_ID']}, STARTED={$wf['STARTED']})");
            bx_call('bizproc.workflow.terminate', ['ID' => $wfId]);
        }
    } else {
        log_msg("No active workflows for this deal");
    }

    //----------------------------------------------------------------------
    // 6. ПОСЛЕ ЭТОГО ЗАКРЫВАЕМ ВСЕ ЗАДАЧИ, ПРИВЯЗАННЫЕ К СДЕЛКЕ
    //----------------------------------------------------------------------

    $binding = 'D_'.$dealId;

    $next = 0;
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

        foreach ($tasks as $t) {
            $tId   = $t['id'];
            $title = $t['title'] ?? '';
            log_msg("Close TASK ID=$tId ($title)");
            bx_call('tasks.task.update', [
                'taskId' => $tId,
                'fields' => ['STATUS' => 5],
            ]);
        }
    } while ($next != -1);

    log_msg("CLEANUP DONE for deal $dealId");

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'dealId'  => $dealId,
        'stage'   => $stage,
    ]);

} catch (Throwable $e) {
    log_msg("ERROR: ".$e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
}
