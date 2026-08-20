<?php
/**
 * WMS PRIME PRO - API de Gestão de Devoluções & Logística Reversa (Returns Management)
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';

try {
    $db = getDB();
    $action = $_GET['action'] ?? 'listar';
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($action) {

        // =====================================================================
        // LISTAR DEVOLUÇÕES & KPIS
        // =====================================================================
        case 'listar':
            requirePermission('devolucao_visualizar');

            $status = $_GET['status'] ?? '';
            $motivo = $_GET['motivo'] ?? '';
            $busca = trim($_GET['busca'] ?? '');
            $limite = (int)($_GET['limite'] ?? 50);

            $sql = "SELECT d.*,
                           (SELECT COUNT(*) FROM devolucao_itens WHERE devolucao_id = d.id) as total_itens,
                           (SELECT SUM(quantidade) FROM devolucao_itens WHERE devolucao_id = d.id) as total_unidades
                    FROM devolucoes d WHERE 1=1";
            $params = [];

            if (!empty($status)) {
                $sql .= " AND d.status = ?";
                $params[] = $status;
            }

            if (!empty($motivo)) {
                $sql .= " AND d.motivo_principal = ?";
                $params[] = $motivo;
            }

            if (!empty($busca)) {
                $sql .= " AND (d.numero_pedido_origem LIKE ? OR d.cliente_nome LIKE ? OR d.codigo_rastreio LIKE ? OR d.operador LIKE ?)";
                $termo = "%$busca%";
                $params[] = $termo;
                $params[] = $termo;
                $params[] = $termo;
                $params[] = $termo;
            }

            $sql .= " ORDER BY d.id DESC LIMIT " . max(1, min(200, $limite));
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $devolucoes = $stmt->fetchAll();

            // KPIs
            $statsStmt = $db->query("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'recebido' THEN 1 ELSE 0 END) as recebidos,
                    SUM(CASE WHEN status = 'em_inspecao' THEN 1 ELSE 0 END) as em_inspecao,
                    SUM(CASE WHEN status = 'concluido' THEN 1 ELSE 0 END) as concluidos,
                    SUM(CASE WHEN status = 'rejeitado' THEN 1 ELSE 0 END) as rejeitados
                FROM devolucoes
            ");
            $stats = $statsStmt->fetch() ?: [
                'total' => 0, 'recebidos' => 0, 'em_inspecao' => 0, 'concluidos' => 0, 'rejeitados' => 0
            ];

            // Motivos mais frequentes
            $motivosStmt = $db->query("
                SELECT motivo_principal, COUNT(*) as qtd
                FROM devolucoes
                WHERE motivo_principal IS NOT NULL AND motivo_principal != ''
                GROUP BY motivo_principal
                ORDER BY qtd DESC LIMIT 5
            ");
            $motivosTop = $motivosStmt->fetchAll();

            echo json_encode([
                'success' => true,
                'stats' => $stats,
                'motivos_top' => $motivosTop,
                'devolucoes' => $devolucoes
            ]);
            break;

        // =====================================================================
        // OBTER DETALHES DE UMA DEVOLUÇÃO
        // =====================================================================
        case 'obter':
            requirePermission('devolucao_visualizar');

            $id = (int)($_GET['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'ID da devolução não informado.']);
                exit;
            }

            $stmt = $db->prepare("SELECT * FROM devolucoes WHERE id = ?");
            $stmt->execute([$id]);
            $dev = $stmt->fetch();

            if (!$dev) {
                echo json_encode(['success' => false, 'error' => 'Devolução não encontrada.']);
                exit;
            }

            // Itens com localização sugerida/atual
            $stmtItens = $db->prepare("
                SELECT di.*, l.codigo as local_codigo, l.armazem, l.rua, l.tipo as local_tipo
                FROM devolucao_itens di
                LEFT JOIN locais_armazenagem l ON l.id = di.local_armazenagem_id
                WHERE di.devolucao_id = ?
                ORDER BY di.id ASC
            ");
            $stmtItens->execute([$id]);
            $itens = $stmtItens->fetchAll();

            echo json_encode([
                'success' => true,
                'devolucao' => $dev,
                'itens' => $itens
            ]);
            break;

        // =====================================================================
        // BUSCAR PEDIDO DE ORIGEM (ITENS VENDIDOS E CLIENTE)
        // =====================================================================
        case 'buscar_pedido_origem':
            requirePermission('devolucao_criar');

            $numero = (int)($_GET['numero_pedido'] ?? 0);
            if (!$numero) {
                echo json_encode(['success' => false, 'error' => 'Número do pedido não informado.']);
                exit;
            }

            // Buscar conferência concluída ou pedido cache
            $stmtConf = $db->prepare("
                SELECT c.id as conferencia_id, c.numero_pedido, c.cliente, c.data_pedido, c.status
                FROM conferencias c
                WHERE c.numero_pedido = ?
                ORDER BY c.id DESC LIMIT 1
            ");
            $stmtConf->execute([$numero]);
            $pedido = $stmtConf->fetch();

            $itens = [];
            if ($pedido && !empty($pedido['conferencia_id'])) {
                $stmtItens = $db->prepare("
                    SELECT ci.codigo_produto, ci.ean, ci.descricao, ci.quantidade_conferida as quantidade, ci.unidade
                    FROM conferencia_itens ci
                    WHERE ci.conferencia_id = ?
                ");
                $stmtItens->execute([$pedido['conferencia_id']]);
                $itens = $stmtItens->fetchAll();
            }

            echo json_encode([
                'success' => true,
                'pedido' => $pedido,
                'itens' => $itens
            ]);
            break;

        // =====================================================================
        // REGISTRAR ENTRADA DE DEVOLUÇÃO
        // =====================================================================
        case 'criar':
            requirePermission('devolucao_criar');

            if ($method !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'Método não permitido.']);
                exit;
            }

            $data = json_decode(file_get_contents('php://input'), true);
            $numPedido = !empty($data['numero_pedido_origem']) ? (int)$data['numero_pedido_origem'] : null;
            $clienteNome = trim($data['cliente_nome'] ?? 'Cliente');
            $rastreio = trim($data['codigo_rastreio'] ?? '');
            $motivoPrincipal = trim($data['motivo_principal'] ?? 'arrependimento');
            $observacoes = trim($data['observacoes'] ?? '');
            $itens = $data['itens'] ?? [];

            if (empty($itens)) {
                echo json_encode(['success' => false, 'error' => 'Adicione pelo menos um item na ordem de devolução.']);
                exit;
            }

            $db->beginTransaction();

            $operador = $_SESSION['wms_user']['nome'] ?? 'Operador';
            $stmtDev = $db->prepare("
                INSERT INTO devolucoes (numero_pedido_origem, cliente_nome, codigo_rastreio, motivo_principal, status, operador, observacoes)
                VALUES (?, ?, ?, ?, 'recebido', ?, ?)
            ");
            $stmtDev->execute([$numPedido, $clienteNome, $rastreio, $motivoPrincipal, $operador, $observacoes]);
            $devId = $db->lastInsertId();

            $stmtItem = $db->prepare("
                INSERT INTO devolucao_itens (devolucao_id, codigo_produto, ean, descricao, quantidade, condicao, motivo, acao_destinatario, local_armazenagem_id, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendente')
            ");

            foreach ($itens as $it) {
                $sku = trim($it['codigo_produto'] ?? '');
                $ean = trim($it['ean'] ?? '');
                $desc = trim($it['descricao'] ?? $sku);
                $qtd = max(1, (float)($it['quantidade'] ?? 1));
                $condicao = $it['condicao'] ?? 'perfeito';
                $motivo = $it['motivo'] ?? $motivoPrincipal;
                $acao = $it['acao_destinatario'] ?? ($condicao === 'perfeito' ? 'reestocar_picking' : 'quarentena');

                // Achar local sugerido
                $localSugeridoId = null;
                $stmtLoc = $db->prepare("
                    SELECT local_id FROM produtos_enderecos WHERE codigo_produto = ? ORDER BY (tipo='principal') DESC LIMIT 1
                ");
                $stmtLoc->execute([$sku]);
                $localSugeridoId = $stmtLoc->fetchColumn() ?: null;

                $stmtItem->execute([
                    $devId, $sku, $ean, $desc, $qtd, $condicao, $motivo, $acao, $localSugeridoId
                ]);
            }

            $db->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Ordem de devolução registrada com sucesso!',
                'devolucao_id' => $devId
            ]);
            break;

        // =====================================================================
        // INSPEÇÃO DE QUALIDADE DO ITEM DEVOLVIDO
        // =====================================================================
        case 'inspecionar_item':
            requirePermission('devolucao_inspecionar');

            if ($method !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'Método não permitido.']);
                exit;
            }

            $data = json_decode(file_get_contents('php://input'), true);
            $itemId = (int)($data['item_id'] ?? 0);
            $condicao = $data['condicao'] ?? 'perfeito';
            $motivo = trim($data['motivo'] ?? '');
            $acao = $data['acao_destinatario'] ?? 'reestocar_picking';
            $localId = !empty($data['local_armazenagem_id']) ? (int)$data['local_armazenagem_id'] : null;
            $operador = $_SESSION['wms_user']['nome'] ?? 'Conferente';

            if (!$itemId) {
                echo json_encode(['success' => false, 'error' => 'ID do item não informado.']);
                exit;
            }

            $stmt = $db->prepare("
                UPDATE devolucao_itens 
                SET condicao = ?, motivo = ?, acao_destinatario = ?, local_armazenagem_id = ?, 
                    inspecionado_por = ?, inspecionado_em = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$condicao, $motivo, $acao, $localId, $operador, $itemId]);

            // Atualizar status da devolução para 'em_inspecao'
            $stmtDevId = $db->prepare("SELECT devolucao_id FROM devolucao_itens WHERE id = ?");
            $stmtDevId->execute([$itemId]);
            $devId = $stmtDevId->fetchColumn();

            if ($devId) {
                $db->prepare("UPDATE devolucoes SET status = 'em_inspecao' WHERE id = ? AND status = 'recebido'")->execute([$devId]);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Inspeção do item atualizada com sucesso!'
            ]);
            break;

        // =====================================================================
        // CONCLUIR REESTOCAGEM (RESTOCK) & RETORNAR SALDOS AO WMS
        // =====================================================================
        case 'concluir_reestocagem':
            requirePermission('devolucao_inspecionar');

            if ($method !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'Método não permitido.']);
                exit;
            }

            $data = json_decode(file_get_contents('php://input'), true);
            $devId = (int)($data['devolucao_id'] ?? 0);

            if (!$devId) {
                echo json_encode(['success' => false, 'error' => 'ID da devolução não informado.']);
                exit;
            }

            $stmtItens = $db->prepare("SELECT * FROM devolucao_itens WHERE devolucao_id = ?");
            $stmtItens->execute([$devId]);
            $itens = $stmtItens->fetchAll();

            $db->beginTransaction();

            foreach ($itens as $it) {
                $acao = $it['acao_destinatario'];
                $qtd = (float)$it['quantidade'];
                $localId = $it['local_armazenagem_id'];

                if (($acao === 'reestocar_picking' || $acao === 'reestocar_pulmao' || $acao === 'quarentena') && $localId) {
                    // Incrementar estoque na posição física
                    $stmtUpsert = $db->prepare("
                        INSERT INTO produtos_enderecos (codigo_produto, local_id, tipo, quantidade_atual, atualizado_em)
                        VALUES (?, ?, 'principal', ?, CURRENT_TIMESTAMP)
                        ON CONFLICT(codigo_produto, local_id) DO UPDATE SET
                            quantidade_atual = produtos_enderecos.quantidade_atual + excluded.quantidade_atual,
                            atualizado_em = CURRENT_TIMESTAMP
                    ");
                    $stmtUpsert->execute([$it['codigo_produto'], $localId, $qtd]);

                    $db->prepare("UPDATE devolucao_itens SET status = 'reestocado' WHERE id = ?")->execute([$it['id']]);
                } else if ($acao === 'descarte') {
                    $db->prepare("UPDATE devolucao_itens SET status = 'descartado' WHERE id = ?")->execute([$it['id']]);
                }
            }

            $db->prepare("UPDATE devolucoes SET status = 'concluido', atualizado_em = CURRENT_TIMESTAMP WHERE id = ?")->execute([$devId]);

            $db->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Reestocagem e devolução concluídas com sucesso! Saldos de estoque atualizados.'
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => "Ação '$action' inválida."]);
            break;
    }

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
