<?php
// Endpoint de Observabilidade e Diagnóstico de Saúde do WMS

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sige_client.php';

$startTime = microtime(true);

$health = [
    'status' => 'healthy',
    'timestamp' => date('Y-m-d H:i:s'),
    'uptime_check_ms' => 0,
    'checks' => [
        'database' => [
            'status' => 'unknown',
            'journal_mode' => 'unknown',
            'busy_timeout' => 'unknown',
            'file_size_bytes' => 0,
            'latency_ms' => 0
        ],
        'storage' => [
            'status' => 'unknown',
            'data_writable' => false,
            'logs_writable' => false,
            'disk_free_space' => 'unknown'
        ],
        'erp_integration' => [
            'status' => 'unknown',
            'sige_configured' => false,
            'latency_ms' => 0
        ],
        'system' => [
            'php_version' => PHP_VERSION,
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'CLI'
        ]
    ]
];

// 1. Verificação do Banco de Dados SQLite
try {
    $dbStart = microtime(true);
    $db = getDB();
    
    $journalMode = $db->query("PRAGMA journal_mode")->fetchColumn();
    $busyTimeout = $db->query("PRAGMA busy_timeout")->fetchColumn();
    
    // Teste de leitura
    $db->query("SELECT 1")->fetch();
    
    $dbLatency = round((microtime(true) - $dbStart) * 1000, 2);
    $fileSize = file_exists(DB_PATH) ? filesize(DB_PATH) : 0;

    $health['checks']['database'] = [
        'status' => 'healthy',
        'journal_mode' => $journalMode,
        'busy_timeout_ms' => (int)$busyTimeout,
        'file_size_bytes' => $fileSize,
        'latency_ms' => $dbLatency
    ];
} catch (\Throwable $e) {
    $health['status'] = 'unhealthy';
    $health['checks']['database'] = [
        'status' => 'unhealthy',
        'error' => $e->getMessage()
    ];
}

// 2. Verificação de Armazenamento e Diretórios
$dataWritable = is_writable(DATA_DIR);
$logsWritable = is_writable(LOGS_DIR);
$freeSpace = function_exists('disk_free_space') ? @disk_free_space(DATA_DIR) : false;

$health['checks']['storage'] = [
    'status' => ($dataWritable && $logsWritable) ? 'healthy' : 'degraded',
    'data_writable' => $dataWritable,
    'logs_writable' => $logsWritable,
    'disk_free_bytes' => $freeSpace !== false ? $freeSpace : 'unknown'
];

if (!$dataWritable || !$logsWritable) {
    $health['status'] = ($health['status'] === 'healthy') ? 'degraded' : $health['status'];
}

// 3. Verificação de Integração com SIGE Cloud
$checkSige = isset($_GET['check_sige']) && $_GET['check_sige'] === '1';
$sigeToken = getConfig('sige_token', DEFAULT_SIGE_TOKEN);
$health['checks']['erp_integration']['sige_configured'] = !empty($sigeToken);

if ($checkSige) {
    try {
        $sigeStart = microtime(true);
        $sige = new SigeClient();
        $sigeRes = $sige->testarConexao();
        $sigeLatency = round((microtime(true) - $sigeStart) * 1000, 2);

        $health['checks']['erp_integration'] = [
            'status' => $sigeRes['success'] ? 'healthy' : 'degraded',
            'sige_configured' => true,
            'latency_ms' => $sigeLatency,
            'message' => $sigeRes['message'] ?? ''
        ];

        if (!$sigeRes['success'] && $health['status'] === 'healthy') {
            $health['status'] = 'degraded';
        }
    } catch (\Throwable $e) {
        $health['checks']['erp_integration'] = [
            'status' => 'unhealthy',
            'sige_configured' => true,
            'error' => $e->getMessage()
        ];
    }
} else {
    $health['checks']['erp_integration']['status'] = 'ready';
    $health['checks']['erp_integration']['note'] = 'Adicione ?check_sige=1 para realizar ping de teste na API do ERP';
}

$health['uptime_check_ms'] = round((microtime(true) - $startTime) * 1000, 2);

$statusCode = ($health['status'] === 'healthy' || $health['status'] === 'degraded') ? 200 : 503;
jsonResponse($health, $statusCode);
