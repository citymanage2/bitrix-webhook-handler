<?php
/**
 * Bitrix24 Webhook Handler (Render PHP, без curl)
 */

///////////////////////////////////////////////////////////////////////////////
// 1. НАСТРОЙКА WEBHOOK API BITRIX24
///////////////////////////////////////////////////////////////////////////////

$BX_INCOMING = 'https://ВАШПОРТАЛ.bitrix24.ru/rest/ХХ/ТОКЕН/';   // ← ЗАМЕНИТЬ!!!

$TARGET_CATEGORY = 2;
$TARGET_STAGES   = ['C2:WON', 'C2:APOLOGY', 'C2:LOSE'];

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


///////////////////////////////////////////////////////////////////////////////
// 3. ОСНОВНОЙ ОБРАБОТЧИК
///////////////////////////////////////////////////////////////////////////////

try {
    $raw = file_get_contents('php://input');
    log_msg("RAW: ".$raw);

    $payload = json_decode($raw, true);
    $dealId  = 0;

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

    // --- 4. Получаем сделку ---
    $deal = bx_call('crm.deal.get', ['id' => $dealId]);

    $stage    = $deal['STAGE_ID'] ?? '';
    $category = (int)($deal['CATEGORY_ID'] ?? -1);

    log_msg("Deal: STAGE=$stage, CATEGORY=$category");

    global $TARGET_CATEGORY, $TARGET_STAGES;

    if ($category !== $TARGET_CATEGORY || !in_array($stage, $TARGET_STAGES, true)) {
        log_msg("Not our category/stage — SKIP");
        http_response_code(204);
        exit;
    }

    log_msg("Conditions OK — CLEANUP start");

    // --- 5. Останавливаем все активные БП ---
    $docId = ['crm', 'CCrmDocumentDeal', 'DEAL_'.$dealId];

    $instances = bx_call('bizproc.workflow.instances', [
        'select' => ['ID','MODIFIED','STARTED','TEMPLATE_ID'],
        'filter' => ['=DOCUMENT_ID' => $docId],
    ]);

    if (!empty($instances)) {
        foreach ($instances as $wf) {
            $wfId = $wf['ID'];
            log_msg("Terminate WF ID=$wfId");
            bx_call('bizproc.workflow.terminate', ['ID' => $wfId]);
        }
    } else {
        log_msg("No active workflows");
    }

    // --- 6. Закрываем задачи, привязанные к сделке ---
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
            $tId = $t['id'];
            log_msg("Close TASK ID=$tId");
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
