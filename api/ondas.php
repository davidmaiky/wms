<?php
/**
 * WMS Prime - API de Separação em Onda / Lote (Wave Picking & Multi-Order Batch Picking)
 * Gerencia o agrupamento de pedidos, consolidação de itens por localização e distribuição em caixas (Put-to-Box).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$user = obterUsuarioAutenticado($pdo);

switch ($action) {
    case 'listar':
        exigirPermissao($pdo, $user, 'onda_visualizar');
        listarOndas($pdo);
        break;

    case 'obter':
        exigirPermissao($pdo, $user, 'onda_visualizar');
        obterOnda($pdo);
        break;

    case 'pedidos_disponiveis':
        exigirPermissao($pdo, $user, 'onda_criar');
        listarPedidosDisponiveis($pdo);
        break;

    case 'criar':
        exigirPermissao($pdo, $user, 'onda_criar');
        criarOnda($pdo, $user);
        break;

    case 'iniciar':
        exigirPermissao($pdo, $user, 'onda_separar');
        iniciarOnda($pdo, $user);
        break;

    case 'bipar':
        exigirPermissao($pdo, $user, 'onda_separar');
        biparOnda($pdo, $user);
        break;

    case 'finalizar':
        exigirPermissao($pdo, $user, 'onda_separar');
        finalizarOnda($pdo, $user);
        break;

    case 'cancelar':
        exigirPermissao($pdo, $user, 'onda_criar');
        cancelarOnda($pdo, $user);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ação inválida para ondas de separação.']);
        break;
}

/**
 * Lista ondas de separação com filtros de status e busca
 */
function listarOndas(PDO $pdo): void {
    $status = $_GET['status'] ?? '';
    $busca = trim($_GET['busca'] ?? '');
    $limite = (int)($_GET['limite'] ?? 50);

    $sql = "SELECT o.*,
                   (SELECT COUNT(*) FROM onda_pedidos op WHERE op.onda_id = o.id) AS qtd_pedidos,
                   (SELECT COUNT(*) FROM onda_itens_consolidados oic WHERE oic.onda_id = o.id) AS qtd_skus
            FROM ondas_separacao o
            WHERE 1=1";
    $params = [];

    if ($status !== '') {
        $sql .= " AND o.status = ?";
        $params[] = $status;
    }

    if ($busca !== '') {
        $sql .= " AND (o.codigo_onda LIKE ? OR o.operador LIKE ? OR o.observacoes LIKE ?)";
        $params[] = "%{$busca}%";
        $params[] = "%{$busca}%";
        $params[] = "%{$busca}%";
    }

    $sql .= " ORDER BY o.id DESC LIMIT " . max(1, min($limite, 200));

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $ondas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Estatísticas rápidas
    $stats = [
        'total' => (int)$pdo->query("SELECT COUNT(*) FROM ondas_separacao")->fetchColumn(),
        'pendentes' => (int)$pdo->query("SELECT COUNT(*) FROM ondas_separacao WHERE status = 'pendente'")->fetchColumn(),
        'em_separacao' => (int)$pdo->query("SELECT COUNT(*) FROM ondas_separacao WHERE status = 'em_separacao'")->fetchColumn(),
        'concluidas' => (int)$pdo->query("SELECT COUNT(*) FROM ondas_separacao WHERE status IN ('separado', 'concluido')")->fetchColumn()
    ];

    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'ondas' => $ondas
    ]);
}

/**
 * Obtém os detalhes completos de uma onda (pedidos, boxes, itens consolidados ordenados por rota e logs)
 */
