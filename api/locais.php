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
            requirePermission('locais_visualizar');

            $armazem = trim($_GET['armazem'] ?? '');
            $rua = trim($_GET['rua'] ?? '');
            $tipo = trim($_GET['tipo'] ?? '');
            $busca = trim($_GET['busca'] ?? '');
            $pagina = max(1, (int)($_GET['pagina'] ?? 1));
            $limite = min(500, max(10, (int)($_GET['limite'] ?? 100)));
            $offset = ($pagina - 1) * $limite;

            $where = ["1=1"];
            $params = [];

            if (!empty($armazem)) {
                $where[] = "l.armazem = ?";
                $params[] = $armazem;
            }
            if (!empty($rua)) {
                $where[] = "l.rua = ?";
                $params[] = $rua;
            }
            if (!empty($tipo)) {
                $where[] = "l.tipo = ?";
                $params[] = $tipo;
            }
            if (!empty($busca)) {
                $where[] = "(l.codigo LIKE ? OR l.observacoes LIKE ? OR EXISTS (
                    SELECT 1 FROM produtos_enderecos pe 
                    LEFT JOIN produtos_cache pc ON pc.codigo_produto = pe.codigo_produto
                    WHERE pe.local_id = l.id AND (pe.codigo_produto LIKE ? OR pc.nome LIKE ? OR pc.ean LIKE ?)
                ))";
                $like = "%$busca%";
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }

            $whereSql = implode(" AND ", $where);

            // Total
            $stmtCount = $db->prepare("SELECT COUNT(*) FROM locais_armazenagem l WHERE $whereSql");
            $stmtCount->execute($params);
            $total = (int)$stmtCount->fetchColumn();

            // Lista
            $sql = "
                SELECT 
                    l.*,
                    COUNT(DISTINCT pe.id) AS total_skus,
                    COALESCE(SUM(pe.quantidade_atual), 0) AS total_unidades
                FROM locais_armazenagem l
                LEFT JOIN produtos_enderecos pe ON pe.local_id = l.id
                WHERE $whereSql
                GROUP BY l.id
                ORDER BY l.armazem ASC, l.rua ASC, l.estante ASC, l.nivel ASC, l.posicao ASC
                LIMIT $limite OFFSET $offset
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $locais = $stmt->fetchAll();

            // Obter opções distintas de Armazéns e Ruas para os filtros
            $armazens = $db->query("SELECT DISTINCT armazem FROM locais_armazenagem WHERE armazem != '' ORDER BY armazem ASC")->fetchAll(PDO::FETCH_COLUMN);
            $ruas = $db->query("SELECT DISTINCT rua FROM locais_armazenagem WHERE rua != '' ORDER BY rua ASC")->fetchAll(PDO::FETCH_COLUMN);

            jsonResponse([
                'success' => true,
                'total' => $total,
                'pagina' => $pagina,
                'limite' => $limite,
                'locais' => $locais,
                'filtro_armazens' => $armazens,
                'filtro_ruas' => $ruas
            ]);
            break;

        case 'obter':
            requirePermission('locais_visualizar');

            $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
            if ($id <= 0) {
                jsonError("ID do local inválido.");
            }

            $stmt = $db->prepare("SELECT * FROM locais_armazenagem WHERE id = ?");
            $stmt->execute([$id]);
            $local = $stmt->fetch();
            if (!$local) {
                jsonError("Local não encontrado.", 404);
            }

            // Buscar produtos alocados neste local
            $stmtProds = $db->prepare("
                SELECT 
                    pe.*,
                    COALESCE(pc.nome, pe.codigo_produto) AS nome_produto,
                    COALESCE(pc.ean, '') AS ean,
                    COALESCE(pc.unidade, 'UN') AS unidade
                FROM produtos_enderecos pe
                LEFT JOIN produtos_cache pc ON pc.codigo_produto = pe.codigo_produto
                WHERE pe.local_id = ?
                ORDER BY pe.tipo DESC, pe.id ASC
            ");
            $stmtProds->execute([$id]);
            $produtos = $stmtProds->fetchAll();

            jsonResponse([
                'success' => true,
                'local' => $local,
                'produtos' => $produtos
            ]);
            break;

        case 'salvar':
            requirePermission('locais_gerenciar');

            $id = (int)($_POST['id'] ?? 0);
            $armazem = trim($_POST['armazem'] ?? 'Principal');
            $rua = strtoupper(trim($_POST['rua'] ?? ''));
            $estante = str_pad(trim($_POST['estante'] ?? '1'), 2, '0', STR_PAD_LEFT);
            $nivel = str_pad(trim($_POST['nivel'] ?? '1'), 2, '0', STR_PAD_LEFT);
            $posicao = str_pad(trim($_POST['posicao'] ?? '1'), 2, '0', STR_PAD_LEFT);
            $tipo = trim($_POST['tipo'] ?? 'picking');
            $capacidade = (float)($_POST['capacidade_maxima'] ?? 0);
            $pesoMax = (float)($_POST['peso_max_kg'] ?? 0);
            $status = trim($_POST['status'] ?? 'ativo');
            $obs = trim($_POST['observacoes'] ?? '');

            if (empty($rua)) {
                jsonError("O campo Rua/Corredor é obrigatório.");
            }

            // Gerar código padronizado: ex: A-01-02-01
            $codigo = trim($_POST['codigo'] ?? '');
            if (empty($codigo)) {
                $codigo = "$rua-$estante-$nivel-$posicao";
            } else {
                $codigo = strtoupper($codigo);
            }

            if ($id > 0) {
                // Verificar código duplicado em outro registro
                $stmtCheck = $db->prepare("SELECT id FROM locais_armazenagem WHERE codigo = ? AND id != ?");
                $stmtCheck->execute([$codigo, $id]);
                if ($stmtCheck->fetch()) {
                    jsonError("Já existe uma posição com o código '$codigo'.");
                }

                $stmt = $db->prepare("
                    UPDATE locais_armazenagem 
                    SET codigo = ?, armazem = ?, rua = ?, estante = ?, nivel = ?, posicao = ?, 
                        tipo = ?, capacidade_maxima = ?, peso_max_kg = ?, status = ?, observacoes = ?, atualizado_em = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$codigo, $armazem, $rua, $estante, $nivel, $posicao, $tipo, $capacidade, $pesoMax, $status, $obs, $id]);
                jsonResponse(['success' => true, 'message' => "Posição '$codigo' atualizada com sucesso.", 'id' => $id]);
            } else {
                $stmtCheck = $db->prepare("SELECT id FROM locais_armazenagem WHERE codigo = ?");
                $stmtCheck->execute([$codigo]);
                if ($stmtCheck->fetch()) {
                    jsonError("Já existe uma posição com o código '$codigo'.");
                }

                $stmt = $db->prepare("
                    INSERT INTO locais_armazenagem (codigo, armazem, rua, estante, nivel, posicao, tipo, capacidade_maxima, peso_max_kg, status, observacoes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$codigo, $armazem, $rua, $estante, $nivel, $posicao, $tipo, $capacidade, $pesoMax, $status, $obs]);
                $newId = (int)$db->lastInsertId();
                jsonResponse(['success' => true, 'message' => "Posição '$codigo' cadastrada com sucesso.", 'id' => $newId]);
            }
            break;

        case 'gerar_lote':
            requirePermission('locais_gerenciar');

            $armazem = trim($_POST['armazem'] ?? 'Principal');
            $ruaInicio = strtoupper(trim($_POST['rua_inicio'] ?? 'A'));
            $ruaFim = strtoupper(trim($_POST['rua_fim'] ?? $ruaInicio));
            $estanteInicio = max(1, (int)($_POST['estante_inicio'] ?? 1));
            $estanteFim = max($estanteInicio, (int)($_POST['estante_fim'] ?? 1));
            $nivelInicio = max(1, (int)($_POST['nivel_inicio'] ?? 1));
            $nivelFim = max($nivelInicio, (int)($_POST['nivel_fim'] ?? 1));
            $posicaoInicio = max(1, (int)($_POST['posicao_inicio'] ?? 1));
            $posicaoFim = max($posicaoInicio, (int)($_POST['posicao_fim'] ?? 1));
            $tipo = trim($_POST['tipo'] ?? 'picking');
            $capacidade = (float)($_POST['capacidade_maxima'] ?? 0);
            $pesoMax = (float)($_POST['peso_max_kg'] ?? 0);

            // Converter letras de rua em range
            $chrInicio = ord($ruaInicio[0] ?? 'A');
            $chrFim = ord($ruaFim[0] ?? 'A');
            if ($chrFim < $chrInicio) {
                $temp = $chrInicio;
                $chrInicio = $chrFim;
                $chrFim = $temp;
            }

            $criados = 0;
            $ignorados = 0;

            $stmtCheck = $db->prepare("SELECT id FROM locais_armazenagem WHERE codigo = ?");
            $stmtIns = $db->prepare("
                INSERT INTO locais_armazenagem (codigo, armazem, rua, estante, nivel, posicao, tipo, capacidade_maxima, peso_max_kg, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'ativo')
            ");

            $db->beginTransaction();
            try {
                for ($r = $chrInicio; $r <= $chrFim; $r++) {
                    $rua = chr($r);
                    for ($e = $estanteInicio; $e <= $estanteFim; $e++) {
                        $estanteStr = str_pad($e, 2, '0', STR_PAD_LEFT);
                        for ($n = $nivelInicio; $n <= $nivelFim; $n++) {
                            $nivelStr = str_pad($n, 2, '0', STR_PAD_LEFT);
                            for ($p = $posicaoInicio; $p <= $posicaoFim; $p++) {
                                $posicaoStr = str_pad($p, 2, '0', STR_PAD_LEFT);
                                $codigo = "$rua-$estanteStr-$nivelStr-$posicaoStr";

                                $stmtCheck->execute([$codigo]);
                                if ($stmtCheck->fetch()) {
                                    $ignorados++;
                                    continue;
                                }

                                $stmtIns->execute([
                                    $codigo, $armazem, $rua, $estanteStr, $nivelStr, $posicaoStr,
                                    $tipo, $capacidade, $pesoMax
                                ]);
                                $criados++;
                            }
                        }
                    }
                }
                $db->commit();
                jsonResponse([
                    'success' => true,
                    'message' => "Geração em lote concluída: $criados novas posições criadas" . ($ignorados > 0 ? " ($ignorados já existiam)." : "."),
                    'criados' => $criados,
                    'ignorados' => $ignorados
                ]);
            } catch (Exception $e) {
                $db->rollBack();
                jsonError("Erro ao gerar posições em lote: " . $e->getMessage());
            }
            break;

        case 'excluir':
            requirePermission('locais_gerenciar');

            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                jsonError("ID do local inválido.");
            }

            // Verificar se tem produtos com estoque alocado
            $stmtVerif = $db->prepare("SELECT COUNT(*) FROM produtos_enderecos WHERE local_id = ? AND quantidade_atual > 0");
            $stmtVerif->execute([$id]);
            $qtdProds = (int)$stmtVerif->fetchColumn();

            if ($qtdProds > 0) {
                jsonError("Não é possível excluir esta posição pois existem $qtdProds produto(s) com saldo estocado nela.");
            }

            $stmtDel = $db->prepare("DELETE FROM locais_armazenagem WHERE id = ?");
            $stmtDel->execute([$id]);

            jsonResponse(['success' => true, 'message' => "Posição removida com sucesso."]);
            break;

        case 'atribuir_produto':
            requirePermission('locais_gerenciar');

            $localId = (int)($_POST['local_id'] ?? 0);
            $codigoProduto = trim($_POST['codigo_produto'] ?? '');
            $tipo = trim($_POST['tipo'] ?? 'principal');
            $quantidade = (float)($_POST['quantidade'] ?? 0);

            if ($localId <= 0 || empty($codigoProduto)) {
                jsonError("Local e código do produto (SKU) são obrigatórios.");
            }

            // Verificar se local existe
            $stmtLoc = $db->prepare("SELECT id, codigo FROM locais_armazenagem WHERE id = ?");
            $stmtLoc->execute([$localId]);
            $loc = $stmtLoc->fetch();
            if (!$loc) {
                jsonError("Localização não encontrada.");
            }

            // Tentar resolver ou cachear nome do produto caso venha no payload
            $nomeProd = trim($_POST['nome_produto'] ?? '');
            $eanProd = trim($_POST['ean'] ?? '');
            if (!empty($nomeProd) || !empty($eanProd)) {
                salvarCacheProduto($codigoProduto, $eanProd, $nomeProd, 'UN', $db);
            } else {
                // Tenta resolver do SIGE ou cache
                obterEanProduto($codigoProduto, $sige, $db);
            }

            $ok = atribuirEnderecoProduto($codigoProduto, $localId, $quantidade, $tipo, $db);
            if ($ok) {
                jsonResponse(['success' => true, 'message' => "Produto $codigoProduto atribuído à posição {$loc['codigo']} com sucesso."]);
            } else {
                jsonError("Erro ao vincular produto ao local.");
            }
            break;

        case 'remover_produto':
            requirePermission('locais_gerenciar');

            $vinculoId = (int)($_POST['id'] ?? 0);
            if ($vinculoId <= 0) {
                jsonError("ID do vínculo inválido.");
            }

            $stmt = $db->prepare("DELETE FROM produtos_enderecos WHERE id = ?");
            $stmt->execute([$vinculoId]);

            jsonResponse(['success' => true, 'message' => "Produto desvinculado da posição."]);
            break;

        case 'buscar_produtos':
            requirePermission('locais_visualizar');

            $termo = trim($_GET['termo'] ?? '');
            if (strlen($termo) < 2) {
                jsonResponse(['success' => true, 'produtos' => []]);
            }

            // Buscar primeiro no cache local
            $stmtCache = $db->prepare("
                SELECT codigo_produto, ean, nome, unidade 
                FROM produtos_cache 
                WHERE codigo_produto LIKE ? OR ean LIKE ? OR nome LIKE ?
                ORDER BY nome ASC 
                LIMIT 20
            ");
            $like = "%$termo%";
            $stmtCache->execute([$like, $like, $like]);
            $prods = $stmtCache->fetchAll();

            // Se achou menos de 5 produtos, busca no SIGE Cloud para enriquecer o autocomplete
            if (count($prods) < 5) {
                $prodsSige = $sige->pesquisarProdutos($termo);
                if (is_array($prodsSige)) {
                    foreach ($prodsSige as $ps) {
                        $cod = trim($ps['Codigo'] ?? '');
                        if (empty($cod)) continue;

                        $exists = false;
                        foreach ($prods as $p) {
                            if ($p['codigo_produto'] === $cod) {
                                $exists = true;
                                break;
                            }
                        }

                        if (!$exists) {
                            $ean = trim($ps['Ean'] ?? $ps['EAN'] ?? $ps['CodigoBarra'] ?? '');
                            $nome = trim($ps['Nome'] ?? $ps['Descricao'] ?? '');
                            $un = trim($ps['EstoqueUnidade'] ?? $ps['UnidadeComercial'] ?? 'UN');
                            salvarCacheProduto($cod, $ean, $nome, $un, $db);
                            $prods[] = [
                                'codigo_produto' => $cod,
                                'ean' => $ean,
                                'nome' => $nome,
                                'unidade' => $un
                            ];
                        }
                    }
                }
            }

            jsonResponse(['success' => true, 'produtos' => $prods]);
            break;

        case 'etiquetas':
            requirePermission('locais_visualizar');

            $ids = $_GET['ids'] ?? $_POST['ids'] ?? '';
            $idsArr = is_array($ids) ? $ids : array_filter(explode(',', $ids), 'is_numeric');

            if (empty($idsArr)) {
                $armazem = trim($_GET['armazem'] ?? '');
                $rua = trim($_GET['rua'] ?? '');
                $where = ["status = 'ativo'"];
                $params = [];
                if (!empty($armazem)) { $where[] = "armazem = ?"; $params[] = $armazem; }
                if (!empty($rua)) { $where[] = "rua = ?"; $params[] = $rua; }
                $whereSql = implode(" AND ", $where);
                $stmt = $db->prepare("SELECT * FROM locais_armazenagem WHERE $whereSql ORDER BY armazem, rua, estante, nivel, posicao LIMIT 500");
                $stmt->execute($params);
                $locais = $stmt->fetchAll();
            } else {
                $placeholders = implode(',', array_fill(0, count($idsArr), '?'));
                $stmt = $db->prepare("SELECT * FROM locais_armazenagem WHERE id IN ($placeholders) ORDER BY armazem, rua, estante, nivel, posicao");
                $stmt->execute($idsArr);
                $locais = $stmt->fetchAll();
            }

            jsonResponse(['success' => true, 'locais' => $locais]);
            break;

        default:
            jsonError("Ação inválida.");
    }
} catch (Exception $e) {
    jsonError("Erro no módulo de locais: " . $e->getMessage(), 500);
}
