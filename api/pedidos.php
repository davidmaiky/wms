<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sige_client.php';

$action = $_GET['action'] ?? 'list';
$db = getDB();
$sige = new SigeClient();

try {
    switch ($action) {
        case 'list':
            requirePermission('pedidos_visualizar');

            $codigo = $_GET['codigo'] ?? '';
            $cliente = $_GET['cliente'] ?? '';
            $status = $_GET['status'] ?? '';
            $dataInicial = $_GET['dataInicial'] ?? '';
            $dataFinal = $_GET['dataFinal'] ?? '';
            $pageSize = isset($_GET['pageSize']) ? (int)$_GET['pageSize'] : 100;
            $skip = isset($_GET['skip']) ? (int)$_GET['skip'] : 0;

            $filters = [
                'pageSize' => $pageSize,
                'skip' => $skip
            ];

            if (!empty($codigo)) {
                $filters['codigo'] = $codigo;
            } else {
                // Se não informou código específico e nem data inicial, busca os pedidos dos últimos 30 dias
                if (empty($dataInicial)) {
                    $dataInicial = date('Y-m-d', strtotime('-30 days'));
                }
            }

            if (!empty($cliente)) $filters['cliente'] = $cliente;
            if (!empty($status)) $filters['status'] = $status;
            if (!empty($dataInicial)) $filters['dataInicial'] = $dataInicial;
            if (!empty($dataFinal)) $filters['dataFinal'] = $dataFinal;

            $res = $sige->pesquisarPedidos($filters);

            if (!$res['success']) {
                jsonError("Falha ao comunicar com SIGE Cloud: " . ($res['error'] ?? 'Erro desconhecido'), $res['status'] ?: 500);
            }

            $pedidos = is_array($res['data']) ? $res['data'] : [];

            // Ordenar pedidos por ID/Código decrescente (mais recentes primeiro)
            usort($pedidos, function($a, $b) {
                $idA = (int)($a['Codigo'] ?? $a['ID'] ?? $a['Id'] ?? 0);
                $idB = (int)($b['Codigo'] ?? $b['ID'] ?? $b['Id'] ?? 0);
                if ($idA === $idB) {
                    $dateA = strtotime($a['Data'] ?? '') ?: 0;
                    $dateB = strtotime($b['Data'] ?? '') ?: 0;
                    return $dateB <=> $dateA;
                }
                return $idB <=> $idA;
            });

            // Obter status de conferência local para cada pedido retornado
            if (!empty($pedidos)) {
                $numeros = array_column($pedidos, 'Codigo');
                $placeholders = implode(',', array_fill(0, count($numeros), '?'));
                $stmt = $db->prepare("
                    SELECT c.id as conferencia_id, c.numero_pedido, c.status as conferencia_status, 
                           c.operador, c.total_itens, c.itens_conferidos, c.quantidade_total_esperada, 
                           c.quantidade_total_conferida, c.data_inicio, c.data_fim
                    FROM conferencias c
                    WHERE c.numero_pedido IN ($placeholders)
                    ORDER BY c.id DESC
                ");
                $stmt->execute($numeros);
                $conferenciasLocal = [];
                while ($row = $stmt->fetch()) {
                    if (!isset($conferenciasLocal[$row['numero_pedido']])) {
                        $conferenciasLocal[$row['numero_pedido']] = $row;
                    }
                }

                // Mesclar informações
                foreach ($pedidos as &$p) {
                    $num = $p['Codigo'];
                    $p['Conferencia'] = $conferenciasLocal[$num] ?? [
                        'conferencia_id' => null,
                        'conferencia_status' => 'nao_iniciado',
                        'operador' => null,
                        'total_itens' => count($p['Items'] ?? []),
                        'itens_conferidos' => 0,
                        'quantidade_total_esperada' => array_sum(array_column($p['Items'] ?? [], 'Quantidade')),
                        'quantidade_total_conferida' => 0
                    ];
                }
            }

            jsonResponse([
                'success' => true,
                'total' => count($pedidos),
                'data' => $pedidos
            ]);
            break;

        case 'get':
            requirePermission('pedidos_visualizar');

            $codigo = (int)($_GET['codigo'] ?? 0);
            if (!$codigo) {
                jsonError("Código do pedido não informado.");
            }

            $pedido = $sige->obterPedidoPorCodigo($codigo);
            if (!$pedido) {
                jsonError("Pedido #$codigo não encontrado no SIGE Cloud.", 404);
            }

            // Enriquecer itens do pedido com EAN
            if (!empty($pedido['Items']) && is_array($pedido['Items'])) {
                foreach ($pedido['Items'] as &$it) {
                    $cod = trim($it['Codigo'] ?? '');
                    $ean = trim($it['Ean'] ?? $it['EAN'] ?? $it['CodigoBarra'] ?? '');
                    if (empty($ean) && !empty($cod)) {
                        $ean = obterEanProduto($cod, $sige, $db);
                    }
                    $it['Ean'] = $ean;
                    $it['EAN'] = $ean;
                }
            }

            // Buscar conferência local existente
            $stmt = $db->prepare("
                SELECT * FROM conferencias WHERE numero_pedido = ? ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$codigo]);
            $conferencia = $stmt->fetch();

            $itensConferencia = [];
            if ($conferencia) {
                $stmtItens = $db->prepare("
                    SELECT * FROM conferencia_itens WHERE conferencia_id = ?
                ");
                $stmtItens->execute([$conferencia['id']]);
                $itensConferencia = $stmtItens->fetchAll();

                // Garantir que os itens da conferência também tenham o EAN atualizado
                foreach ($itensConferencia as &$cit) {
                    if (empty($cit['ean']) && !empty($cit['codigo_produto'])) {
                        $citEan = obterEanProduto($cit['codigo_produto'], $sige, $db);
                        if (!empty($citEan)) {
                            $cit['ean'] = $citEan;
                            $stmtUpEan = $db->prepare("UPDATE conferencia_itens SET ean = ? WHERE id = ?");
                            $stmtUpEan->execute([$citEan, $cit['id']]);
                        }
                    }
                }

                // Buscar volumes gerados
                $stmtVols = $db->prepare("
                    SELECT * FROM volumes WHERE conferencia_id = ? ORDER BY numero_volume ASC
                ");
                $stmtVols->execute([$conferencia['id']]);
                $conferencia['volumes'] = $stmtVols->fetchAll();

                // Buscar logs
                $stmtLogs = $db->prepare("
                    SELECT * FROM logs_bipagem WHERE conferencia_id = ? ORDER BY id DESC LIMIT 50
                ");
                $stmtLogs->execute([$conferencia['id']]);
                $conferencia['logs'] = $stmtLogs->fetchAll();
            }

            jsonResponse([
                'success' => true,
                'pedido' => $pedido,
                'conferencia' => $conferencia,
                'itens_conferencia' => $itensConferencia
            ]);
            break;

        case 'stats':
            requirePermission('pedidos_visualizar');

            // Estatísticas gerais para o Dashboard
            $stats = [
                'total_conferencias' => 0,
                'conferidos_hoje' => 0,
                'em_separacao' => 0,
                'divergencias' => 0,
                'ultimas_conferencias' => []
            ];

            $hoje = date('Y-m-d');

            $rowTot = $db->query("SELECT COUNT(*) as c FROM conferencias")->fetch();
            $stats['total_conferencias'] = (int)$rowTot['c'];

            $rowConf = $db->query("SELECT COUNT(*) as c FROM conferencias WHERE status = 'conferido' AND DATE(data_fim) = '$hoje'")->fetch();
            $stats['conferidos_hoje'] = (int)$rowConf['c'];

            $rowSep = $db->query("SELECT COUNT(*) as c FROM conferencias WHERE status = 'em_separacao'")->fetch();
            $stats['em_separacao'] = (int)$rowSep['c'];

            $rowDiv = $db->query("SELECT COUNT(*) as c FROM conferencias WHERE status = 'divergencia'")->fetch();
            $stats['divergencias'] = (int)$rowDiv['c'];

            $stmtRec = $db->query("
                SELECT c.*, 
                       (SELECT COUNT(*) FROM conferencia_itens ci WHERE ci.conferencia_id = c.id) as total_linhas
                FROM conferencias c 
                ORDER BY c.id DESC LIMIT 6
            ");
            $stats['ultimas_conferencias'] = $stmtRec->fetchAll();

            jsonResponse([
                'success' => true,
                'stats' => $stats
            ]);
            break;

        default:
            jsonError("Ação desconhecida: $action");
    }
} catch (Exception $e) {
    jsonError("Erro interno no servidor: " . $e->getMessage(), 500);
}
