<?php
require_once __DIR__ . '/config.php';

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dbPath = DB_PATH;
        $needInit = !file_exists($dbPath);
        
        $pdo = new PDO("sqlite:" . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        // Otimização de concorrência e integridade do SQLite
        $pdo->exec("PRAGMA journal_mode = WAL;");
        $pdo->exec("PRAGMA synchronous = NORMAL;");
        $pdo->exec("PRAGMA busy_timeout = 5000;");
        $pdo->exec("PRAGMA foreign_keys = ON;");
        
        if ($needInit || true) {
            initSchema($pdo);
        }
    }
    return $pdo;
}

function initSchema(PDO $pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS configuracoes (
            chave TEXT PRIMARY KEY,
            valor TEXT,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS pedidos_cache (
            id_sige TEXT PRIMARY KEY,
            numero_pedido INTEGER,
            cliente TEXT,
            data_pedido TEXT,
            valor_total REAL,
            status_sige TEXT,
            raw_json TEXT,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS conferencias (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pedido_sige_id TEXT,
            numero_pedido INTEGER,
            cliente TEXT,
            operador TEXT,
            status TEXT DEFAULT 'pendente', -- pendente, em_separacao, conferido, divergencia, finalizado
            total_itens INTEGER DEFAULT 0,
            itens_conferidos INTEGER DEFAULT 0,
            quantidade_total_esperada REAL DEFAULT 0,
            quantidade_total_conferida REAL DEFAULT 0,
            data_inicio DATETIME,
            data_fim DATETIME,
            observacoes TEXT,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS conferencia_itens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            conferencia_id INTEGER,
            codigo_produto TEXT,
            ean TEXT,
            descricao TEXT,
            unidade TEXT DEFAULT 'UN',
            quantidade_pedida REAL,
            quantidade_conferida REAL DEFAULT 0,
            status TEXT DEFAULT 'pendente', -- pendente, parcial, conferido, divergente
            categoria TEXT,
            FOREIGN KEY (conferencia_id) REFERENCES conferencias(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS logs_bipagem (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            conferencia_id INTEGER,
            codigo_bipado TEXT,
            codigo_produto_identificado TEXT,
            tipo_leitura TEXT, -- camera, pistola, manual
            resultado TEXT, -- sucesso, produto_nao_pertence, quantidade_excedida, erro
            operador TEXT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (conferencia_id) REFERENCES conferencias(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS volumes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            conferencia_id INTEGER,
            numero_volume INTEGER,
            total_volumes INTEGER,
            peso_kg REAL DEFAULT 0,
            dimensoes TEXT,
            etiqueta_codigo TEXT,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (conferencia_id) REFERENCES conferencias(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS produtos_ean_custom (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            codigo_produto TEXT,
            ean_adicional TEXT UNIQUE,
            descricao TEXT,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS usuarios (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL,
            email TEXT UNIQUE NOT NULL,
            funcao TEXT DEFAULT 'operador', -- admin, supervisor, conferente, operador
            pin TEXT,
            status TEXT DEFAULT 'ativo', -- ativo, inativo
            avatar_cor TEXT DEFAULT '#3b82f6',
            permissoes TEXT DEFAULT NULL, -- JSON com permissões customizadas ou NULL para usar padrão da função
            ultimo_acesso DATETIME,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS produtos_cache (
            codigo_produto TEXT PRIMARY KEY,
            ean TEXT,
            nome TEXT,
            unidade TEXT DEFAULT 'UN',
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE INDEX IF NOT EXISTS idx_conferencias_num ON conferencias(numero_pedido);
        CREATE INDEX IF NOT EXISTS idx_conferencias_status ON conferencias(status);
        CREATE INDEX IF NOT EXISTS idx_itens_conferencia ON conferencia_itens(conferencia_id);
        CREATE INDEX IF NOT EXISTS idx_itens_codigo ON conferencia_itens(codigo_produto);
        CREATE INDEX IF NOT EXISTS idx_itens_ean ON conferencia_itens(ean);
        CREATE INDEX IF NOT EXISTS idx_ean_custom ON produtos_ean_custom(ean_adicional);
        CREATE INDEX IF NOT EXISTS idx_prod_cache_ean ON produtos_cache(ean);
        CREATE INDEX IF NOT EXISTS idx_usuarios_email ON usuarios(email);
        CREATE INDEX IF NOT EXISTS idx_usuarios_status ON usuarios(status);
        CREATE INDEX IF NOT EXISTS idx_usuarios_funcao ON usuarios(funcao);
    ");

    // Garantir migração da coluna permissoes se a tabela já existia antes
    $colunasUsuarios = $pdo->query("PRAGMA table_info(usuarios)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('permissoes', $colunasUsuarios)) {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN permissoes TEXT DEFAULT NULL");
    }

    // Inserir usuários padrão se tabela estiver vazia
    $countUsers = $pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
    if ($countUsers == 0) {
        $stmtUser = $pdo->prepare("INSERT INTO usuarios (nome, email, funcao, pin, status, avatar_cor) VALUES (?, ?, ?, ?, ?, ?)");
        $stmtUser->execute(['David', 'david@primepro.com.br', 'admin', password_hash('1234', PASSWORD_BCRYPT), 'ativo', '#3b82f6']);
        $stmtUser->execute(['Operador Padrão', 'operador@primepro.com.br', 'operador', password_hash('1111', PASSWORD_BCRYPT), 'ativo', '#10b981']);
        $stmtUser->execute(['Conferente WMS', 'conferente@primepro.com.br', 'conferente', password_hash('2222', PASSWORD_BCRYPT), 'ativo', '#8b5cf6']);
    }

    // Inserir configurações padrão se não existirem
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO configuracoes (chave, valor) VALUES (?, ?)");
    $stmt->execute(['sige_token', DEFAULT_SIGE_TOKEN]);
    $stmt->execute(['sige_user', DEFAULT_SIGE_USER]);
    $stmt->execute(['sige_app', DEFAULT_SIGE_APP]);
    $stmt->execute(['som_habilitado', '1']);
    $stmt->execute(['modo_cego', '0']); // 0 = normal, 1 = conferência cega
    $stmt->execute(['operador_padrao', 'Operador']);
}

/**
 * Catálogo completo de permissões disponíveis no sistema WMS
 */
function getCatalogoPermissoes(): array {
    return [
        'pedidos_visualizar' => [
            'id' => 'pedidos_visualizar',
            'nome' => 'Visualizar Pedidos & Dashboard',
            'descricao' => 'Consultar listagem de pedidos do ERP e indicadores (KPIs).',
            'categoria' => 'Pedidos',
            'icone' => 'fa-solid fa-clipboard-list'
        ],
        'pedidos_iniciar_separacao' => [
            'id' => 'pedidos_iniciar_separacao',
            'nome' => 'Iniciar Separação',
            'descricao' => 'Iniciar o processo de conferência e separação de pedidos.',
            'categoria' => 'Pedidos',
            'icone' => 'fa-solid fa-play'
        ],
        'pedidos_cancelar' => [
            'id' => 'pedidos_cancelar',
            'nome' => 'Cancelar Separação',
            'descricao' => 'Cancelar pedidos e separações em andamento com justificativa.',
            'categoria' => 'Pedidos',
            'icone' => 'fa-solid fa-ban'
        ],
        'conferencia_bipar' => [
            'id' => 'conferencia_bipar',
            'nome' => 'Bipagem & Conferência de Itens',
            'descricao' => 'Escanear códigos de barras (EAN/SKU) e conferir produtos.',
            'categoria' => 'Separação',
            'icone' => 'fa-solid fa-barcode'
        ],
        'conferencia_adicionar_volume' => [
            'id' => 'conferencia_adicionar_volume',
            'nome' => 'Gerenciar Volumes & Embalagens',
            'descricao' => 'Pesar caixas, registrar volumes e imprimir etiquetas térmicas.',
            'categoria' => 'Separação',
            'icone' => 'fa-solid fa-box'
        ],
        'conferencia_finalizar' => [
            'id' => 'conferencia_finalizar',
            'nome' => 'Finalizar Separação',
            'descricao' => 'Concluir a conferência do pedido e registrar término no WMS.',
            'categoria' => 'Separação',
            'icone' => 'fa-solid fa-circle-check'
        ],
        'historico_visualizar' => [
            'id' => 'historico_visualizar',
            'nome' => 'Visualizar Histórico',
            'descricao' => 'Consultar histórico de conferências concluídas e logs de bipagem.',
            'categoria' => 'Histórico',
            'icone' => 'fa-solid fa-clock-rotate-left'
        ],
        'historico_imprimir_romaneio' => [
            'id' => 'historico_imprimir_romaneio',
            'nome' => 'Imprimir Romaneios',
            'descricao' => 'Visualizar e imprimir romaneios detalhados de separação.',
            'categoria' => 'Histórico',
            'icone' => 'fa-solid fa-print'
        ],
        'eans_visualizar' => [
            'id' => 'eans_visualizar',
            'nome' => 'Visualizar De-Para EAN',
            'descricao' => 'Consultar catálogo de vínculos de códigos de barras (EAN x SKU).',
            'categoria' => 'De-Para EAN',
            'icone' => 'fa-solid fa-tags'
        ],
        'eans_gerenciar' => [
            'id' => 'eans_gerenciar',
            'nome' => 'Gerenciar De-Para EAN',
            'descricao' => 'Cadastrar novos códigos de barras e excluir vínculos existentes.',
            'categoria' => 'De-Para EAN',
            'icone' => 'fa-solid fa-tag'
        ],
        'usuarios_visualizar' => [
            'id' => 'usuarios_visualizar',
            'nome' => 'Visualizar Usuários',
            'descricao' => 'Visualizar lista de operadores e usuários cadastrados no sistema.',
            'categoria' => 'Usuários',
            'icone' => 'fa-solid fa-users'
        ],
        'usuarios_gerenciar' => [
            'id' => 'usuarios_gerenciar',
            'nome' => 'Gerenciar Usuários',
            'descricao' => 'Cadastrar novos usuários, editar cadastros, inativar e excluir.',
            'categoria' => 'Usuários',
            'icone' => 'fa-solid fa-user-gear'
        ],
        'permissoes_gerenciar' => [
            'id' => 'permissoes_gerenciar',
            'nome' => 'Gerenciar Permissões',
            'descricao' => 'Personalizar e alterar permissões de acesso de qualquer usuário.',
            'categoria' => 'Usuários',
            'icone' => 'fa-solid fa-shield-halved'
        ],
        'config_visualizar' => [
            'id' => 'config_visualizar',
            'nome' => 'Visualizar Ajustes',
            'descricao' => 'Acessar a tela de configurações do sistema e integração.',
            'categoria' => 'Ajustes',
            'icone' => 'fa-solid fa-gear'
        ],
        'config_alterar' => [
            'id' => 'config_alterar',
            'nome' => 'Alterar Configurações',
            'descricao' => 'Modificar tokens SIGE Cloud, parâmetros operacionais e integrações.',
            'categoria' => 'Ajustes',
            'icone' => 'fa-solid fa-sliders'
        ]
    ];
}

/**
 * Retorna a lista de permissões padrão para cada função/cargo
 */
function getRoleDefaultPermissions(string $funcao): array {
    $catalogo = array_keys(getCatalogoPermissoes());

    switch (strtolower(trim($funcao))) {
        case 'admin':
            return $catalogo; // Todas as permissões

        case 'supervisor':
            return [
                'pedidos_visualizar',
                'pedidos_iniciar_separacao',
                'pedidos_cancelar',
                'conferencia_bipar',
                'conferencia_adicionar_volume',
                'conferencia_finalizar',
                'historico_visualizar',
                'historico_imprimir_romaneio',
                'eans_visualizar',
                'eans_gerenciar',
                'usuarios_visualizar',
                'config_visualizar'
            ];

        case 'conferente':
            return [
                'pedidos_visualizar',
                'pedidos_iniciar_separacao',
                'conferencia_bipar',
                'conferencia_adicionar_volume',
                'conferencia_finalizar',
                'historico_visualizar',
                'historico_imprimir_romaneio',
                'eans_visualizar'
            ];

        case 'operador':
        default:
            return [
                'pedidos_visualizar',
                'pedidos_iniciar_separacao',
                'conferencia_bipar',
                'conferencia_adicionar_volume',
                'conferencia_finalizar',
                'historico_visualizar'
            ];
    }
}

/**
 * Obtém a lista de permissões efetivas de um usuário (customizadas se definidas, senão o padrão da função)
 */
function getUserEffectivePermissions(array $usuario): array {
    // Se o usuário tiver campo 'permissoes' definido e não vazio em JSON
    if (!empty($usuario['permissoes'])) {
        $custom = is_array($usuario['permissoes']) ? $usuario['permissoes'] : json_decode($usuario['permissoes'], true);
        if (is_array($custom)) {
            // Se for admin, sempre tem tudo garantido
            if (($usuario['funcao'] ?? '') === 'admin') {
                return array_keys(getCatalogoPermissoes());
            }
            return array_values(array_unique($custom));
        }
    }

    // Caso contrário, retorna o padrão da função
    return getRoleDefaultPermissions($usuario['funcao'] ?? 'operador');
}

function getConfig($chave, $default = '') {
    $db = getDB();
    $stmt = $db->prepare("SELECT valor FROM configuracoes WHERE chave = ?");
    $stmt->execute([$chave]);
    $res = $stmt->fetch();
    return $res ? $res['valor'] : $default;
}

function setConfig($chave, $valor) {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO configuracoes (chave, valor, atualizado_em) VALUES (?, ?, CURRENT_TIMESTAMP) ON CONFLICT(chave) DO UPDATE SET valor = excluded.valor, atualizado_em = CURRENT_TIMESTAMP");
    $stmt->execute([$chave, $valor]);
}

/**
 * Salvar produto no cache local
 */
function salvarCacheProduto(string $codigoProduto, string $ean, string $nome = '', string $unidade = 'UN', ?PDO $db = null): void {
    if (empty($codigoProduto)) return;
    $db = $db ?? getDB();
    $stmt = $db->prepare("
        INSERT INTO produtos_cache (codigo_produto, ean, nome, unidade, atualizado_em)
        VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(codigo_produto) DO UPDATE SET
            ean = CASE WHEN excluded.ean != '' THEN excluded.ean ELSE produtos_cache.ean END,
            nome = CASE WHEN excluded.nome != '' THEN excluded.nome ELSE produtos_cache.nome END,
            unidade = CASE WHEN excluded.unidade != '' THEN excluded.unidade ELSE produtos_cache.unidade END,
            atualizado_em = CURRENT_TIMESTAMP
    ");
    $stmt->execute([trim($codigoProduto), trim($ean), trim($nome), trim($unidade)]);
}

/**
 * Resolver o código EAN de um produto:
 * 1) Verifica De-Para customizado local (produtos_ean_custom)
 * 2) Verifica Cache local (produtos_cache)
 * 3) Consulta API do SIGE Cloud e salva no cache
 */
function obterEanProduto(string $codigoProduto, ?SigeClient $sige = null, ?PDO $db = null): string {
    $codigo = trim($codigoProduto);
    if (empty($codigo)) return '';

    $db = $db ?? getDB();

    // 1) De-Para customizado
    $stmtCustom = $db->prepare("SELECT ean_adicional FROM produtos_ean_custom WHERE codigo_produto = ? LIMIT 1");
    $stmtCustom->execute([$codigo]);
    $rowCustom = $stmtCustom->fetch();
    if ($rowCustom && !empty($rowCustom['ean_adicional'])) {
        return trim($rowCustom['ean_adicional']);
    }

    // 2) Cache local
    $stmtCache = $db->prepare("SELECT ean FROM produtos_cache WHERE codigo_produto = ? LIMIT 1");
    $stmtCache->execute([$codigo]);
    $rowCache = $stmtCache->fetch();
    if ($rowCache && !empty($rowCache['ean'])) {
        return trim($rowCache['ean']);
    }

    // 3) Consultar SIGE Cloud
    if ($sige === null) {
        require_once __DIR__ . '/sige_client.php';
        $sige = new SigeClient();
    }

    $prodSige = $sige->obterProduto($codigo);
    if ($prodSige && is_array($prodSige)) {
        $ean = trim($prodSige['Ean'] ?? $prodSige['EAN'] ?? $prodSige['CodigoBarra'] ?? '');
        $nome = trim($prodSige['Nome'] ?? $prodSige['Descricao'] ?? '');
        $unidade = trim($prodSige['EstoqueUnidade'] ?? $prodSige['UnidadeComercial'] ?? 'UN');
        
        salvarCacheProduto($codigo, $ean, $nome, $unidade, $db);
        return $ean;
    }

    return '';
}

/**
 * Buscar produto a partir de um código de barras / EAN:
 * 1) Verifica De-Para customizado local
 * 2) Verifica Cache local
 * 3) Consulta API do SIGE Cloud por EAN e salva no cache
 */
function obterProdutoPorEan(string $ean, ?SigeClient $sige = null, ?PDO $db = null): ?array {
    $cleanEan = trim($ean);
    if (empty($cleanEan)) return null;

    $db = $db ?? getDB();

    // 1) De-para customizado
    $stmtCustom = $db->prepare("SELECT codigo_produto, descricao FROM produtos_ean_custom WHERE ean_adicional = ? LIMIT 1");
    $stmtCustom->execute([$cleanEan]);
    $rowCustom = $stmtCustom->fetch();
    if ($rowCustom) {
        return [
            'codigo_produto' => $rowCustom['codigo_produto'],
            'ean' => $cleanEan,
            'nome' => $rowCustom['descricao'] ?? ''
        ];
    }

    // 2) Cache local
    $stmtCache = $db->prepare("SELECT codigo_produto, ean, nome, unidade FROM produtos_cache WHERE ean = ? LIMIT 1");
    $stmtCache->execute([$cleanEan]);
    $rowCache = $stmtCache->fetch();
    if ($rowCache) {
        return [
            'codigo_produto' => $rowCache['codigo_produto'],
            'ean' => $rowCache['ean'],
            'nome' => $rowCache['nome'],
            'unidade' => $rowCache['unidade']
        ];
    }

    // 3) Consultar SIGE Cloud por EAN
    if ($sige === null) {
        require_once __DIR__ . '/sige_client.php';
        $sige = new SigeClient();
    }

    $prodSige = $sige->obterProduto('', $cleanEan);
    if ($prodSige && is_array($prodSige) && !empty($prodSige['Codigo'])) {
        $cod = trim($prodSige['Codigo']);
        $eanSige = trim($prodSige['Ean'] ?? $cleanEan);
        $nome = trim($prodSige['Nome'] ?? $prodSige['Descricao'] ?? '');
        $unidade = trim($prodSige['EstoqueUnidade'] ?? $prodSige['UnidadeComercial'] ?? 'UN');

        salvarCacheProduto($cod, $eanSige, $nome, $unidade, $db);

        return [
            'codigo_produto' => $cod,
            'ean' => $eanSige,
            'nome' => $nome,
            'unidade' => $unidade
        ];
    }

    return null;
}
