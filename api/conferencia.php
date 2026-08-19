<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sige_client.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = getDB();
$sige = new SigeClient();

// Ler payload JSON se enviado via body
$inputJSON = json_decode(file_get_contents('php://input'), true);
if (is_array($inputJSON)) {
    $_POST = array_merge($_POST, $inputJSON);
}

try {
    switch ($action) {
        case 'iniciar':
            $codigo = (int)($_POST['numero_pedido'] ?? 0);
            $operador = trim($_POST['operador'] ?? getConfig('operador_padrao', 'Operador'));

            if (!$codigo) {
                jsonError("Número do pedido é obrigatório.");
            }

            // Buscar pedido no SIGE
            $pedido = $sige->obterPedidoPorCodigo($codigo);
            if (!$pedido) {
                jsonError("Pedido #$codigo não encontrado no SIGE Cloud.", 404);
            }

            // Verificar se já existe conferência ativa
            $stmt = $db->prepare("SELECT * FROM conferencias WHERE numero_pedido = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$codigo]);
            $confExistente = $stmt->fetch();

            if ($confExistente && ($confExistente['status'] === 'em_separacao' || $confExistente['status'] === 'pendente')) {
                // Atualizar operador se necessário e retornar a existente
                $stmtUp = $db->prepare("UPDATE conferencias SET operador = ?, data_inicio = COALESCE(data_inicio, CURRENT_TIMESTAMP), status = 'em_separacao', atualizado_em = CURRENT_TIMESTAMP WHERE id = ?");
                $stmtUp->execute([$operador, $confExistente['id']]);
                $conferenciaId = $confExistente['id'];
            } else {
                // Criar nova conferência
                $items = $pedido['Items'] ?? [];
                $totalItensLinhas = count($items);
                $qtdTotalEsperada = 0;
                foreach ($items as $it) {
                    $qtdTotalEsperada += (float)($it['Quantidade'] ?? 0);
                }

                $stmtIns = $db->prepare("
                    INSERT INTO conferencias (
                        pedido_sige_id, numero_pedido, cliente, operador, status, 
                        total_itens, itens_conferidos, quantidade_total_esperada, 
                        quantidade_total_conferida, data_inicio, criado_em, atualizado_em
                    ) VALUES (?, ?, ?, ?, 'em_separacao', ?, 0, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ");
                $stmtIns->execute([
                    $pedido['ID'] ?? '',
                    $codigo,
                    $pedido['Cliente'] ?? 'Consumidor',
                    $operador,
                    $totalItensLinhas,
                    $qtdTotalEsperada
                ]);
                $conferenciaId = (int)$db->lastInsertId();

                // Inserir itens
                $stmtItemIns = $db->prepare("
                    INSERT INTO conferencia_itens (
                        conferencia_id, codigo_produto, ean, descricao, unidade, 
                        quantidade_pedida, quantidade_conferida, status, categoria
                    ) VALUES (?, ?, ?, ?, ?, ?, 0, 'pendente', ?)
                ");

                foreach ($items as $it) {
                    $codProd = trim($it['Codigo'] ?? '');
                    $eanProd = trim($it['EAN'] ?? $it['Ean'] ?? $it['CodigoBarra'] ?? '');
                    
                    // Se o item do pedido não veio com EAN, verificar se existe na tabela de de-para local
                    if (empty($eanProd)) {
                        $stmtCustom = $db->prepare("SELECT ean_adicional FROM produtos_ean_custom WHERE codigo_produto = ? LIMIT 1");
                        $stmtCustom->execute([$codProd]);
                        $custom = $stmtCustom->fetch();
                        if ($custom) {
                            $eanProd = $custom['ean_adicional'];
                        }
                    }

                    $stmtItemIns->execute([
                        $conferenciaId,
                        $codProd,
                        $eanProd,
                        trim($it['Descricao'] ?? $codProd),
                        trim($it['Unidade'] ?? 'UN'),
                        (float)($it['Quantidade'] ?? 1),
                        trim($it['Categoria'] ?? '')
                    ]);
                }
            }

            // Retornar dados completos da conferência
            retornarDadosConferencia($conferenciaId, "Conferência iniciada para o pedido #$codigo");
            break;

        case 'bipar':
            $conferenciaId = (int)($_POST['conferencia_id'] ?? 0);
            $codigoBipado = trim((string)($_POST['codigo_bipado'] ?? ''));
            $quantidade = (float)($_POST['quantidade'] ?? 1);
            $tipoLeitura = trim($_POST['tipo_leitura'] ?? 'camera'); // camera, pistola, manual
            $operador = trim($_POST['operador'] ?? 'Operador');

            if (!$conferenciaId || empty($codigoBipado)) {
                jsonError("Conferência e código bipado são obrigatórios.");
            }

            if ($quantidade <= 0) {
                $quantidade = 1;
            }

            // Buscar conferência
            $stmt = $db->prepare("SELECT * FROM conferencias WHERE id = ?");
            $stmt->execute([$conferenciaId]);
            $conf = $stmt->fetch();
            if (!$conf) {
                jsonError("Conferência #$conferenciaId não encontrada.", 404);
            }

            // Buscar todos os itens desta conferência
            $stmtItens = $db->prepare("SELECT * FROM conferencia_itens WHERE conferencia_id = ?");
            $stmtItens->execute([$conferenciaId]);
            $itens = $stmtItens->fetchAll();

            // Buscar se o código bipado está cadastrado no de-para customizado
            $stmtEanCustom = $db->prepare("SELECT codigo_produto FROM produtos_ean_custom WHERE ean_adicional = ?");
            $stmtEanCustom->execute([$codigoBipado]);
            $customMapped = $stmtEanCustom->fetch();
            $codigoProdutoMapeado = $customMapped ? $customMapped['codigo_produto'] : null;

            // Encontrar o item correspondente
            $itemEncontrado = null;
            $codigoLimpo = ltrim($codigoBipado, '0');

            foreach ($itens as $it) {
                $sku = trim($it['codigo_produto']);
                $ean = trim($it['ean']);
                
                // Correspondência exata por EAN ou Código/SKU
                if (strcasecmp($sku, $codigoBipado) === 0 || 
                    (!empty($ean) && strcasecmp($ean, $codigoBipado) === 0)) {
                    $itemEncontrado = $it;
                    break;
                }

                // Correspondência via mapeamento customizado
                if ($codigoProdutoMapeado && strcasecmp($sku, $codigoProdutoMapeado) === 0) {
                    $itemEncontrado = $it;
                    break;
                }

                // Correspondência sem zeros à esquerda
                if (!empty($ean) && ltrim($ean, '0') === $codigoLimpo) {
                    $itemEncontrado = $it;
                    break;
                }

                // Se o código de barras contiver o SKU como substring/termo
                if (!empty($sku) && strlen($sku) >= 4 && stripos($codigoBipado, $sku) !== false) {
                    $itemEncontrado = $it;
                    break;
                }
            }

            // SE NÃO ENCONTROU O ITEM NO PEDIDO
            if (!$itemEncontrado) {
                // Registrar log de erro
                $stmtLog = $db->prepare("
                    INSERT INTO logs_bipagem (conferencia_id, codigo_bipado, codigo_produto_identificado, tipo_leitura, resultado, operador)
                    VALUES (?, ?, NULL, ?, 'produto_nao_pertence', ?)
                ");
                $stmtLog->execute([$conferenciaId, $codigoBipado, $tipoLeitura, $operador]);

                jsonResponse([
                    'success' => false,
                    'motivo' => 'PRODUTO_NAO_PERTENCE',
                    'message' => "Código '$codigoBipado' não pertence ao pedido #{$conf['numero_pedido']}!",
                    'codigo_bipado' => $codigoBipado
                ], 200);
            }

            // SE ENCONTROU O ITEM, VERIFICAR QUANTIDADE
            $qtdAtual = (float)$itemEncontrado['quantidade_conferida'];
            $qtdEsperada = (float)$itemEncontrado['quantidade_pedida'];
            $novaQtd = $qtdAtual + $quantidade;

            if ($novaQtd > $qtdEsperada) {
                // Registrar log de excesso
                $stmtLog = $db->prepare("
                    INSERT INTO logs_bipagem (conferencia_id, codigo_bipado, codigo_produto_identificado, tipo_leitura, resultado, operador)
                    VALUES (?, ?, ?, ?, 'quantidade_excedida', ?)
                ");
                $stmtLog->execute([$conferenciaId, $codigoBipado, $itemEncontrado['codigo_produto'], $tipoLeitura, $operador]);

                jsonResponse([
                    'success' => false,
                    'motivo' => 'QUANTIDADE_EXCEDIDA',
                    'message' => "Quantidade excedida para '{$itemEncontrado['descricao']}'. Esperado: $qtdEsperada, Atual: $qtdAtual, Tentativa: +$quantidade",
                    'item' => $itemEncontrado,
                    'codigo_bipado' => $codigoBipado
                ], 200);
            }

            // ATUALIZAR ITEM
            $novoStatusItem = ($novaQtd >= $qtdEsperada) ? 'conferido' : 'parcial';
            $stmtUpItem = $db->prepare("
                UPDATE conferencia_itens 
                SET quantidade_conferida = ?, status = ?
                WHERE id = ?
            ");
            $stmtUpItem->execute([$novaQtd, $novoStatusItem, $itemEncontrado['id']]);

            // Se o item não tinha EAN gravado e foi bipado um EAN válido, associar
            if (empty($itemEncontrado['ean']) && strlen($codigoBipado) >= 8 && is_numeric($codigoBipado)) {
                $stmtUpEan = $db->prepare("UPDATE conferencia_itens SET ean = ? WHERE id = ?");
                $stmtUpEan->execute([$codigoBipado, $itemEncontrado['id']]);
                
                // Salvar também no catálogo de EAN customizado para futuros pedidos
                $stmtInsEan = $db->prepare("INSERT OR IGNORE INTO produtos_ean_custom (codigo_produto, ean_adicional, descricao) VALUES (?, ?, ?)");
                $stmtInsEan->execute([$itemEncontrado['codigo_produto'], $codigoBipado, $itemEncontrado['descricao']]);
            }

            // Registrar log de sucesso
            $stmtLog = $db->prepare("
                INSERT INTO logs_bipagem (conferencia_id, codigo_bipado, codigo_produto_identificado, tipo_leitura, resultado, operador)
                VALUES (?, ?, ?, ?, 'sucesso', ?)
            ");
            $stmtLog->execute([$conferenciaId, $codigoBipado, $itemEncontrado['codigo_produto'], $tipoLeitura, $operador]);

            // Recalcular totais da conferência
            recalcularConferencia($conferenciaId);

            retornarDadosConferencia($conferenciaId, "Item '{$itemEncontrado['descricao']}' conferido com sucesso (+$quantidade)", [
                'item_bipado' => $itemEncontrado['codigo_produto'],
                'item_concluido' => ($novaQtd >= $qtdEsperada)
            ]);
            break;

        case 'ajustar_item':
            $itemId = (int)($_POST['item_id'] ?? 0);
            $novaQuantidade = max(0, (float)($_POST['quantidade'] ?? 0));
            $operador = trim($_POST['operador'] ?? 'Operador');

            if (!$itemId) jsonError("ID do item não informado.");

            $stmtIt = $db->prepare("SELECT * FROM conferencia_itens WHERE id = ?");
            $stmtIt->execute([$itemId]);
            $it = $stmtIt->fetch();
            if (!$it) jsonError("Item não encontrado.");

            $conferenciaId = $it['conferencia_id'];
            $qtdEsperada = (float)$it['quantidade_pedida'];

            $novoStatus = 'pendente';
            if ($novaQuantidade >= $qtdEsperada) {
                $novoStatus = 'conferido';
            } elseif ($novaQuantidade > 0) {
                $novoStatus = 'parcial';
            }

            $stmtUp = $db->prepare("UPDATE conferencia_itens SET quantidade_conferida = ?, status = ? WHERE id = ?");
            $stmtUp->execute([$novaQuantidade, $novoStatus, $itemId]);

            // Registrar log
            $stmtLog = $db->prepare("
                INSERT INTO logs_bipagem (conferencia_id, codigo_bipado, codigo_produto_identificado, tipo_leitura, resultado, operador)
                VALUES (?, 'AJUSTE_MANUAL', ?, 'manual', 'sucesso', ?)
            ");
            $stmtLog->execute([$conferenciaId, $it['codigo_produto'], $operador]);

            recalcularConferencia($conferenciaId);
            retornarDadosConferencia($conferenciaId, "Quantidade ajustada para $novaQuantidade");
            break;

        case 'adicionar_volume':
            $conferenciaId = (int)($_POST['conferencia_id'] ?? 0);
            $pesoKg = (float)($_POST['peso_kg'] ?? 0);
            $dimensoes = trim($_POST['dimensoes'] ?? '');
            
            if (!$conferenciaId) jsonError("ID da conferência não informado.");

            $stmtCount = $db->prepare("SELECT COUNT(*) as c FROM volumes WHERE conferencia_id = ?");
            $stmtCount->execute([$conferenciaId]);
            $numVol = (int)($stmtCount->fetch()['c'] ?? 0) + 1;

            $etiqueta = "VOL-" . str_pad($conferenciaId, 5, '0', STR_PAD_LEFT) . "-" . str_pad($numVol, 2, '0', STR_PAD_LEFT);

            $stmtIns = $db->prepare("
                INSERT INTO volumes (conferencia_id, numero_volume, total_volumes, peso_kg, dimensoes, etiqueta_codigo)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmtIns->execute([$conferenciaId, $numVol, $numVol, $pesoKg, $dimensoes, $etiqueta]);

            // Atualizar total de volumes para todos os volumes deste pedido
            $stmtUpVols = $db->prepare("UPDATE volumes SET total_volumes = ? WHERE conferencia_id = ?");
            $stmtUpVols->execute([$numVol, $conferenciaId]);

            retornarDadosConferencia($conferenciaId, "Volume #$numVol registrado com sucesso!");
            break;

        case 'remover_volume':
            $volumeId = (int)($_POST['volume_id'] ?? 0);
            if (!$volumeId) jsonError("ID do volume não informado.");

            $stmtVol = $db->prepare("SELECT conferencia_id FROM volumes WHERE id = ?");
            $stmtVol->execute([$volumeId]);
            $v = $stmtVol->fetch();
            if (!$v) jsonError("Volume não encontrado.");

            $conferenciaId = $v['conferencia_id'];
            $db->prepare("DELETE FROM volumes WHERE id = ?")->execute([$volumeId]);

            // Reorganizar números de volumes
            $stmtAll = $db->prepare("SELECT id FROM volumes WHERE conferencia_id = ? ORDER BY id ASC");
            $stmtAll->execute([$conferenciaId]);
            $all = $stmtAll->fetchAll();
            $tot = count($all);
            $n = 1;
            foreach ($all as $itemV) {
                $et = "VOL-" . str_pad($conferenciaId, 5, '0', STR_PAD_LEFT) . "-" . str_pad($n, 2, '0', STR_PAD_LEFT);
                $db->prepare("UPDATE volumes SET numero_volume = ?, total_volumes = ?, etiqueta_codigo = ? WHERE id = ?")
                   ->execute([$n, $tot, $et, $itemV['id']]);
                $n++;
            }

            retornarDadosConferencia($conferenciaId, "Volume removido.");
            break;

        case 'finalizar':
            $conferenciaId = (int)($_POST['conferencia_id'] ?? 0);
            $observacoes = trim($_POST['observacoes'] ?? '');
            $operador = trim($_POST['operador'] ?? 'Operador');

            if (!$conferenciaId) jsonError("ID da conferência não informado.");

            // Verificar se há divergência
            $stmtItens = $db->prepare("SELECT * FROM conferencia_itens WHERE conferencia_id = ?");
            $stmtItens->execute([$conferenciaId]);
            $itens = $stmtItens->fetchAll();

            $temDivergencia = false;
            foreach ($itens as $it) {
                if ((float)$it['quantidade_conferida'] !== (float)$it['quantidade_pedida']) {
                    $temDivergencia = true;
                    break;
                }
            }

            $novoStatus = $temDivergencia ? 'divergencia' : 'conferido';

            $stmtUp = $db->prepare("
                UPDATE conferencias 
                SET status = ?, observacoes = ?, data_fim = CURRENT_TIMESTAMP, atualizado_em = CURRENT_TIMESTAMP, operador = ?
                WHERE id = ?
            ");
            $stmtUp->execute([$novoStatus, $observacoes, $operador, $conferenciaId]);

            retornarDadosConferencia($conferenciaId, $temDivergencia ? "Conferência finalizada com DIVERGÊNCIA." : "Conferência finalizada com SUCESSO!");
            break;

        case 'cancelar':
            $conferenciaId = (int)($_POST['conferencia_id'] ?? 0);
            $numeroPedido = (int)($_POST['numero_pedido'] ?? 0);
            $motivo = trim($_POST['motivo'] ?? 'Cancelado pelo operador');
            $observacoes = trim($_POST['observacoes'] ?? '');
            $operador = trim($_POST['operador'] ?? 'Operador');

            if (!$conferenciaId && $numeroPedido) {
                $stmtFind = $db->prepare("SELECT id FROM conferencias WHERE numero_pedido = ? ORDER BY id DESC LIMIT 1");
                $stmtFind->execute([$numeroPedido]);
                $rowF = $stmtFind->fetch();
                if ($rowF) {
                    $conferenciaId = (int)$rowF['id'];
                }
            }

            if (!$conferenciaId) {
                jsonError("ID da conferência ou número do pedido não informado.");
            }

            $stmtConf = $db->prepare("SELECT * FROM conferencias WHERE id = ?");
            $stmtConf->execute([$conferenciaId]);
            $conf = $stmtConf->fetch();
            if (!$conf) {
                jsonError("Conferência #$conferenciaId não encontrada.", 404);
            }

            $obsFinal = !empty($observacoes) ? "[$motivo] $observacoes" : $motivo;

            $stmtUp = $db->prepare("
                UPDATE conferencias 
                SET status = 'cancelado', observacoes = ?, data_fim = CURRENT_TIMESTAMP, atualizado_em = CURRENT_TIMESTAMP, operador = COALESCE(?, operador)
                WHERE id = ?
            ");
            $stmtUp->execute([$obsFinal, $operador, $conferenciaId]);

            // Registrar log de auditoria
            $stmtLog = $db->prepare("
                INSERT INTO logs_bipagem (conferencia_id, codigo_bipado, codigo_produto_identificado, tipo_leitura, resultado, operador)
                VALUES (?, 'CANCELAMENTO', NULL, 'manual', 'cancelado', ?)
            ");
            $stmtLog->execute([$conferenciaId, $operador]);

            retornarDadosConferencia($conferenciaId, "Separação do pedido #{$conf['numero_pedido']} cancelada com sucesso.");
            break;

        case 'reiniciar':
            $conferenciaId = (int)($_POST['conferencia_id'] ?? 0);
            if (!$conferenciaId) jsonError("ID da conferência não informado.");

            $db->prepare("UPDATE conferencia_itens SET quantidade_conferida = 0, status = 'pendente' WHERE conferencia_id = ?")->execute([$conferenciaId]);
            $db->prepare("UPDATE conferencias SET status = 'em_separacao', itens_conferidos = 0, quantidade_total_conferida = 0, data_fim = NULL, atualizado_em = CURRENT_TIMESTAMP WHERE id = ?")->execute([$conferenciaId]);
            $db->prepare("DELETE FROM logs_bipagem WHERE conferencia_id = ?")->execute([$conferenciaId]);
            $db->prepare("DELETE FROM volumes WHERE conferencia_id = ?")->execute([$conferenciaId]);

            retornarDadosConferencia($conferenciaId, "Conferência reiniciada. Todos os itens zerados.");
            break;

        case 'historico':
            $busca = $_GET['busca'] ?? '';
            $status = $_GET['status'] ?? '';
            $limite = (int)($_GET['limite'] ?? 50);

            $sql = "SELECT c.*, 
                           (SELECT COUNT(*) FROM volumes v WHERE v.conferencia_id = c.id) as total_volumes_registrados
                    FROM conferencias c WHERE 1=1";
            $params = [];

            if (!empty($busca)) {
                $sql .= " AND (c.numero_pedido LIKE ? OR c.cliente LIKE ? OR c.operador LIKE ?)";
                $params[] = "%$busca%";
                $params[] = "%$busca%";
                $params[] = "%$busca%";
            }

            if (!empty($status)) {
                $sql .= " AND c.status = ?";
                $params[] = $status;
            }

            $sql .= " ORDER BY c.id DESC LIMIT $limite";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $historico = $stmt->fetchAll();

            jsonResponse([
                'success' => true,
                'total' => count($historico),
                'data' => $historico
            ]);
            break;

        case 'romaneio':
        case 'obter':
            $confId = (int)($_GET['conferencia_id'] ?? $_POST['conferencia_id'] ?? 0);
            $numPed = (int)($_GET['numero_pedido'] ?? $_POST['numero_pedido'] ?? 0);

            if ($confId > 0) {
                retornarDadosConferencia($confId, "Dados do romaneio");
                break;
            }

            if ($numPed > 0) {
                $stmt = $db->prepare("SELECT id FROM conferencias WHERE numero_pedido = ? ORDER BY id DESC LIMIT 1");
                $stmt->execute([$numPed]);
                $c = $stmt->fetch();
                if ($c) {
                    retornarDadosConferencia((int)$c['id'], "Dados do romaneio");
                    break;
                }

                // Se ainda não foi iniciada localmente, buscar do SIGE e retornar dados formatados
                $pedido = $sige->obterPedidoPorCodigo($numPed);
                if (!$pedido) {
                    jsonError("Pedido #$numPed não encontrado.", 404);
                }

                $items = $pedido['Items'] ?? [];
                $itensFormatados = [];
                $qtdTotal = 0;
                foreach ($items as $it) {
                    $qtd = (float)($it['Quantidade'] ?? 0);
                    $qtdTotal += $qtd;
                    $itensFormatados[] = [
                        'id' => 0,
                        'codigo_produto' => trim($it['Codigo'] ?? ''),
                        'ean' => trim($it['EAN'] ?? $it['Ean'] ?? $it['CodigoBarra'] ?? ''),
                        'descricao' => trim($it['Descricao'] ?? ''),
                        'quantidade_pedida' => $qtd,
                        'quantidade_conferida' => 0,
                        'status' => 'pendente'
                    ];
                }

                $confMock = [
                    'id' => 0,
                    'numero_pedido' => $numPed,
                    'cliente' => $pedido['Cliente'] ?? 'Consumidor',
                    'operador' => 'Pendente',
                    'status' => 'pendente',
                    'quantidade_total_esperada' => $qtdTotal,
                    'quantidade_total_conferida' => 0,
                    'porcentagem' => 0,
                    'criado_em' => $pedido['Data'] ?? date('Y-m-d H:i:s'),
                    'data_inicio' => null,
                    'data_fim' => null
                ];

                jsonResponse([
                    'success' => true,
                    'message' => 'Dados do pedido',
                    'conferencia' => $confMock,
                    'itens' => $itensFormatados,
                    'volumes' => [],
                    'logs' => []
                ]);
                break;
            }

            jsonError("Informe o ID da conferência ou número do pedido.");
            break;

        default:
            jsonError("Ação inválida.");
    }
} catch (Exception $e) {
    jsonError("Erro na conferência: " . $e->getMessage(), 500);
}

function recalcularConferencia(int $conferenciaId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM conferencia_itens WHERE conferencia_id = ?");
    $stmt->execute([$conferenciaId]);
    $itens = $stmt->fetchAll();

    $itensConferidosContagem = 0;
    $qtdTotalConferida = 0;
    $qtdTotalEsperada = 0;
    $todosCompletos = true;

    foreach ($itens as $it) {
        $qtdPed = (float)$it['quantidade_pedida'];
        $qtdConf = (float)$it['quantidade_conferida'];

        $qtdTotalEsperada += $qtdPed;
        $qtdTotalConferida += $qtdConf;

        if ($qtdConf >= $qtdPed) {
            $itensConferidosContagem++;
        } else {
            $todosCompletos = false;
        }
    }

    $novoStatus = $todosCompletos ? 'conferido' : 'em_separacao';

    $stmtUp = $db->prepare("
        UPDATE conferencias 
        SET itens_conferidos = ?, 
            quantidade_total_conferida = ?, 
            quantidade_total_esperada = ?, 
            status = CASE WHEN status = 'divergencia' THEN status ELSE ? END,
            data_fim = CASE WHEN ? = 'conferido' THEN CURRENT_TIMESTAMP ELSE data_fim END,
            atualizado_em = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $stmtUp->execute([
        $itensConferidosContagem,
        $qtdTotalConferida,
        $qtdTotalEsperada,
        $novoStatus,
        $novoStatus,
        $conferenciaId
    ]);
}

function retornarDadosConferencia(int $conferenciaId, string $mensagem = '', array $extra = []) {
    $db = getDB();

    $stmtConf = $db->prepare("SELECT * FROM conferencias WHERE id = ?");
    $stmtConf->execute([$conferenciaId]);
    $conf = $stmtConf->fetch();

    $stmtItens = $db->prepare("SELECT * FROM conferencia_itens WHERE conferencia_id = ? ORDER BY id ASC");
    $stmtItens->execute([$conferenciaId]);
    $itens = $stmtItens->fetchAll();

    $stmtVols = $db->prepare("SELECT * FROM volumes WHERE conferencia_id = ? ORDER BY numero_volume ASC");
    $stmtVols->execute([$conferenciaId]);
    $volumes = $stmtVols->fetchAll();

    $stmtLogs = $db->prepare("SELECT * FROM logs_bipagem WHERE conferencia_id = ? ORDER BY id DESC LIMIT 30");
    $stmtLogs->execute([$conferenciaId]);
    $logs = $stmtLogs->fetchAll();

    $conf['porcentagem'] = ($conf['quantidade_total_esperada'] > 0)
        ? min(100, round(($conf['quantidade_total_conferida'] / $conf['quantidade_total_esperada']) * 100, 1))
        : 0;

    jsonResponse(array_merge([
        'success' => true,
        'message' => $mensagem,
        'conferencia' => $conf,
        'itens' => $itens,
        'volumes' => $volumes,
        'logs' => $logs
    ], $extra));
}
