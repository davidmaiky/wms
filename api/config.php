<?php
// Configurações Globais do WMS & SIGE Cloud

define('DB_PATH', __DIR__ . '/../data/wms.sqlite');
define('DATA_DIR', __DIR__ . '/../data');

// Credenciais Padrão do SIGE Cloud (podem ser alteradas também via UI em Configurações)
define('DEFAULT_SIGE_TOKEN', '7135365e78e515613e5ae5f333103d430033e19bf8980bf37e4a9115a754641877df45a0c554ed2703b4ef03e8b14e21784fdbbfc278c4d05d8f57efa2ac4ae0b8d0470ab1a0ee2d9992d1604e0d25d551cef01a5dd2ddd7a72131665045b26c2ee650ac57862f74fc5517fe269bd301a660a0457873757d44dbd52baa4f4d4d');
define('DEFAULT_SIGE_USER', 'david@primepro.com.br');
define('DEFAULT_SIGE_APP', 'API');
define('SIGE_BASE_URL', 'https://api.sigecloud.com.br');

// Criar diretório de dados se não existir
if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0777, true);
}

// Helpers de resposta JSON
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function jsonError($message, $statusCode = 400, $details = null) {
    jsonResponse([
        'success' => false,
        'error' => $message,
        'details' => $details
    ], $statusCode);
}

// Iniciar sessão PHP para autenticação dos operadores
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Verifica se o operador está autenticado.
 * Bloqueia requisições não autorizadas, exceto para a ação de login.
 */
function checkAuth() {
    // Se for execução via CLI (por exemplo, scripts de migração), permitir
    if (PHP_SAPI === 'cli') {
        return;
    }

    $script = basename($_SERVER['SCRIPT_NAME']);
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    // Se a ação não estiver na query/post, verificar se está no corpo JSON
    if (empty($action)) {
        $input = json_decode(file_get_contents('php://input'), true);
        if (is_array($input) && isset($input['action'])) {
            $action = $input['action'];
        }
    }

    // Permitir a ação de login sem autenticação
    if ($script === 'usuarios.php' && $action === 'login') {
        return;
    }

    // Se a sessão não estiver ativa, retorna erro 401 Unauthorized
    if (empty($_SESSION['wms_user'])) {
        jsonError("Não autorizado. Por favor, faça login.", 401);
    }
}

// Executar verificação em todas as requisições web
checkAuth();

