<?php
/**
 * WMS Prime - API de Estação de Embalagem & Expedição (Packing Station & Checkout)
 * Validação de peso teórico vs balança, conferência final de caixas e despacho de volumes.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$user = obterUsuarioAutenticado($pdo);

switch ($action) {
    case 'carregar_pedido':
        exigirPermissao($pdo, $user, 'packing_visualizar');
        carregarPedidoPacking($pdo);
        break;

    case 'validar_item':
        exigirPermissao($pdo, $user, 'packing_embalar');
        validarItemPacking($pdo, $user);
        break;

    case 'concluir_embalagem':
        exigirPermissao($pdo, $user, 'packing_embalar');
        concluirEmbalagemPacking($pdo, $user);
        break;

    case 'listar_historico':
        exigirPermissao($pdo, $user, 'packing_visualizar');
        listarHistoricoPacking($pdo);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ação inválida para o Packing Station.']);
        break;
}

/**
 * Carrega os dados de um pedido para a bancada de embalagem (por número do pedido, rastreio ou box da onda)
 */
function carregarPedidoPacking(PDO $pdo): void {
    $busca = trim($_GET['termo'] ?? '');
    if ($busca === '') {
        echo json_encode(['success' => false, 'error' => 'Informe o número do pedido ou bipe o código de barras.']);
        return;
    }

    $numPedido = null;

    // 1. Tentar busca direta pelo número do pedido
    if (is_numeric($busca)) {
        $numPedido = (int)$busca;
    } else {
        // 2. Tentar buscar em ondas ativas pelo box
        if (preg_match('/^BOX[ -]?([0-9]+)$/i', $busca, $m)) {
            $boxNum = (int)$m[1];
            $stmtBox = $pdo->prepare("
                SELECT op.numero_pedido 
                FROM onda_pedidos op
                JOIN ondas_separacao o ON o.id = op.onda_id
                WHERE op.caixa_box_numero = ? AND o.status IN ('em_separacao', 'separado')
                ORDER BY o.id DESC LIMIT 1
            ");
            $stmtBox->execute([$boxNum]);
            $numPedido = $stmtBox->fetchColumn() ?: null;
        }
    }

    if (!$numPedido) {
        $numPedido = (int)$busca;
    }

    // Buscar conferência do pedido
    $stmtConf = $pdo->prepare("SELECT * FROM conferencias WHERE numero_pedido = ? LIMIT 1");
    $stmtConf->execute([$numPedido]);
    $conf = $stmtConf->fetch(PDO::FETCH_ASSOC);

    if (!$conf) {
        echo json_encode(['success' => false, 'error' => "Pedido #{$numPedido} não encontrado no sistema."]);
        return;
    }

    // Buscar itens do pedido
    $stmtItens = $pdo->prepare("
        SELECT ci.*,
               (SELECT la.codigo FROM produtos_enderecos pe 
                JOIN locais_armazenagem la ON la.id = pe.local_id 
                WHERE pe.codigo_produto = ci.codigo_produto LIMIT 1) AS local_codigo
        FROM conferencia_itens ci 
        WHERE ci.conferencia_id = ?
        ORDER BY ci.descricao ASC
    ");
    $stmtItens->execute([$conf['id']]);
    $itens = $stmtItens->fetchAll(PDO::FETCH_ASSOC);

    // Buscar volumes já gerados
    $stmtVol = $pdo->prepare("SELECT * FROM volumes WHERE conferencia_id = ? ORDER BY numero_volume ASC");
    $stmtVol->execute([$conf['id']]);
    $volumes = $stmtVol->fetchAll(PDO::FETCH_ASSOC);

    // Calcular peso teórico total dos produtos cadastrados
    $pesoTeoricoTotalKg = 0.0;
    foreach ($itens as $it) {
        // Obter peso do cadastro do produto se houver
        $stmtPeso = $pdo->prepare("SELECT peso_bruto FROM produtos_cache WHERE codigo_produto = ? LIMIT 1");
        $stmtPeso->execute([$it['codigo_produto']]);
        $pesoUnitKg = (float)($stmtPeso->fetchColumn() ?: 0.25); // Default 250g se não cadastrado
        $pesoTeoricoTotalKg += ($pesoUnitKg * (float)$it['quantidade']);
    }

    // Verificar se já passou por checkout
    $stmtCheck = $pdo->prepare("SELECT * FROM packing_checkouts WHERE numero_pedido = ? ORDER BY id DESC LIMIT 1");
    $stmtCheck->execute([$numPedido]);
    $ultimoCheckout = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'pedido' => $conf,
        'itens' => $itens,
        'volumes' => $volumes,
        'peso_teorico_kg' => round($pesoTeoricoTotalKg, 3),
        'ultimo_checkout' => $ultimoCheckout
    ]);
}

