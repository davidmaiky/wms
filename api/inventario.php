<?php
/**
 * WMS PRIME PRO - API de Inventário Cíclico & Contagem de Estoque (Cycle Counting)
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';

try {
    $db = getDB();
    $action = $_GET['action'] ?? 'listar';
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($action) {

        // =====================================================================
        // LISTAR INVENTÁRIOS & KPIS
        // =====================================================================
        case 'listar':
            requirePermission('inventario_visualizar');

            $status = $_GET['status'] ?? '';
            $busca = trim($_GET['busca'] ?? '');
            $limite = (int)($_GET['limite'] ?? 50);

            $sql = "SELECT i.*, 
                           (SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = i.id) as total_posicoes_skus,
                           (SELECT COUNT(*) FROM inventario_itens WHERE inventario_id = i.id AND divergencia != 0) as total_divergencias
                    FROM inventarios i WHERE 1=1";
            $params = [];

            if (!empty($status)) {
                $sql .= " AND i.status = ?";
                $params[] = $status;
            }

            if (!empty($busca)) {
                $sql .= " AND (i.titulo LIKE ? OR i.armazem LIKE ? OR i.rua_inicio LIKE ? OR i.operador LIKE ?)";
                $termo = "%$busca%";
                $params[] = $termo;
                $params[] = $termo;
                $params[] = $termo;
                $params[] = $termo;
            }

            $sql .= " ORDER BY i.id DESC LIMIT " . max(1, min(200, $limite));
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $inventarios = $stmt->fetchAll();

            // KPIs
            $statsStmt = $db->query("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'pendente' THEN 1 ELSE 0 END) as pendentes,
                    SUM(CASE WHEN status = 'em_contagem' THEN 1 ELSE 0 END) as em_contagem,
                    SUM(CASE WHEN status IN ('finalizado', 'ajustado') THEN 1 ELSE 0 END) as finalizados,
                    AVG(CASE WHEN status IN ('finalizado', 'ajustado') THEN acuracidade_pct ELSE NULL END) as acuracidade_media
                FROM inventarios
            ");
            $stats = $statsStmt->fetch() ?: [
                'total' => 0, 'pendentes' => 0, 'em_contagem' => 0, 'finalizados' => 0, 'acuracidade_media' => 100
            ];
            $stats['acuracidade_media'] = round((float)($stats['acuracidade_media'] ?? 100), 1);

            echo json_encode([
                'success' => true,
                'stats' => $stats,
                'inventarios' => $inventarios
            ]);
            break;

        // =====================================================================
        // OBTER DETALHES DE UM INVENTÁRIO (ITENS, LOCAIS E LOGS)
        // =====================================================================
        case 'obter':
            requirePermission('inventario_visualizar');

            $id = (int)($_GET['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'ID do inventário não informado.']);
                exit;
            }

            $stmt = $db->prepare("SELECT * FROM inventarios WHERE id = ?");
            $stmt->execute([$id]);
            $inv = $stmt->fetch();

            if (!$inv) {
                echo json_encode(['success' => false, 'error' => 'Inventário não encontrado.']);
                exit;
            }

            // Buscar itens com dados de endereço
            $stmtItens = $db->prepare("
                SELECT ii.*, l.codigo as local_codigo, l.armazem, l.rua, l.estante, l.nivel, l.posicao, l.tipo as local_tipo
                FROM inventario_itens ii
                JOIN locais_armazenagem l ON l.id = ii.local_id
                WHERE ii.inventario_id = ?
                ORDER BY l.rua ASC, l.estante ASC, l.nivel ASC, l.posicao ASC, ii.codigo_produto ASC
            ");
            $stmtItens->execute([$id]);
            $itens = $stmtItens->fetchAll();

            // Buscar logs de bipagem recentes
            $stmtLogs = $db->prepare("
                SELECT * FROM logs_bipagem_inventario
                WHERE inventario_id = ?
                ORDER BY id DESC LIMIT 50
            ");
            $stmtLogs->execute([$id]);
            $logs = $stmtLogs->fetchAll();

            echo json_encode([
                'success' => true,
                'inventario' => $inv,
                'itens' => $itens,
                'logs' => $logs
            ]);
            break;

        // =====================================================================
        // CRIAR NOVA SESSÃO DE INVENTÁRIO CÍCLICO
        // =====================================================================
        case 'criar':
            requirePermission('inventario_criar');

            if ($method !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'Método não permitido.']);
                exit;
            }

            $data = json_decode(file_get_contents('php://input'), true);
            $titulo = trim($data['titulo'] ?? '');
            $tipo = $data['tipo'] ?? 'localizacao'; // localizacao, sku, total
            $modo = $data['modo'] ?? 'cego'; // cego, aberto
            $armazem = trim($data['armazem'] ?? 'Principal');
            $rua_inicio = strtoupper(trim($data['rua_inicio'] ?? 'A'));
            $rua_fim = strtoupper(trim($data['rua_fim'] ?? $rua_inicio));
            $skus_filtro = $data['skus'] ?? [];

            if (empty($titulo)) {
                $titulo = "Inventário Cíclico " . date('d/m/Y H:i');
            }

            $db->beginTransaction();

            $stmtInv = $db->prepare("
                INSERT INTO inventarios (titulo, tipo, modo, armazem, rua_inicio, rua_fim, status, operador)
                VALUES (?, ?, ?, ?, ?, ?, 'pendente', ?)
            ");
            $operador = $_SESSION['wms_user']['nome'] ?? 'Supervisor';
            $stmtInv->execute([$titulo, $tipo, $modo, $armazem, $rua_inicio, $rua_fim, $operador]);
            $invId = $db->lastInsertId();

            // Carregar posições e produtos alocados para o inventário
            if ($tipo === 'localizacao') {
                $sqlLoc = "
                    SELECT pe.local_id, pe.codigo_produto, pe.quantidade_atual,
                           pc.descricao, pc.ean
                    FROM produtos_enderecos pe
                    JOIN locais_armazenagem l ON l.id = pe.local_id
                    LEFT JOIN produtos_cache pc ON pc.codigo = pe.codigo_produto
                    WHERE l.armazem = ? AND l.rua BETWEEN ? AND ?
                ";
                $stmtLoc = $db->prepare($sqlLoc);
                $stmtLoc->execute([$armazem, $rua_inicio, $rua_fim]);
                $alocacoes = $stmtLoc->fetchAll();
            } else if ($tipo === 'sku' && !empty($skus_filtro)) {
                $placeholders = str_repeat('?,', count($skus_filtro) - 1) . '?';
                $sqlSku = "
                    SELECT pe.local_id, pe.codigo_produto, pe.quantidade_atual,
                           pc.descricao, pc.ean
                    FROM produtos_enderecos pe
                    LEFT JOIN produtos_cache pc ON pc.codigo = pe.codigo_produto
                    WHERE pe.codigo_produto IN ($placeholders)
                ";
                $stmtSku = $db->prepare($sqlSku);
                $stmtSku->execute($skus_filtro);
                $alocacoes = $stmtSku->fetchAll();
            } else {
                // Total
                $sqlTotal = "
                    SELECT pe.local_id, pe.codigo_produto, pe.quantidade_atual,
                           pc.descricao, pc.ean
                    FROM produtos_enderecos pe
                    LEFT JOIN produtos_cache pc ON pc.codigo = pe.codigo_produto
                ";
                $alocacoes = $db->query($sqlTotal)->fetchAll();
            }

            $totalEsperado = 0;
            $stmtInsItem = $db->prepare("
                INSERT INTO inventario_itens (inventario_id, local_id, codigo_produto, ean, descricao, quantidade_sistema, quantidade_contada, divergencia, status)
                VALUES (?, ?, ?, ?, ?, ?, 0, ?, 'pendente')
            ");

            foreach ($alocacoes as $aloc) {
                $qtdSis = (float)($aloc['quantidade_atual'] ?? 0);
                $totalEsperado += $qtdSis;
                $diverg = 0 - $qtdSis;
                $stmtInsItem->execute([
                    $invId,
                    $aloc['local_id'],
                    $aloc['codigo_produto'],
                    $aloc['ean'] ?? '',
                    $aloc['descricao'] ?? $aloc['codigo_produto'],
                    $qtdSis,
                    $diverg
                ]);
            }

            // Se não houver itens cadastrados nas posições mas houver posições físicas
            if (empty($alocacoes) && $tipo === 'localizacao') {
                $locaisVazios = $db->prepare("SELECT id FROM locais_armazenagem WHERE armazem = ? AND rua BETWEEN ? AND ?");
                $locaisVazios->execute([$armazem, $rua_inicio, $rua_fim]);
                $locaisIds = $locaisVazios->fetchAll(PDO::FETCH_COLUMN);

                foreach ($locaisIds as $lid) {
                    $stmtInsItem->execute([$invId, $lid, 'GERAL', '', 'Posição física sem SKU alocado', 0, 0]);
                }
            }

            $stmtUpdTotal = $db->prepare("UPDATE inventarios SET total_itens_esperados = ? WHERE id = ?");
            $stmtUpdTotal->execute([$totalEsperado, $invId]);

            $db->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Ordem de inventário criada com sucesso!',
                'inventario_id' => $invId
            ]);
            break;

        // =====================================================================
        // INICIAR CONTAGEM DE INVENTÁRIO
        // =====================================================================
        case 'iniciar':
            requirePermission('inventario_contar');

            if ($method !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'Método não permitido.']);
                exit;
            }

            $data = json_decode(file_get_contents('php://input'), true);
            $id = (int)($data['id'] ?? 0);
            $operador = trim($data['operador'] ?? ($_SESSION['wms_user']['nome'] ?? 'Operador'));

            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'ID não informado.']);
                exit;
            }

            $stmt = $db->prepare("UPDATE inventarios SET status = 'em_contagem', operador = ? WHERE id = ? AND status = 'pendente'");
            $stmt->execute([$operador, $id]);

            // Retornar dados completos
            $_GET['id'] = $id;
            header("Location: inventario.php?action=obter&id=$id");
            break;

        // =====================================================================
        // BIPAR PRODUTO / LOCALIZAÇÃO NA CONTAGEM
        // =====================================================================
        case 'bipar':
            requirePermission('inventario_contar');

            if ($method !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'Método não permitido.']);
                exit;
            }

            $data = json_decode(file_get_contents('php://input'), true);
            $invId = (int)($data['inventario_id'] ?? 0);
            $localCodigo = trim($data['local_codigo'] ?? '');
            $codigoBipado = trim($data['codigo_bipado'] ?? '');
            $qtd = max(1, (float)($data['quantidade'] ?? 1));
            $tipoLeitura = $data['tipo_leitura'] ?? 'camera';
            $operador = trim($data['operador'] ?? ($_SESSION['wms_user']['nome'] ?? 'Operador'));

            if (!$invId || empty($codigoBipado)) {
                echo json_encode(['success' => false, 'error' => 'Inventário e código de barras são obrigatórios.']);
                exit;
            }

            // Identificar produto por EAN, De-para ou SKU
            $produtoIdentificado = null;

            // 1. Busca direta por SKU
            $stmtSku = $db->prepare("SELECT codigo, descricao, ean FROM produtos_cache WHERE codigo = ?");
            $stmtSku->execute([$codigoBipado]);
            $produtoIdentificado = $stmtSku->fetch();

            // 2. Busca por EAN nativo
            if (!$produtoIdentificado) {
                $stmtEan = $db->prepare("SELECT codigo, descricao, ean FROM produtos_cache WHERE ean = ?");
                $stmtEan->execute([$codigoBipado]);
                $produtoIdentificado = $stmtEan->fetch();
            }

            // 3. Busca por EAN adicional
            if (!$produtoIdentificado) {
                $stmtCustom = $db->prepare("
                    SELECT pc.codigo, pc.descricao, pc.ean 
                    FROM produtos_ean_custom pe 
                    JOIN produtos_cache pc ON pc.codigo = pe.codigo_produto 
                    WHERE pe.ean_adicional = ?
                ");
                $stmtCustom->execute([$codigoBipado]);
                $produtoIdentificado = $stmtCustom->fetch();
            }

            $skuFinal = $produtoIdentificado ? $produtoIdentificado['codigo'] : $codigoBipado;
            $descFinal = $produtoIdentificado ? $produtoIdentificado['descricao'] : $codigoBipado;
            $eanFinal = $produtoIdentificado ? $produtoIdentificado['ean'] : '';

            // Identificar o Local de Armazenagem
            $localId = null;
            if (!empty($localCodigo)) {
                $stmtLoc = $db->prepare("SELECT id FROM locais_armazenagem WHERE codigo = ?");
                $stmtLoc->execute([$localCodigo]);
                $localId = $stmtLoc->fetchColumn() ?: null;
            }

            // Se não informou local, tentar achar o local principal do SKU
            if (!$localId) {
                $stmtLocProd = $db->prepare("
                    SELECT local_id FROM produtos_enderecos WHERE codigo_produto = ? ORDER BY (tipo='principal') DESC LIMIT 1
                ");
                $stmtLocProd->execute([$skuFinal]);
                $localId = $stmtLocProd->fetchColumn() ?: null;
            }

            // Se ainda não achou local, pega o primeiro local do inventário
            if (!$localId) {
                $stmtPrimLoc = $db->prepare("SELECT local_id FROM inventario_itens WHERE inventario_id = ? LIMIT 1");
                $stmtPrimLoc->execute([$invId]);
                $localId = $stmtPrimLoc->fetchColumn() ?: 1;
            }

            $db->beginTransaction();

            // Verificar se já existe o item na tabela do inventário
            $stmtItem = $db->prepare("
                SELECT id, quantidade_sistema, quantidade_contada 
                FROM inventario_itens 
                WHERE inventario_id = ? AND local_id = ? AND codigo_produto = ?
            ");
            $stmtItem->execute([$invId, $localId, $skuFinal]);
            $itemExistente = $stmtItem->fetch();

            if ($itemExistente) {
                $novaQtdContada = (float)$itemExistente['quantidade_contada'] + $qtd;
                $qtdSistema = (float)$itemExistente['quantidade_sistema'];
                $novaDivergencia = $novaQtdContada - $qtdSistema;
                $statusItem = ($novaDivergencia == 0) ? 'contado' : 'divergente';

                $stmtUpd = $db->prepare("
                    UPDATE inventario_itens 
                    SET quantidade_contada = ?, divergencia = ?, status = ?, contado_por = ?, contado_em = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmtUpd->execute([$novaQtdContada, $novaDivergencia, $statusItem, $operador, $itemExistente['id']]);
            } else {
                // Item inesperado na posição física (sobra ou item fora de lugar)
                $novaQtdContada = $qtd;
                $qtdSistema = 0;
                $novaDivergencia = $qtd;

                $stmtIns = $db->prepare("
                    INSERT INTO inventario_itens (inventario_id, local_id, codigo_produto, ean, descricao, quantidade_sistema, quantidade_contada, divergencia, status, contado_por, contado_em)
                    VALUES (?, ?, ?, ?, ?, 0, ?, ?, 'divergente', ?, CURRENT_TIMESTAMP)
                ");
                $stmtIns->execute([$invId, $localId, $skuFinal, $eanFinal, $descFinal, $novaQtdContada, $novaDivergencia, $operador]);
            }

            // Registrar log de auditoria
            $stmtLog = $db->prepare("
                INSERT INTO logs_bipagem_inventario (inventario_id, local_id, codigo_bipado, codigo_produto_identificado, tipo_leitura, operador)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmtLog->execute([$invId, $localId, $codigoBipado, $skuFinal, $tipoLeitura, $operador]);

            // Atualizar total contados no inventário
            $stmtTot = $db->prepare("
                UPDATE inventarios 
                SET total_itens_contados = (SELECT SUM(quantidade_contada) FROM inventario_itens WHERE inventario_id = ?)
                WHERE id = ?
            ");
            $stmtTot->execute([$invId, $invId]);

            $db->commit();

            // Retornar inventário atualizado
            $stmtInv = $db->prepare("SELECT * FROM inventarios WHERE id = ?");
            $stmtInv->execute([$invId]);
            $invAtualizado = $stmtInv->fetch();

            $stmtItens = $db->prepare("
                SELECT ii.*, l.codigo as local_codigo, l.armazem, l.rua, l.estante, l.nivel, l.posicao
                FROM inventario_itens ii
                JOIN locais_armazenagem l ON l.id = ii.local_id
                WHERE ii.inventario_id = ?
                ORDER BY l.rua ASC, l.estante ASC, l.nivel ASC, l.posicao ASC, ii.codigo_produto ASC
            ");
            $stmtItens->execute([$invId]);
            $itensAtualizados = $stmtItens->fetchAll();

            echo json_encode([
                'success' => true,
                'message' => "Item bipado: $skuFinal (+$qtd un)",
                'item_bipado' => $skuFinal,
                'inventario' => $invAtualizado,
                'itens' => $itensAtualizados
            ]);
            break;

        // =====================================================================
        // FINALIZAR CONTAGEM & APURAR ACURACIDADE (IRA)
        // =====================================================================
        case 'finalizar':
            requirePermission('inventario_contar');

            if ($method !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'Método não permitido.']);
                exit;
            }

            $data = json_decode(file_get_contents('php://input'), true);
            $id = (int)($data['id'] ?? 0);

            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'ID não informado.']);
                exit;
            }

            // Calcular acuracidade
            $stmtTotais = $db->prepare("
                SELECT 
                    COUNT(*) as total_linhas,
                    SUM(CASE WHEN divergencia = 0 THEN 1 ELSE 0 END) as linhas_acuradas,
                    SUM(quantidade_sistema) as total_esperado,
                    SUM(quantidade_contada) as total_contado,
                    SUM(ABS(divergencia)) as total_divergencia_abs
                FROM inventario_itens WHERE inventario_id = ?
            ");
            $stmtTotais->execute([$id]);
            $t = $stmtTotais->fetch();

            $totalLinhas = max(1, (int)($t['total_linhas'] ?? 1));
            $linhasAcuradas = (int)($t['linhas_acuradas'] ?? 0);
            $acuracidade = round(($linhasAcuradas / $totalLinhas) * 100, 1);

            $stmtUpd = $db->prepare("
                UPDATE inventarios 
                SET status = 'finalizado', 
                    acuracidade_pct = ?,
                    total_itens_contados = ?,
                    finalizado_em = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $stmtUpd->execute([$acuracidade, (float)($t['total_contado'] ?? 0), $id]);

            echo json_encode([
                'success' => true,
                'message' => "Inventário finalizado com sucesso! Acuracidade apurada: {$acuracidade}%.",
                'acuracidade_pct' => $acuracidade
            ]);
            break;

        // =====================================================================
        // APROVAR CONCILIAÇÃO & AJUSTAR SALDOS NAS PRATELEIRAS
        // =====================================================================
        case 'aprovar_ajustes':
            requirePermission('inventario_aprovar');

            if ($method !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'Método não permitido.']);
                exit;
            }

            $data = json_decode(file_get_contents('php://input'), true);
            $id = (int)($data['id'] ?? 0);

            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'ID não informado.']);
                exit;
            }

            $stmtItens = $db->prepare("SELECT * FROM inventario_itens WHERE inventario_id = ?");
            $stmtItens->execute([$id]);
            $itens = $stmtItens->fetchAll();

            $db->beginTransaction();

            foreach ($itens as $it) {
                if ($it['codigo_produto'] === 'GERAL') continue;

                $qtdNova = (float)$it['quantidade_contada'];

                // Atualizar ou inserir saldo em produtos_enderecos
                $stmtUpsert = $db->prepare("
                    INSERT INTO produtos_enderecos (codigo_produto, local_id, tipo, quantidade_atual, atualizado_em)
                    VALUES (?, ?, 'principal', ?, CURRENT_TIMESTAMP)
                    ON CONFLICT(codigo_produto, local_id) DO UPDATE SET
                        quantidade_atual = excluded.quantidade_atual,
                        atualizado_em = CURRENT_TIMESTAMP
                ");
                $stmtUpsert->execute([$it['codigo_produto'], $it['local_id'], $qtdNova]);

                $db->prepare("UPDATE inventario_itens SET status = 'ajustado' WHERE id = ?")->execute([$it['id']]);
            }

            $db->prepare("UPDATE inventarios SET status = 'ajustado' WHERE id = ?")->execute([$id]);

            $db->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Saldos de estoque conciliados e atualizados nas prateleiras com sucesso!'
            ]);
            break;

        // =====================================================================
        // CANCELAR INVENTÁRIO
        // =====================================================================
        case 'cancelar':
            requirePermission('inventario_criar');

            $data = json_decode(file_get_contents('php://input'), true);
            $id = (int)($data['id'] ?? 0);

            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'ID não informado.']);
                exit;
            }

            $stmt = $db->prepare("UPDATE inventarios SET status = 'cancelado' WHERE id = ?");
            $stmt->execute([$id]);

            echo json_encode(['success' => true, 'message' => 'Inventário cancelado.']);
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
