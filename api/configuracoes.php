<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sige_client.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'get';
$db = getDB();

$inputJSON = json_decode(file_get_contents('php://input'), true);
if (is_array($inputJSON)) {
    $_POST = array_merge($_POST, $inputJSON);
}

try {
    switch ($action) {
        case 'get':
            requirePermission('config_visualizar');

            $stmt = $db->query("SELECT chave, valor FROM configuracoes");
            $configs = [];
            while ($row = $stmt->fetch()) {
                $configs[$row['chave']] = $row['valor'];
            }

            jsonResponse([
                'success' => true,
                'configuracoes' => $configs
            ]);
            break;

        case 'save':
            requirePermission('config_alterar');

            $chavesPermitidas = ['sige_token', 'sige_user', 'sige_app', 'som_habilitado', 'modo_cego', 'operador_padrao'];
            foreach ($chavesPermitidas as $chave) {
                if (isset($_POST[$chave])) {
                    setConfig($chave, (string)$_POST[$chave]);
                }
            }

            jsonResponse([
                'success' => true,
                'message' => 'Configurações salvas com sucesso!'
            ]);
            break;

        case 'test_sige':
            requirePermission('config_visualizar');

            $sige = new SigeClient();
            $res = $sige->testarConexao();
            jsonResponse($res);
            break;

        case 'list_eans':
            requirePermission('eans_visualizar');

            $stmt = $db->query("SELECT * FROM produtos_ean_custom ORDER BY id DESC");
            $eans = $stmt->fetchAll();
            jsonResponse([
                'success' => true,
                'data' => $eans
            ]);
            break;

        case 'save_ean':
            requirePermission('eans_gerenciar');

            $codigoProduto = trim($_POST['codigo_produto'] ?? '');
            $eanAdicional = trim($_POST['ean_adicional'] ?? '');
            $descricao = trim($_POST['descricao'] ?? '');

            if (empty($codigoProduto) || empty($eanAdicional)) {
                jsonError("Código do Produto (SKU) e Código de Barras (EAN) são obrigatórios.");
            }

            $stmt = $db->prepare("
                INSERT INTO produtos_ean_custom (codigo_produto, ean_adicional, descricao)
                VALUES (?, ?, ?)
                ON CONFLICT(ean_adicional) DO UPDATE SET
                    codigo_produto = excluded.codigo_produto,
                    descricao = excluded.descricao
            ");
            $stmt->execute([$codigoProduto, $eanAdicional, $descricao]);

            jsonResponse([
                'success' => true,
                'message' => "Código de barras $eanAdicional vinculado com sucesso ao SKU $codigoProduto!"
            ]);
            break;

        case 'delete_ean':
            requirePermission('eans_gerenciar');

            $id = (int)($_POST['id'] ?? 0);
            if (!$id) jsonError("ID não informado.");

            $stmt = $db->prepare("DELETE FROM produtos_ean_custom WHERE id = ?");
            $stmt->execute([$id]);

            jsonResponse([
                'success' => true,
                'message' => "Vínculo de código de barras excluído."
            ]);
            break;

        default:
            jsonError("Ação desconhecida.");
    }
} catch (Exception $e) {
    jsonError("Erro em configurações: " . $e->getMessage(), 500);
}