/**
 * Validação de item bipado na bancada de Packing
 */
function validarItemPacking(PDO $pdo, ?array $user): void {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $confId = (int)($input['conferencia_id'] ?? 0);
    $codigoBipado = trim($input['codigo_bipado'] ?? '');

    if ($confId <= 0 || $codigoBipado === '') {
        echo json_encode(['success' => false, 'error' => 'Código ou ID do pedido inválido.']);
        return;
    }

    // Localizar item na conferência
    $stmtItem = $pdo->prepare("
        SELECT * FROM conferencia_itens 
        WHERE conferencia_id = ? AND (codigo_produto = ? OR ean = ?)
        LIMIT 1
    ");
    $stmtItem->execute([$confId, $codigoBipado, $codigoBipado]);
    $item = $stmtItem->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        // Tentar via de-para EAN customizado
        $stmtEan = $pdo->prepare("SELECT codigo_produto FROM produtos_ean_custom WHERE ean_adicional = ? LIMIT 1");
        $stmtEan->execute([$codigoBipado]);
        $customSku = $stmtEan->fetchColumn();

        if ($customSku) {
            $stmtItem->execute([$confId, $customSku, $customSku]);
            $item = $stmtItem->fetch(PDO::FETCH_ASSOC);
        }
    }

    if (!$item) {
        echo json_encode([
            'success' => false,
            'error' => "O produto '{$codigoBipado}' não pertence a este pedido!"
        ]);
        return;
    }

    echo json_encode([
        'success' => true,
        'message' => "Item validado: {$item['descricao']}",
        'item' => $item
    ]);
}

/**
 * Conclui a embalagem, confere peso e gera despacho
 */
function concluirEmbalagemPacking(PDO $pdo, ?array $user): void {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $numPedido = (int)($input['numero_pedido'] ?? 0);
    $pesoBalancaKg = (float)($input['peso_balanca_kg'] ?? 0.0);
    $pesoTeoricoKg = (float)($input['peso_teorico_kg'] ?? 0.0);
    $volumesTotal = max(1, (int)($input['volumes_total'] ?? 1));
    $observacoes = trim($input['observacoes'] ?? '');
    $operador = $user['nome'] ?? 'Operador';

    if ($numPedido <= 0) {
        echo json_encode(['success' => false, 'error' => 'Número de pedido inválido.']);
        return;
    }

    $diferencaGramas = round(($pesoBalancaKg - $pesoTeoricoKg) * 1000, 1);
    // Tolerância padrão de 80g
    $statusPeso = (abs($diferencaGramas) <= 120) ? 'ok' : 'divergente';

    $pdo->beginTransaction();
    try {
        // Obter cliente
        $stmtCli = $pdo->prepare("SELECT cliente FROM conferencias WHERE numero_pedido = ? LIMIT 1");
        $stmtCli->execute([$numPedido]);
        $cliente = $stmtCli->fetchColumn() ?: 'Cliente';

        // Registrar Checkout
        $stmtCheck = $pdo->prepare("
            INSERT INTO packing_checkouts (numero_pedido, cliente, peso_teorico_kg, peso_balanca_kg, diferenca_peso_g, volumes_total, status_peso, observacoes, operador)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtCheck->execute([
            $numPedido,
            $cliente,
            $pesoTeoricoKg,
            $pesoBalancaKg,
            $diferencaGramas,
            $volumesTotal,
            $statusPeso,
            $observacoes,
            $operador
        ]);

        // Atualizar status da conferência para embalado / pronto_coleta
        $stmtUpConf = $pdo->prepare("
            UPDATE conferencias 
            SET status = 'embalado', data_finalizacao = CURRENT_TIMESTAMP
            WHERE numero_pedido = ?
        ");
        $stmtUpConf->execute([$numPedido]);

        // Atualizar também na onda de pedidos se fizer parte de uma
        $stmtUpOndaPed = $pdo->prepare("
            UPDATE onda_pedidos 
            SET status = 'embalado' 
            WHERE numero_pedido = ?
        ");
        $stmtUpOndaPed->execute([$numPedido]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => "Pedido #{$numPedido} embalado e finalizado com sucesso!",
            'status_peso' => $statusPeso,
            'diferenca_g' => $diferencaGramas,
            'numero_pedido' => $numPedido
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Erro ao finalizar packing: ' . $e->getMessage()]);
    }
}

/**
 * Lista histórico recente de checkouts realizados na bancada
 */
function listarHistoricoPacking(PDO $pdo): void {
    $stmt = $pdo->query("SELECT * FROM packing_checkouts ORDER BY id DESC LIMIT 50");
    $historico = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'historico' => $historico
    ]);
}