function obterOnda(PDO $pdo): void {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'ID da onda inválido.']);
        return;
    }

    $stmt = $pdo->prepare("SELECT * FROM ondas_separacao WHERE id = ?");
    $stmt->execute([$id]);
    $onda = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$onda) {
        echo json_encode(['success' => false, 'error' => 'Onda não encontrada.']);
        return;
    }

    // Pedidos da onda
    $stmtPed = $pdo->prepare("SELECT * FROM onda_pedidos WHERE onda_id = ? ORDER BY caixa_box_numero ASC");
    $stmtPed->execute([$id]);
    $pedidos = $stmtPed->fetchAll(PDO::FETCH_ASSOC);

    // Itens consolidados ordenados por rota de endereçamento (Armazém -> Rua -> Estante -> Nível -> Posição)
    $stmtItens = $pdo->prepare("
        SELECT oic.*,
               la.codigo AS local_codigo,
               la.rua, la.estante, la.nivel, la.posicao, la.tipo AS local_tipo
        FROM onda_itens_consolidados oic
        LEFT JOIN locais_armazenagem la ON la.id = oic.local_id
        WHERE oic.onda_id = ?
        ORDER BY 
            CASE WHEN la.codigo IS NULL THEN 1 ELSE 0 END,
            la.rua ASC,
            la.estante ASC,
            la.nivel ASC,
            la.posicao ASC,
            oic.descricao ASC
    ");
    $stmtItens->execute([$id]);
    $itens = $stmtItens->fetchAll(PDO::FETCH_ASSOC);

    // Logs de bipagem recentes
    $stmtLogs = $pdo->prepare("SELECT * FROM logs_bipagem_onda WHERE onda_id = ? ORDER BY id DESC LIMIT 25");
    $stmtLogs->execute([$id]);
    $logs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'onda' => $onda,
        'pedidos' => $pedidos,
        'itens' => $itens,
        'logs' => $logs
    ]);
}

/**
 * Retorna pedidos disponíveis para agrupamento em nova onda
 */
function listarPedidosDisponiveis(PDO $pdo): void {
    // Busca conferências em aberto ou pedidos em cache que não estão em nenhuma onda ativa
    $sql = "
        SELECT c.id, c.numero_pedido, c.cliente, c.data_pedido, c.total_itens, c.status,
               (SELECT COUNT(*) FROM conferencia_itens ci WHERE ci.conferencia_id = c.id) as total_skus
        FROM conferencias c
        WHERE c.status IN ('pendente', 'parcial', 'em_conferencia')
          AND c.numero_pedido NOT IN (
              SELECT op.numero_pedido 
              FROM onda_pedidos op
              JOIN ondas_separacao o ON o.id = op.onda_id
              WHERE o.status IN ('pendente', 'em_separacao')
          )
        ORDER BY c.numero_pedido ASC
        LIMIT 100
    ";
    $pedidos = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'pedidos' => $pedidos
    ]);
}

/**
 * Cria uma nova onda de separação (Wave Picking) a partir de uma lista de pedidos
 */
