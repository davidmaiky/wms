<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$db = getDB();

$inputJSON = json_decode(file_get_contents('php://input'), true);
if (is_array($inputJSON)) {
    $_POST = array_merge($_POST, $inputJSON);
}

try {
    switch ($action) {
        // -------------------------------------------------------------
        // LIST: Listar todos os usuários com filtros e estatísticas
        // -------------------------------------------------------------
        case 'list':
            $q = trim($_GET['q'] ?? '');
            $funcao = trim($_GET['funcao'] ?? '');
            $status = trim($_GET['status'] ?? '');

            $sql = "SELECT id, nome, email, funcao, (CASE WHEN pin IS NOT NULL AND pin != '' THEN 1 ELSE 0 END) AS has_pin, status, avatar_cor, ultimo_acesso, criado_em, atualizado_em FROM usuarios WHERE 1=1";
            $params = [];

            if ($q !== '') {
                $sql .= " AND (nome LIKE ? OR email LIKE ?)";
                $params[] = "%$q%";
                $params[] = "%$q%";
            }

            if ($funcao !== '') {
                $sql .= " AND funcao = ?";
                $params[] = $funcao;
            }

            if ($status !== '') {
                $sql .= " AND status = ?";
                $params[] = $status;
            }

            $sql .= " ORDER BY CASE WHEN status = 'ativo' THEN 0 ELSE 1 END, nome ASC";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $usuarios = $stmt->fetchAll();

            // Estatísticas gerais
            $statsStmt = $db->query("
                SELECT 
                    COUNT(*) AS total,
                    SUM(CASE WHEN status = 'ativo' THEN 1 ELSE 0 END) AS ativos,
                    SUM(CASE WHEN status = 'inativo' THEN 1 ELSE 0 END) AS inativos,
                    SUM(CASE WHEN funcao = 'admin' AND status = 'ativo' THEN 1 ELSE 0 END) AS admins,
                    SUM(CASE WHEN funcao IN ('operador', 'conferente') AND status = 'ativo' THEN 1 ELSE 0 END) AS operadores
                FROM usuarios
            ");
            $stats = $statsStmt->fetch() ?: [
                'total' => 0, 'ativos' => 0, 'inativos' => 0, 'admins' => 0, 'operadores' => 0
            ];

            jsonResponse([
                'success' => true,
                'data' => $usuarios,
                'stats' => $stats
            ]);
            break;

        // -------------------------------------------------------------
        // GET: Obter usuário por ID
        // -------------------------------------------------------------
        case 'get':
            $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
            if (!$id) {
                jsonError("ID do usuário não informado.");
            }

            $stmt = $db->prepare("SELECT id, nome, email, funcao, (CASE WHEN pin IS NOT NULL AND pin != '' THEN 1 ELSE 0 END) AS has_pin, status, avatar_cor, ultimo_acesso, criado_em, atualizado_em FROM usuarios WHERE id = ?");
            $stmt->execute([$id]);
            $usuario = $stmt->fetch();

            if (!$usuario) {
                jsonError("Usuário não encontrado.", 404);
            }

            jsonResponse([
                'success' => true,
                'usuario' => $usuario
            ]);
            break;

        // -------------------------------------------------------------
        // CREATE: Cadastrar novo usuário
        // -------------------------------------------------------------
        case 'create':
            $nome = trim($_POST['nome'] ?? '');
            $email = trim(strtolower($_POST['email'] ?? ''));
            $funcao = trim($_POST['funcao'] ?? 'operador');
            $pin = trim($_POST['pin'] ?? '');
            $status = trim($_POST['status'] ?? 'ativo');
            $avatarCor = trim($_POST['avatar_cor'] ?? '#3b82f6');

            if (empty($nome)) {
                jsonError("O nome completo é obrigatório.");
            }
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                jsonError("Informe um endereço de e-mail válido.");
            }

            $funcoesValidas = ['admin', 'supervisor', 'conferente', 'operador'];
            if (!in_array($funcao, $funcoesValidas)) {
                $funcao = 'operador';
            }

            $status = ($status === 'inativo') ? 'inativo' : 'ativo';

            // Verificar se o e-mail já está cadastrado
            $checkStmt = $db->prepare("SELECT id FROM usuarios WHERE email = ?");
            $checkStmt->execute([$email]);
            if ($checkStmt->fetch()) {
                jsonError("Já existe um usuário cadastrado com o e-mail '$email'.");
            }

            $stmt = $db->prepare("
                INSERT INTO usuarios (nome, email, funcao, pin, status, avatar_cor, criado_em, atualizado_em)
                VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([$nome, $email, $funcao, $pin, $status, $avatarCor]);
            $novoId = $db->lastInsertId();

            jsonResponse([
                'success' => true,
                'message' => "Usuário '$nome' cadastrado com sucesso!",
                'id' => (int)$novoId
            ]);
            break;

        // -------------------------------------------------------------
        // UPDATE: Atualizar dados de um usuário
        // -------------------------------------------------------------
        case 'update':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) {
                jsonError("ID do usuário não informado.");
            }

            $nome = trim($_POST['nome'] ?? '');
            $email = trim(strtolower($_POST['email'] ?? ''));
            $funcao = trim($_POST['funcao'] ?? 'operador');
            $pin = trim($_POST['pin'] ?? '');
            $status = trim($_POST['status'] ?? 'ativo');
            $avatarCor = trim($_POST['avatar_cor'] ?? '#3b82f6');

            if (empty($nome)) {
                jsonError("O nome completo é obrigatório.");
            }
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                jsonError("Informe um endereço de e-mail válido.");
            }

            $funcoesValidas = ['admin', 'supervisor', 'conferente', 'operador'];
            if (!in_array($funcao, $funcoesValidas)) {
                $funcao = 'operador';
            }
            $status = ($status === 'inativo') ? 'inativo' : 'ativo';

            // Verificar existência
            $userStmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
            $userStmt->execute([$id]);
            $userAtual = $userStmt->fetch();
            if (!$userAtual) {
                jsonError("Usuário não encontrado.", 404);
            }

            // Verificar se o e-mail pertence a outro usuário
            $checkStmt = $db->prepare("SELECT id FROM usuarios WHERE email = ? AND id != ?");
            $checkStmt->execute([$email, $id]);
            if ($checkStmt->fetch()) {
                jsonError("O e-mail '$email' já está sendo utilizado por outro usuário.");
            }

            // Se for inativar ou mudar cargo, garantir que não é o único admin ativo
            if (($status === 'inativo' || $funcao !== 'admin') && $userAtual['funcao'] === 'admin') {
                $countAdmins = $db->query("SELECT COUNT(*) FROM usuarios WHERE funcao = 'admin' AND status = 'ativo' AND id != $id")->fetchColumn();
                if ($countAdmins == 0) {
                    jsonError("Operação bloqueada: o sistema deve possuir pelo menos um Administrador ativo.");
                }
            }

            if ($pin !== '') {
                $stmt = $db->prepare("
                    UPDATE usuarios SET
                        nome = ?,
                        email = ?,
                        funcao = ?,
                        pin = ?,
                        status = ?,
                        avatar_cor = ?,
                        atualizado_em = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$nome, $email, $funcao, $pin, $status, $avatarCor, $id]);
            } else {
                $stmt = $db->prepare("
                    UPDATE usuarios SET
                        nome = ?,
                        email = ?,
                        funcao = ?,
                        status = ?,
                        avatar_cor = ?,
                        atualizado_em = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$nome, $email, $funcao, $status, $avatarCor, $id]);
            }

            jsonResponse([
                'success' => true,
                'message' => "Usuário '$nome' atualizado com sucesso!"
            ]);
            break;

        // -------------------------------------------------------------
        // TOGGLE_STATUS: Alternar ativo/inativo
        // -------------------------------------------------------------
        case 'toggle_status':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) {
                jsonError("ID do usuário não informado.");
            }

            $stmt = $db->prepare("SELECT id, nome, funcao, status FROM usuarios WHERE id = ?");
            $stmt->execute([$id]);
            $user = $stmt->fetch();
            if (!$user) {
                jsonError("Usuário não encontrado.", 404);
            }

            $novoStatus = ($user['status'] === 'ativo') ? 'inativo' : 'ativo';

            // Segurança: não inativar o único admin
            if ($novoStatus === 'inativo' && $user['funcao'] === 'admin') {
                $countAdmins = $db->query("SELECT COUNT(*) FROM usuarios WHERE funcao = 'admin' AND status = 'ativo' AND id != $id")->fetchColumn();
                if ($countAdmins == 0) {
                    jsonError("Não é possível inativar o único Administrador ativo do sistema.");
                }
            }

            $update = $db->prepare("UPDATE usuarios SET status = ?, atualizado_em = CURRENT_TIMESTAMP WHERE id = ?");
            $update->execute([$novoStatus, $id]);

            jsonResponse([
                'success' => true,
                'message' => "Status de '{$user['nome']}' alterado para " . ($novoStatus === 'ativo' ? 'Ativo' : 'Inativo') . ".",
                'novo_status' => $novoStatus
            ]);
            break;

        // -------------------------------------------------------------
        // DELETE: Excluir usuário
        // -------------------------------------------------------------
        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) {
                jsonError("ID do usuário não informado.");
            }

            $stmt = $db->prepare("SELECT id, nome, funcao, status FROM usuarios WHERE id = ?");
            $stmt->execute([$id]);
            $user = $stmt->fetch();
            if (!$user) {
                jsonError("Usuário não encontrado.", 404);
            }

            // Segurança: não excluir o único admin
            if ($user['funcao'] === 'admin') {
                $countAdmins = $db->query("SELECT COUNT(*) FROM usuarios WHERE funcao = 'admin' AND id != $id")->fetchColumn();
                if ($countAdmins == 0) {
                    jsonError("Não é possível excluir o único Administrador do sistema.");
                }
            }

            $delStmt = $db->prepare("DELETE FROM usuarios WHERE id = ?");
            $delStmt->execute([$id]);

            jsonResponse([
                'success' => true,
                'message' => "Usuário '{$user['nome']}' excluído com sucesso."
            ]);
            break;

        // -------------------------------------------------------------
        // ACTIVE_OPERATORS: Lista simplificada de operadores ativos
        // -------------------------------------------------------------
        case 'active_operators':
            $stmt = $db->query("
                SELECT id, nome, email, funcao, avatar_cor 
                FROM usuarios 
                WHERE status = 'ativo' 
                ORDER BY CASE WHEN funcao IN ('operador', 'conferente') THEN 0 ELSE 1 END, nome ASC
            ");
            $operadores = $stmt->fetchAll();

            jsonResponse([
                'success' => true,
                'data' => $operadores
            ]);
            break;

        // -------------------------------------------------------------
        // LOGIN: Autenticar usuário por email e senha (pin)
        // -------------------------------------------------------------
        case 'login':
            $email = trim(strtolower($_POST['email'] ?? ''));
            $senha = trim($_POST['senha'] ?? $_POST['password'] ?? $_POST['pin'] ?? '');

            if (empty($email) || empty($senha)) {
                jsonError("E-mail e senha são obrigatórios.");
            }

            $stmt = $db->prepare("SELECT id, nome, email, funcao, pin, status, avatar_cor FROM usuarios WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user) {
                jsonError("E-mail ou senha incorretos.");
            }

            if ($user['status'] !== 'ativo') {
                jsonError("Este usuário está inativo. Contate um administrador.");
            }

            if ($user['pin'] !== $senha) {
                jsonError("E-mail ou senha incorretos.");
            }

            // Atualizar último acesso
            $upStmt = $db->prepare("UPDATE usuarios SET ultimo_acesso = CURRENT_TIMESTAMP WHERE id = ?");
            $upStmt->execute([$user['id']]);

            // Definir sessão
            $_SESSION['wms_user'] = [
                'id' => $user['id'],
                'nome' => $user['nome'],
                'email' => $user['email'],
                'funcao' => $user['funcao'],
                'avatar_cor' => $user['avatar_cor']
            ];

            jsonResponse([
                'success' => true,
                'message' => "Login efetuado com sucesso!",
                'user' => $_SESSION['wms_user']
            ]);
            break;

        // -------------------------------------------------------------
        // LOGOUT: Destruir sessão
        // -------------------------------------------------------------
        case 'logout':
            $_SESSION = [];
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            session_destroy();

            jsonResponse([
                'success' => true,
                'message' => "Sessão encerrada com sucesso."
            ]);
            break;

        // -------------------------------------------------------------
        // ME: Verificar sessão ativa
        // -------------------------------------------------------------
        case 'me':
            if (isset($_SESSION['wms_user'])) {
                jsonResponse([
                    'success' => true,
                    'user' => $_SESSION['wms_user']
                ]);
            } else {
                jsonError("Não autenticado.", 401);
            }
            break;

        default:
            jsonError("Ação '$action' desconhecida.");
    }
} catch (Exception $e) {
    jsonError("Erro em usuários: " . $e->getMessage(), 500);
}
