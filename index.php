<?php
/**
 * Bitrix24 Webhook Handler (Render PHP)
 * -------------------------------------
 * Сценарий:
 *  - Render вызывает этот PHP-файл по событию onCrmDealUpdate
 *  - PHP получает ID сделки
 *  - Проверяет:
 *        CATEGORY_ID == 2
 *        STAGE_ID in [C2:WON, C2:APOLOGY, C2:LOSE]
 *  - Если условия совпадают:
 *        1) Завершает все активные бизнес-процессы сделки
 *        2) Закрывает все связанные задачи UF_CRM_TASK = D_<ID>
 *
 * Требуется:
 *  - Вставить НИЖЕ URL входящего вебхука Bitrix24
 *    (Настройки → Приложения → Вебхуки → Входящий вебхук)
 */

////////////////////////////////////////////////////////////////////////////////
// 1. НАСТРОЙКА WEBHOOK API BITRIX24
////////////////////////////////////////////////////////////////////////////////

$BX_INCOMING = 'https://ВАШПОРТАЛ.bitrix24.ru/rest/ХХ/ТОКЕН/';   // ← ЗАМЕНИТЬ!!!

// целевая категория (воронка)
$TARGET_CATEGORY = 2;

// стадии, на которых запускаем зачистку
$TARGET_STAGES = ['C2:WON', 'C2:APOLOGY', 'C2:LOSE'];

// включить логирование в файл /tmp/render-b24.log
$ENABLE_LOG = true;
$LOG_FILE = '/tmp/render-b24.log';


////////////////////////////////////////////////////////////////////////////////
// 2. ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
////////////////////////////////////////////////////////////////////////////////

function log_msg($msg) {
    global $ENABLE_LOG, $LOG_FILE;
    if (!$ENABLE_LOG) return;
    file_put_contents($LOG_FILE, '['.date('Y-m-d H:i:s').'] '.$msg.PHP_EOL, FILE_APPEND);
}

function bx_call($method, $params = []) {
    global $BX_INCOMING;

    $url = $BX_INCOMING . $method . '.json';
    $ch  = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
    ]);

    $res = curl_exec($ch);

    if ($res === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("CURL ERROR: $err");
    }

    curl_close($ch);

    $data = json_decode($res, true);
    if (!is_array($data)) {
        throw new RuntimeException("INVALID JSON: ".substr($res,0,200));
    }

    if (!empty($data['error'])) {
        throw new RuntimeException("B24 REST ERROR: {$data['error']} - {$data['error_description']}");
    }

    return $data['result'];
}


////////////////////////////////////////////////////////////////////////////////
// 3. ОСНОВНОЙ ОБРАБОТЧИК ВХОДЯЩЕГО СОБЫТИЯ
////////////////////////////////////////////////////////////////////////////////

try {
    // читаем payload от Bitrix24 (исходящий вебхук)
    $raw = file_get_contents('php://input');
    log_msg("RAW: " . $raw);

    $payload = json_decode($raw, true);

    // ищем ID сделки
    $dealId = 0;

    if (isset($payload['data']['FIELDS']['ID'])) {
        $dealId = (int)$payload['data']['FIELDS']['ID'];
    } elseif (isset($payload['data']['ID'])) {
        $dealId = (int)$payload['data']['ID'];
    }

    if ($dealId <= 0) {
        log_msg("NO DEAL ID FOUND — EXIT");
        http_response_code(204);
        exit;
    }

    log_msg("Processing DEAL #$dealId");

    ////////////////////////////////////////////////////////////////////////////
    // 4. ПОЛУЧАЕМ ПОЛЯ СДЕЛКИ
    ////////////////////////////////////////////////////////////////////////////

    $deal = bx_call('crm.deal.get', ['id' => $dealId]);

    $stage     = $deal['STAGE_ID']     ?? '';
    $category  = (int)($deal['CATEGORY_ID'] ?? -1);

    log_msg("Deal: STAGE=$stage, CATEGORY=$category");

    ////////////////////////////////////////////////////////////////////////////
    // 5. ПРОВЕРКА УСЛОВИЙ ДЛЯ ЗАПУСКА «ЗАЧИСТКИ»
    ////////////////////////////////////////////////////////////////////////////

    global $TARGET_CATEGORY, $TARGET_STAGES;

    if ($category !== $TARGET_CATEGORY || !in_array($stage, $TARGET_STAGES, true)) {
        log_msg("Deal is NOT in target category/stage — SKIP");
        http_response_code(204);
        exit;
    }

    log_msg("Conditions OK — running CLEANUP");


    ////////////////////////////////////////////////////////////////////////////
    // 6. ОСТАНАВЛИВАЕМ ВСЕ АКТИВНЫЕ БИЗНЕС-ПРОЦЕССЫ
    ////////////////////////////////////////////////////////////////////////////

    $docId = ['crm', 'CCrmDocumentDeal', 'DEAL_'.$dealId];

    $instances = bx_call('bizproc.workflow.instances', [
        'select' => ['ID', 'MODIFIED', 'STARTED', 'TEMPLATE_ID'],
        'filter' => ['=DOCUMENT_ID' => $docId],
    ]);

    if (!empty($instances)) {
        foreach ($instances as $wf) {
            $wfId = $wf['ID'];
            log_msg("Terminating workflow ID=$wfId");
            bx_call('bizproc.workflow.terminate', [
                'ID' => $wfId,
            ]);
        }
    } else {
        log_msg("NO active workflows");
    }


    ////////////////////////////////////////////////////////////////////////////
    // 7. ЗАКРЫВАЕМ ВСЕ ЗАДАЧИ, ПРИВЯЗАННЫЕ К СДЕЛКЕ
    ////////////////////////////////////////////////////////////////////////////

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
            log_msg("Closing task ID=$tId");
            bx_call('tasks.task.update', [
                'taskId' => $tId,
                'fields' => ['STATUS' => 5],
            ]);
        }

    } while ($next != -1);


    ////////////////////////////////////////////////////////////////////////////
    // 8. ОКОНЧАНИЕ РАБОТЫ
    ////////////////////////////////////////////////////////////////////////////

    log_msg("CLEANUP COMPLETE for deal $dealId");

    echo json_encode([
        'success' => true,
        'dealId'  => $dealId,
        'stage'   => $stage,
    ]);
    http_response_code(200);

} catch (Throwable $e) {
    log_msg("ERROR: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
}
