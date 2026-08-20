<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sige_client.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'listar';
$db = getDB();
$sige = new SigeClient();

$inputJSON = json_decode(file_get_contents('php://input'), true);
if (is_array($inputJSON)) {
    $_POST = array_merge($_POST, $inputJSON);
}

try {
    switch ($action) {
        case 'listar':
            requirePermission('recebimento_visualizar');

            $status = trim($_GET['status'] ?? '');
            $busca = trim($_GET['busca'] ?? '');
            $pagina = max(1, (int)($_GET['pagina'] ?? 1));
            $limite = min(100, max(5, (int)($_GET['limite'] ?? 25)));
            $offset = ($pagina - 1) * $limite;

            $where = ["1=1"];
            $params = [];

            if (!empty($status) && $status !== 'todos') {
                $where[] = "r.status = ?";
                $params[] = $status;
            }
            if (!empty($busca)) {
                $where[] = "(r.numero_documento LIKE ? OR r.fornecedor_nome LIKE ? OR r.fornecedor_cnpj LIKE ? OR r.chave_nfe LIKE ?)";
                $like = "%$busca%";
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }

            $whereSql = implode(" AND ", $where);

            // Contadores por status
            $stats = [
                'total' => (int)$db->query("SELECT COUNT(*) FROM recebimentos")->fetchColumn(),
                'pendente' => (int)$db->query("SELECT COUNT(*) FROM recebimentos WHERE status = 'pendente'")->fetchColumn(),
                'em_conferencia' => (int)$db->query("SELECT COUNT(*) FROM recebimentos WHERE status = 'em_conferencia'")->fetchColumn(),
                'conferido' => (int)$db->query("SELECT COUNT(*) FROM recebimentos WHERE status = 'conferido'")->fetchColumn(),
                'divergencia' => (int)$db->query("SELECT COUNT(*) FROM recebimentos WHERE status = 'divergencia'")->fetchColumn(),
                'armazenado' => (int)$db->query("SELECT COUNT(*) FROM recebimentos WHERE status = 'armazenado'")->fetchColumn()
            ];

            $stmtCount = $db->prepare("SELECT COUNT(*) FROM recebimentos r WHERE $whereSql");
            $stmtCount->execute($params);
            $totalFiltrado = (int)$stmtCount->fetchColumn();

            $sql = "
                SELECT r.* 
                FROM recebimentos r 
                WHERE $whereSql 
                ORDER BY r.id DESC 
                LIMIT $limite OFFSET $offset
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $recebimentos = $stmt->fetchAll();

            foreach ($recebimentos as &$rec) {
                $rec['porcentagem'] = ($rec['quantidade_total_esperada'] > 0)
                    ? min(100, round(($rec['quantidade_total_conferida'] / $rec['quantidade_total_esperada']) * 100, 1))
                    : 0;
            }

            jsonResponse([
                'success' => true,
                'total' => $totalFiltrado,
                'pagina' => $pagina,
                'limite' => $limite,
                'stats' => $stats,
                'recebimentos' => $recebimentos
            ]);
            break;

        case 'obter':
            requirePermission('recebimento_visualizar');

            $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
            if ($id <= 0) {
                jsonError("ID do recebimento inválido.");
            }

            retornarDadosRecebimento($id);
            break;

        case 'upload_xml':
            requirePermission('recebimento_criar');

            $xmlContent = '';
            if (isset($_FILES['xml_file']) && $_FILES['xml_file']['error'] === UPLOAD_ERR_OK) {
                $xmlContent = file_get_contents($_FILES['xml_file']['tmp_name']);
            } elseif (!empty($_POST['xml_string'])) {
                $xmlContent = $_POST['xml_string'];
            }

            if (empty($xmlContent)) {
                jsonError("Arquivo XML não enviado ou vazio.");
            }

            // Parse do XML da NF-e
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($xmlContent);
            if (!$xml) {
                jsonError("Estrutura XML inválida ou corrompida.");
            }

            // Normalizar namespaces
            $xml->registerXPathNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');

            // Localizar nó infNFe
            $infNFeList = $xml->xpath('//nfe:infNFe | //infNFe');
            if (empty($infNFeList)) {
                jsonError("Estrutura de NF-e não reconhecida (tag infNFe não encontrada).");
            }
            $infNFe = $infNFeList[0];

            $chaveNfe = preg_replace('/\D/', '', (string)($infNFe['Id'] ?? ''));
            $chaveNfe = str_replace('NFe', '', $chaveNfe);

            $nNF = (string)($infNFe->ide->nNF ?? '');
            $serie = (string)($infNFe->ide->serie ?? '1');
            $dhEmi = (string)($infNFe->ide->dhEmi ?? $infNFe->ide->dEmi ?? date('Y-m-d H:i:s'));
            $vNF = (float)($infNFe->total->ICMSTot->vNF ?? 0);

            $fornNome = (string)($infNFe->emit->xNome ?? $infNFe->emit->xFant ?? 'Fornecedor Desconhecido');
            $fornCnpj = (string)($infNFe->emit->CNPJ ?? $infNFe->emit->CPF ?? '');
            $fornUf = (string)($infNFe->emit->enderEmit->UF ?? '');

            // Verificar se já foi importado pela chave
            if (!empty($chaveNfe)) {
                $stmtCheck = $db->prepare("SELECT id, status, numero_documento FROM recebimentos WHERE chave_nfe = ?");
                $stmtCheck->execute([$chaveNfe]);
                $recExistente = $stmtCheck->fetch();
                if ($recExistente) {
                    jsonError("Esta NF-e (Chave $chaveNfe) já foi importada no sistema (Recebimento #{$recExistente['id']}).");
                }
            }

            // Extrair Itens
            $itensXml = [];
            $totalQtdEsperada = 0;

            $detList = $infNFe->xpath('nfe:det | det');
            foreach ($detList as $det) {
                $prod = $det->prod;
                $cProd = trim((string)$prod->cProd);
                $cEAN = trim((string)$prod->cEAN);
                $cEANTrib = trim((string)$prod->cEANTrib);
                $xProd = trim((string)$prod->xProd);
                $uCom = trim((string)$prod->uCom);
                $qCom = (float)($prod->qCom ?? 0);
                $vUnCom = (float)($prod->vUnCom ?? 0);

                $eanEfetivo = (!empty($cEAN) && $cEAN !== 'SEM GTIN') ? $cEAN : ((!empty($cEANTrib) && $cEANTrib !== 'SEM GTIN') ? $cEANTrib : '');
                
                // Rastreabilidade (Lote / Validade) se houver
                $lote = '';
                $dVal = null;
                if (isset($det->prod->rastro)) {
                    $lote = trim((string)$det->prod->rastro->nLote);
                    $dValRaw = trim((string)$det->prod->rastro->dVal);
                    if (!empty($dValRaw)) {
                        $dVal = $dValRaw;
                    }
                }

                // Salvar / atualizar no cache de produtos para consultas rápidas
                salvarCacheProduto($cProd, $eanEfetivo, $xProd, $uCom, $db);

                // Buscar sugestão de endereço existente
                $endSugerido = obterEnderecoPrincipalProduto($cProd, $db);
                $localSugeridoId = $endSugerido ? (int)$endSugerido['local_id'] : null;

                $itensXml[] = [
                    'codigo_produto' => $cProd,
                    'ean' => $eanEfetivo,
                    'descricao' => $xProd,
                    'unidade' => $uCom ?: 'UN',
                    'quantidade_esperada' => $qCom,
                    'quantidade_conferida' => 0,
                    'valor_unitario' => $vUnCom,
                    'lote' => $lote,
                    'data_validade' => $dVal,
                    'local_sugerido_id' => $localSugeridoId,
                    'status' => 'pendente'
                ];

                $totalQtdEsperada += $qCom;
            }

            if (empty($itensXml)) {
                jsonError("Nenhum item válido encontrado no XML da NF-e.");
            }

            $db->beginTransaction();
            try {
                $stmtIns = $db->prepare("
                    INSERT INTO recebimentos (
                        numero_documento, serie_documento, chave_nfe, fornecedor_nome, fornecedor_cnpj, 
                        fornecedor_uf, valor_total, status, total_itens, itens_conferidos, 
                        quantidade_total_esperada, quantidade_total_conferida, data_emissao, operador, xml_raw
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pendente', ?, 0, ?, 0, ?, ?, ?)
                ");
                $operador = $_SESSION['wms_user']['nome'] ?? getConfig('operador_padrao', 'Operador');
                $stmtIns->execute([
                    $nNF, $serie, $chaveNfe, $fornNome, $fornCnpj,
                    $fornUf, $vNF, count($itensXml), $totalQtdEsperada, $dhEmi, $operador, $xmlContent
                ]);
                $recebimentoId = (int)$db->lastInsertId();

                $stmtItemIns = $db->prepare("
                    INSERT INTO recebimento_itens (
                        recebimento_id, codigo_produto, ean, descricao, unidade, 
                        quantidade_esperada, quantidade_conferida, valor_unitario, lote, data_validade, local_sugerido_id, status
                    ) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 'pendente')
                ");

                foreach ($itensXml as $it) {
                    $stmtItemIns->execute([
                        $recebimentoId,
                        $it['codigo_produto'],
                        $it['ean'],
                        $it['descricao'],
                        $it['unidade'],
                        $it['quantidade_esperada'],
                        $it['valor_unitario'],
                        $it['lote'],
                        $it['data_validade'],
                        $it['local_sugerido_id']
                    ]);
                }

                $db->commit();
                jsonResponse([
                    'success' => true,
                    'message' => "NF-e #$nNF importada com sucesso! " . count($itensXml) . " produtos carregados para conferência.",
                    'recebimento_id' => $recebimentoId
                ]);
            } catch (Exception $e) {
                $db->rollBack();
                jsonError("Erro ao gravar recebimento: " . $e->getMessage());
            }
            break;

        case 'criar_manual':
            requirePermission('recebimento_criar');

            $numDoc = trim($_POST['numero_documento'] ?? '');
            $fornNome = trim($_POST['fornecedor_nome'] ?? 'Fornecedor');
            $fornCnpj = trim($_POST['fornecedor_cnpj'] ?? '');
            $itens = $_POST['itens'] ?? [];

            if (empty($numDoc)) {
                jsonError("Número do documento / pedido de compra é obrigatório.");
            }
            if (empty($itens) || !is_array($itens)) {
                jsonError("Adicione ao menos um item para o recebimento.");
            }

            $totalQtd = 0;
            foreach ($itens as $it) {
                $totalQtd += (float)($it['quantidade'] ?? 0);
            }

            $db->beginTransaction();
            try {
                $operador = $_SESSION['wms_user']['nome'] ?? getConfig('operador_padrao', 'Operador');
                $stmtIns = $db->prepare("
                    INSERT INTO recebimentos (
                        numero_documento, fornecedor_nome, fornecedor_cnpj, status, 
                        total_itens, quantidade_total_esperada, operador, data_emissao
                    ) VALUES (?, ?, ?, 'pendente', ?, ?, ?, CURRENT_TIMESTAMP)
                ");
                $stmtIns->execute([$numDoc, $fornNome, $fornCnpj, count($itens), $totalQtd, $operador]);
                $recId = (int)$db->lastInsertId();

                $stmtItem = $db->prepare("
                    INSERT INTO recebimento_itens (
                        recebimento_id, codigo_produto, ean, descricao, unidade, 
                        quantidade_esperada, local_sugerido_id, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pendente')
                ");

                foreach ($itens as $it) {
                    $cod = trim($it['codigo_produto'] ?? '');
                    $ean = trim($it['ean'] ?? '');
                    $desc = trim($it['descricao'] ?? $cod);
                    $un = trim($it['unidade'] ?? 'UN');
                    $qtd = (float)($it['quantidade'] ?? 0);

                    if (empty($ean) && !empty($cod)) {
                        $ean = obterEanProduto($cod, $sige, $db);
                    }
                    $endSugerido = obterEnderecoPrincipalProduto($cod, $db);
                    $localSugeridoId = $endSugerido ? (int)$endSugerido['local_id'] : null;

                    $stmtItem->execute([$recId, $cod, $ean, $desc, $un, $qtd, $localSugeridoId]);
                }

                $db->commit();
                jsonResponse(['success' => true, 'message' => "Recebimento manual criado com sucesso.", 'recebimento_id' => $recId]);
            } catch (Exception $e) {
                $db->rollBack();
                jsonError("Erro ao criar recebimento manual: " . $e->getMessage());
            }
            break;

        case 'iniciar':
            requirePermission('recebimento_conferir');

            $recId = (int)($_POST['id'] ?? 0);
            $operador = trim($_POST['operador'] ?? ($_SESSION['wms_user']['nome'] ?? getConfig('operador_padrao', 'Operador')));

            if ($recId <= 0) jsonError("ID do recebimento inválido.");

            $stmt = $db->prepare("
                UPDATE recebimentos 
                SET status = 'em_conferencia', 
                    operador = ?, 
                    data_recebimento = COALESCE(data_recebimento, CURRENT_TIMESTAMP),
                    atualizado_em = CURRENT_TIMESTAMP
                WHERE id = ? AND status = 'pendente'
            ");
            $stmt->execute([$operador, $recId]);

            retornarDadosRecebimento($recId, "Conferência de entrada iniciada!");
            break;

        case 'bipar':
            requirePermission('recebimento_conferir');

            $recId = (int)($_POST['recebimento_id'] ?? 0);
            $codigoBipado = trim($_POST['codigo_bipado'] ?? '');
            $tipoLeitura = trim($_POST['tipo_leitura'] ?? 'camera');
            $operador = trim($_POST['operador'] ?? ($_SESSION['wms_user']['nome'] ?? getConfig('operador_padrao', 'Operador')));

            if ($recId <= 0 || empty($codigoBipado)) {
                jsonError("Informe o recebimento e o código de barras bipado.");
            }

            // Obter itens deste recebimento
            $stmtItens = $db->prepare("SELECT * FROM recebimento_itens WHERE recebimento_id = ?");
            $stmtItens->execute([$recId]);
            $itens = $stmtItens->fetchAll();

            if (empty($itens)) {
                jsonError("Recebimento sem itens cadastrados.");
            }

            // Resolver o código bipado
            $itemEncontrado = null;
            $cleanCode = $codigoBipado;

            // 1. Tentar bater direto com EAN ou Código do Produto do item
            foreach ($itens as $it) {
                if (strcasecmp($it['ean'], $cleanCode) === 0 || strcasecmp($it['codigo_produto'], $cleanCode) === 0) {
                    $itemEncontrado = $it;
                    break;
                }
            }

            // 2. Se não achou, tentar resolver EAN via de-para customizado ou cache
            if (!$itemEncontrado) {
                $prodResolvido = obterProdutoPorEan($cleanCode, $sige, $db);
                if ($prodResolvido) {
                    foreach ($itens as $it) {
                        if (strcasecmp($it['codigo_produto'], $prodResolvido['codigo_produto']) === 0) {
                            $itemEncontrado = $it;
                            break;
                        }
                    }
                }
            }

            // Se o produto não pertence ao recebimento
            if (!$itemEncontrado) {
                $stmtLog = $db->prepare("
                    INSERT INTO logs_bipagem_recebimento (recebimento_id, codigo_bipado, codigo_produto_identificado, tipo_leitura, resultado, operador)
                    VALUES (?, ?, '', ?, 'produto_nao_pertence', ?)
                ");
                $stmtLog->execute([$recId, $cleanCode, $tipoLeitura, $operador]);

                jsonError("O código bipado ($cleanCode) NÃO pertence a esta nota fiscal / recebimento!", 422);
            }

            // Verificar se já atingiu a quantidade
            $qtdEsperada = (float)$itemEncontrado['quantidade_esperada'];
            $qtdAtual = (float)$itemEncontrado['quantidade_conferida'];
            $novaQtd = $qtdAtual + 1;

            $resultadoBipagem = 'sucesso';
            $novoItemStatus = ($novaQtd >= $qtdEsperada) ? 'conferido' : 'parcial';

            if ($novaQtd > $qtdEsperada) {
                $resultadoBipagem = 'quantidade_excedida';
                $novoItemStatus = 'divergente';
            }

            // Atualizar item
            $stmtUpItem = $db->prepare("
                UPDATE recebimento_itens 
                SET quantidade_conferida = ?, status = ?
                WHERE id = ?
            ");
            $stmtUpItem->execute([$novaQtd, $novoItemStatus, $itemEncontrado['id']]);

            // Gravar log
            $stmtLog = $db->prepare("
                INSERT INTO logs_bipagem_recebimento (recebimento_id, codigo_bipado, codigo_produto_identificado, tipo_leitura, resultado, operador)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmtLog->execute([$recId, $cleanCode, $itemEncontrado['codigo_produto'], $tipoLeitura, $resultadoBipagem, $operador]);

            // Recalcular totais e status do recebimento
            recalcularRecebimento($recId);

            retornarDadosRecebimento($recId, ($resultadoBipagem === 'quantidade_excedida' ? "Atenção: Quantidade excedida para {$itemEncontrado['descricao']}!" : "Bipagem registrada (+1 {$itemEncontrado['unidade']})"));
            break;

        case 'finalizar':
            requirePermission('recebimento_conferir');

            $recId = (int)($_POST['id'] ?? 0);
            if ($recId <= 0) jsonError("ID do recebimento inválido.");

            recalcularRecebimento($recId, true);
            retornarDadosRecebimento($recId, "Conferência de entrada finalizada com sucesso! Prossiga para a guarda (Putaway).");
            break;

        case 'armazenar':
            requirePermission('recebimento_armazenar');

            $recId = (int)($_POST['recebimento_id'] ?? 0);
            $itensArmazenamento = $_POST['itens'] ?? []; // Array de [{ item_id, local_id }]

            if ($recId <= 0) jsonError("ID do recebimento inválido.");

            $db->beginTransaction();
            try {
                $stmtItem = $db->prepare("SELECT * FROM recebimento_itens WHERE recebimento_id = ?");
                $stmtItem->execute([$recId]);
                $itensDB = $stmtItem->fetchAll();

                $mapAlocacoes = [];
                foreach ($itensArmazenamento as $aloc) {
                    $mapAlocacoes[(int)$aloc['item_id']] = (int)$aloc['local_id'];
                }

                $stmtUpItemLoc = $db->prepare("UPDATE recebimento_itens SET local_armazenado_id = ? WHERE id = ?");

                foreach ($itensDB as $it) {
                    $localId = $mapAlocacoes[$it['id']] ?? $it['local_sugerido_id'];
                    if ($localId > 0) {
                        $stmtUpItemLoc->execute([$localId, $it['id']]);

                        // Incrementar o saldo no endereço de estoque
                        $qtdGuardada = (float)$it['quantidade_conferida'];
                        if ($qtdGuardada <= 0) {
                            $qtdGuardada = (float)$it['quantidade_esperada'];
                        }

                        $stmtSaldo = $db->prepare("
                            INSERT INTO produtos_enderecos (codigo_produto, local_id, tipo, quantidade_atual, atualizado_em)
                            VALUES (?, ?, 'principal', ?, CURRENT_TIMESTAMP)
                            ON CONFLICT(codigo_produto, local_id) DO UPDATE SET
                                quantidade_atual = produtos_enderecos.quantidade_atual + excluded.quantidade_atual,
                                atualizado_em = CURRENT_TIMESTAMP
                        ");
                        $stmtSaldo->execute([$it['codigo_produto'], $localId, $qtdGuardada]);
                    }
                }

                // Atualizar recebimento para status armazenado
                $stmtUpRec = $db->prepare("
                    UPDATE recebimentos 
                    SET status = 'armazenado', 
                        data_armazenamento = CURRENT_TIMESTAMP, 
                        atualizado_em = CURRENT_TIMESTAMP 
                    WHERE id = ?
                ");
                $stmtUpRec->execute([$recId]);

                $db->commit();
                retornarDadosRecebimento($recId, "Produtos guardados e saldos de estoque atualizados nas prateleiras!");
            } catch (Exception $e) {
                $db->rollBack();
                jsonError("Erro ao processar armazenagem (Putaway): " . $e->getMessage());
            }
            break;

        default:
            jsonError("Ação inválida.");
    }
} catch (Exception $e) {
    jsonError("Erro no recebimento: " . $e->getMessage(), 500);
}

function recalcularRecebimento(int $recebimentoId, bool $forcarFinalizar = false) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM recebimento_itens WHERE recebimento_id = ?");
    $stmt->execute([$recebimentoId]);
    $itens = $stmt->fetchAll();

    $itensConferidosContagem = 0;
    $qtdTotalConferida = 0;
    $qtdTotalEsperada = 0;
    $possuiDivergencia = false;
    $todosCompletos = true;

    foreach ($itens as $it) {
        $qtdEsp = (float)$it['quantidade_esperada'];
        $qtdConf = (float)$it['quantidade_conferida'];

        $qtdTotalEsperada += $qtdEsp;
        $qtdTotalConferida += $qtdConf;

        if ($qtdConf > $qtdEsp) {
            $possuiDivergencia = true;
        }

        if ($qtdConf >= $qtdEsp && $qtdEsp > 0) {
            $itensConferidosContagem++;
        } else {
            $todosCompletos = false;
        }
    }

    $novoStatus = 'em_conferencia';
    if ($forcarFinalizar || $todosCompletos) {
        $novoStatus = $possuiDivergencia ? 'divergencia' : 'conferido';
    }

    $stmtUp = $db->prepare("
        UPDATE recebimentos 
        SET total_itens = ?,
            itens_conferidos = ?,
            quantidade_total_esperada = ?,
            quantidade_total_conferida = ?,
            status = CASE WHEN status = 'armazenado' THEN 'armazenado' ELSE ? END,
            atualizado_em = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $stmtUp->execute([
        count($itens),
        $itensConferidosContagem,
        $qtdTotalEsperada,
        $qtdTotalConferida,
        $novoStatus,
        $recebimentoId
    ]);
}

function retornarDadosRecebimento(int $recebimentoId, string $mensagem = '') {
    $db = getDB();

    $stmtRec = $db->prepare("SELECT * FROM recebimentos WHERE id = ?");
    $stmtRec->execute([$recebimentoId]);
    $rec = $stmtRec->fetch();
    if (!$rec) {
        jsonError("Recebimento não encontrado.", 404);
    }

    $stmtItens = $db->prepare("
        SELECT 
            ri.*,
            ls.codigo AS local_sugerido_codigo,
            ls.armazem AS local_sugerido_armazem,
            ls.rua AS local_sugerido_rua,
            ls.estante AS local_sugerido_estante,
            ls.nivel AS local_sugerido_nivel,
            ls.posicao AS local_sugerido_posicao,
            la.codigo AS local_armazenado_codigo
        FROM recebimento_itens ri
        LEFT JOIN locais_armazenagem ls ON ls.id = ri.local_sugerido_id
        LEFT JOIN locais_armazenagem la ON la.id = ri.local_armazenado_id
        WHERE ri.recebimento_id = ?
        ORDER BY ri.id ASC
    ");
    $stmtItens->execute([$recebimentoId]);
    $itens = $stmtItens->fetchAll();

    // Se algum item não tiver local sugerido, buscar o primeiro local disponível ou cadastrado
    foreach ($itens as &$it) {
        if (empty($it['local_sugerido_codigo'])) {
            $end = obterEnderecoPrincipalProduto($it['codigo_produto'], $db);
            if ($end) {
                $it['local_sugerido_id'] = $end['local_id'];
                $it['local_sugerido_codigo'] = $end['local_codigo'];
                $it['local_sugerido_armazem'] = $end['armazem'];
                $it['local_sugerido_rua'] = $end['rua'];
            }
        }
    }

    $stmtLogs = $db->prepare("SELECT * FROM logs_bipagem_recebimento WHERE recebimento_id = ? ORDER BY id DESC LIMIT 30");
    $stmtLogs->execute([$recebimentoId]);
    $logs = $stmtLogs->fetchAll();

    $rec['porcentagem'] = ($rec['quantidade_total_esperada'] > 0)
        ? min(100, round(($rec['quantidade_total_conferida'] / $rec['quantidade_total_esperada']) * 100, 1))
        : 0;

    jsonResponse([
        'success' => true,
        'message' => $mensagem,
        'recebimento' => $rec,
        'itens' => $itens,
        'logs' => $logs
    ]);
}