function criarOnda(PDO $pdo, ?array $user): void {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $pedidosIds = $input['pedidos'] ?? []; // Array de números de pedidos
    $observacoes = trim($input['observacoes'] ?? '');
    $operador = $user['nome'] ?? 'Operador';

    if (empty($pedidosIds) || !is_array($pedidosIds)) {
        echo json_encode(['success' => false, 'error' => 'Selecione ao menos um pedido para criar a onda.']);
        return;
    }

    $pdo->beginTransaction();
    try {
        // Gerar código da onda: ONDA-{YYYYMMDD}-{SEQ}
        $dataHoje = date('Ymd');
        $countHoje = (int)$pdo->query("SELECT COUNT(*) FROM ondas_separacao WHERE codigo_onda LIKE 'ONDA-{$dataHoje}-%'")->fetchColumn() + 1;
        $codigoOnda = sprintf("ONDA-%s-%03d", $dataHoje, $countHoje);

        $stmtOnda = $pdo->prepare("
            INSERT INTO ondas_separacao (codigo_onda, status, total_pedidos, operador, observacoes)
            VALUES (?, 'pendente', ?, ?, ?)
        ");
        $stmtOnda->execute([$codigoOnda, count($pedidosIds), $operador, $observacoes]);
        $ondaId = (int)$pdo->lastInsertId();

        $boxNum = 1;
        $skusConsolidados = [];
        $totalUnidades = 0;

        $stmtOndaPed = $pdo->prepare("
            INSERT INTO onda_pedidos (onda_id, numero_pedido, cliente, caixa_box_numero, status)
            VALUES (?, ?, ?, ?, 'pendente')
        ");

        $stmtItensConf = $pdo->prepare("
            SELECT ci.codigo_produto, ci.ean, ci.descricao, ci.quantidade
            FROM conferencia_itens ci
            JOIN conferencias c ON c.id = ci.conferencia_id
            WHERE c.numero_pedido = ?
        ");

        foreach ($pedidosIds as $numPedido) {
            $numPedInt = (int)$numPedido;

            // Obter cliente
            $stmtCli = $pdo->prepare("SELECT cliente FROM conferencias WHERE numero_pedido = ? LIMIT 1");
            $stmtCli->execute([$numPedInt]);
            $clienteNome = $stmtCli->fetchColumn() ?: "Pedido #{$numPedInt}";

            $stmtOndaPed->execute([$ondaId, $numPedInt, $clienteNome, $boxNum]);

            // Carregar itens do pedido para consolidação
            $stmtItensConf->execute([$numPedInt]);
            $itensDoPedido = $stmtItensConf->fetchAll(PDO::FETCH_ASSOC);

            foreach ($itensDoPedido as $item) {
                $sku = trim($item['codigo_produto']);
                $qtd = (float)$item['quantidade'];
                $totalUnidades += $qtd;

                if (!isset($skusConsolidados[$sku])) {
                    // Buscar melhor posição física no endereçamento (picking preferencial)
                    $stmtLoc = $pdo->prepare("
                        SELECT pe.local_id 
                        FROM produtos_enderecos pe
                        JOIN locais_armazenagem la ON la.id = pe.local_id
                        WHERE pe.codigo_produto = ? 
                        ORDER BY CASE WHEN pe.tipo = 'principal' THEN 0 ELSE 1 END, la.rua ASC
                        LIMIT 1
                    ");
                    $stmtLoc->execute([$sku]);
                    $localId = $stmtLoc->fetchColumn() ?: null;

                    $skusConsolidados[$sku] = [
                        'codigo_produto' => $sku,
                        'ean' => $item['ean'] ?? '',
                        'descricao' => $item['descricao'] ?? $sku,
                        'local_id' => $localId,
                        'quantidade_total' => 0.0,
                        'pedidos_destinatarios' => []
                    ];
                }

                $skusConsolidados[$sku]['quantidade_total'] += $qtd;
                $skusConsolidados[$sku]['pedidos_destinatarios'][] = [
                    'numero_pedido' => $numPedInt,
                    'box_numero' => $boxNum,
                    'quantidade' => $qtd
                ];
            }

            $boxNum++;
        }

        // Inserir itens consolidados
        $stmtInsConsol = $pdo->prepare("
            INSERT INTO onda_itens_consolidados (onda_id, codigo_produto, ean, descricao, local_id, quantidade_total, quantidade_coletada, status)
            VALUES (?, ?, ?, ?, ?, ?, 0, 'pendente')
        ");

        foreach ($skusConsolidados as $c) {
            $stmtInsConsol->execute([
                $ondaId,
                $c['codigo_produto'],
                $c['ean'],
                $c['descricao'],
                $c['local_id'],
                $c['quantidade_total']
            ]);
        }

        // Atualizar totais na onda
        $stmtUpOnda = $pdo->prepare("
            UPDATE ondas_separacao 
            SET total_itens = ?, total_unidades = ?
            WHERE id = ?
        ");
        $stmtUpOnda->execute([count($skusConsolidados), $totalUnidades, $ondaId]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => "Onda {$codigoOnda} criada com sucesso para " . count($pedidosIds) . " pedidos.",
            'onda_id' => $ondaId,
            'codigo_onda' => $codigoOnda
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Erro ao criar onda: ' . $e->getMessage()]);
    }
}

/**
 * Inicia a separação da onda
 */
function iniciarOnda(PDO $pdo, ?array $user): void {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($input['id'] ?? 0);
    $operador = $user['nome'] ?? 'Operador';

    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'ID inválido.']);
        return;
    }

    $stmt = $pdo->prepare("UPDATE ondas_separacao SET status = 'em_separacao', operador = ? WHERE id = ? AND status = 'pendente'");
    $stmt->execute([$operador, $id]);

    // Retorna detalhes completos da onda para o front-end
    $_GET['id'] = $id;
    obterOnda($pdo);
}

/**
 * Bipagem inteligente de produto na onda com indicação de Box (Put-to-Box)
 */
function biparOnda(PDO $pdo, ?array $user): void {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $ondaId = (int)($input['onda_id'] ?? 0);
    $codigoBipado = trim($input['codigo_bipado'] ?? '');
    $quantidade = max(1.0, (float)($input['quantidade'] ?? 1.0));
    $tipoLeitura = $input['tipo_leitura'] ?? 'camera';
    $operador = $user['nome'] ?? 'Operador';

    if ($ondaId <= 0 || $codigoBipado === '') {
        echo json_encode(['success' => false, 'error' => 'Dados de leitura incompletos.']);
        return;
    }

    // 1. Identificar SKU correspondente
    $skuIdentificado = null;

    // A) Verificar diretamente se o código bipado é o código do SKU
    $stmtCheckSku = $pdo->prepare("
        SELECT codigo_produto, ean, descricao, quantidade_total, quantidade_coletada 
        FROM onda_itens_consolidados 
        WHERE onda_id = ? AND (codigo_produto = ? OR ean = ?)
        LIMIT 1
    ");
    $stmtCheckSku->execute([$ondaId, $codigoBipado, $codigoBipado]);
    $itemConsolidado = $stmtCheckSku->fetch(PDO::FETCH_ASSOC);

    if ($itemConsolidado) {
        $skuIdentificado = $itemConsolidado['codigo_produto'];
    } else {
        // B) Buscar na tabela de-para produtos_ean_custom
        $stmtEan = $pdo->prepare("SELECT codigo_produto FROM produtos_ean_custom WHERE ean_adicional = ? LIMIT 1");
        $stmtEan->execute([$codigoBipado]);
        $customSku = $stmtEan->fetchColumn();

        if ($customSku) {
            $stmtCheckSku->execute([$ondaId, $customSku, $customSku]);
            $itemConsolidado = $stmtCheckSku->fetch(PDO::FETCH_ASSOC);
            if ($itemConsolidado) {
                $skuIdentificado = $customSku;
            }
        }
    }

    if (!$skuIdentificado || !$itemConsolidado) {
        echo json_encode([
            'success' => false,
            'error' => "O produto '{$codigoBipado}' não pertence aos pedidos desta onda de separação."
        ]);
        return;
    }

    $qtdTotal = (float)$itemConsolidado['quantidade_total'];
    $qtdColetada = (float)$itemConsolidado['quantidade_coletada'];

    if ($qtdColetada + $quantidade > $qtdTotal) {
        echo json_encode([
            'success' => false,
            'error' => "Quantidade total necessária para o SKU {$skuIdentificado} já foi totalmente coletada ({$qtdColetada}/{$qtdTotal})."
        ]);
        return;
    }

    $pdo->beginTransaction();
    try {
        // 2. Determinar para qual pedido/box da onda destinar esta unidade
        // Buscar pedidos da onda que ainda precisam deste SKU
        $stmtDest = $pdo->prepare("
            SELECT op.numero_pedido, op.caixa_box_numero, op.cliente,
                   ci.quantidade AS qtd_esperada,
                   ci.quantidade_conferida AS qtd_ja_conferida
            FROM onda_pedidos op
            JOIN conferencias c ON c.numero_pedido = op.numero_pedido
            JOIN conferencia_itens ci ON ci.conferencia_id = c.id AND ci.codigo_produto = ?
            WHERE op.onda_id = ? AND ci.quantidade_conferida < ci.quantidade
            ORDER BY op.caixa_box_numero ASC
            LIMIT 1
        ");
        $stmtDest->execute([$skuIdentificado, $ondaId]);
        $destinatario = $stmtDest->fetch(PDO::FETCH_ASSOC);

        $boxDestino = $destinatario ? (int)$destinatario['caixa_box_numero'] : 1;
        $numPedidoDestino = $destinatario ? (int)$destinatario['numero_pedido'] : null;
        $clienteDestino = $destinatario ? $destinatario['cliente'] : 'Pedido';

        // 3. Atualizar quantidade conferida no pedido individual
        if ($numPedidoDestino) {
            $stmtUpItemPed = $pdo->prepare("
                UPDATE conferencia_itens 
                SET quantidade_conferida = quantidade_conferida + ?
                WHERE conferencia_id = (SELECT id FROM conferencias WHERE numero_pedido = ?)
                  AND codigo_produto = ?
            ");
            $stmtUpItemPed->execute([$quantidade, $numPedidoDestino, $skuIdentificado]);
        }

        // 4. Atualizar item consolidado na onda
        $novaQtdColetada = $qtdColetada + $quantidade;
        $novoStatusItem = ($novaQtdColetada >= $qtdTotal) ? 'coletado' : 'parcial';

        $stmtUpConsol = $pdo->prepare("
            UPDATE onda_itens_consolidados 
            SET quantidade_coletada = ?, status = ?
            WHERE onda_id = ? AND codigo_produto = ?
        ");
        $stmtUpConsol->execute([$novaQtdColetada, $novoStatusItem, $ondaId, $skuIdentificado]);

        // 5. Atualizar total de unidades coletadas na onda
        $stmtUpOnda = $pdo->prepare("
            UPDATE ondas_separacao 
            SET unidades_coletadas = unidades_coletadas + ?
            WHERE id = ?
        ");
        $stmtUpOnda->execute([$quantidade, $ondaId]);

        // 6. Registrar log de bipagem
        $stmtLog = $pdo->prepare("
            INSERT INTO logs_bipagem_onda (onda_id, numero_pedido, codigo_bipado, codigo_produto_identificado, caixa_box_numero, tipo_leitura, operador)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtLog->execute([$ondaId, $numPedidoDestino, $codigoBipado, $skuIdentificado, $boxDestino, $tipoLeitura, $operador]);

        $pdo->commit();

        // Obter estado atualizado para retorno
        $_GET['id'] = $ondaId;
        
        // Custom message with Put-to-Box instruction
        $msg = "Coloque {$quantidade} un no BOX #{$boxDestino} (Pedido #{$numPedidoDestino})";

        $stmtPed = $pdo->prepare("SELECT * FROM onda_pedidos WHERE onda_id = ? ORDER BY caixa_box_numero ASC");
        $stmtPed->execute([$ondaId]);
        $pedidos = $stmtPed->fetchAll(PDO::FETCH_ASSOC);

        $stmtItens = $pdo->prepare("
            SELECT oic.*, la.codigo AS local_codigo, la.rua, la.estante, la.nivel, la.posicao
            FROM onda_itens_consolidados oic
            LEFT JOIN locais_armazenagem la ON la.id = oic.local_id
            WHERE oic.onda_id = ?
            ORDER BY la.rua ASC, la.estante ASC, la.nivel ASC, oic.descricao ASC
        ");
        $stmtItens->execute([$ondaId]);
        $itens = $stmtItens->fetchAll(PDO::FETCH_ASSOC);

        $stmtOnda = $pdo->prepare("SELECT * FROM ondas_separacao WHERE id = ?");
        $stmtOnda->execute([$ondaId]);
        $ondaAtualizada = $stmtOnda->fetch(PDO::FETCH_ASSOC);

        $stmtLogs = $pdo->prepare("SELECT * FROM logs_bipagem_onda WHERE onda_id = ? ORDER BY id DESC LIMIT 25");
        $stmtLogs->execute([$ondaId]);
        $logs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'message' => $msg,
            'box_destino' => $boxDestino,
            'numero_pedido' => $numPedidoDestino,
            'cliente' => $clienteDestino,
            'sku_bipado' => $skuIdentificado,
            'onda' => $ondaAtualizada,
            'pedidos' => $pedidos,
            'itens' => $itens,
            'logs' => $logs
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Erro ao processar bipagem: ' . $e->getMessage()]);
    }
}

/**
 * Conclui a separação da onda
 */
function finalizarOnda(PDO $pdo, ?array $user): void {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($input['id'] ?? 0);

    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'ID inválido.']);
        return;
    }

    $stmt = $pdo->prepare("
        UPDATE ondas_separacao 
        SET status = 'separado', finalizado_em = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $stmt->execute([$id]);

    // Marcar pedidos da onda como separados e prontos para o packing
    $stmtPed = $pdo->prepare("UPDATE onda_pedidos SET status = 'separado' WHERE onda_id = ?");
    $stmtPed->execute([$id]);

    echo json_encode([
        'success' => true,
        'message' => 'Separação em lote da onda finalizada com sucesso! Itens prontos para o Packing Station.'
    ]);
}

/**
 * Cancela a onda de separação
 */
function cancelarOnda(PDO $pdo, ?array $user): void {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($input['id'] ?? 0);

    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'ID inválido.']);
        return;
    }

    $stmt = $pdo->prepare("UPDATE ondas_separacao SET status = 'cancelado' WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode([
        'success' => true,
        'message' => 'Onda cancelada com sucesso.'
    ]);
}
