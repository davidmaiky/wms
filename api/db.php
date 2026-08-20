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

        CREATE TABLE IF NOT EXISTS locais_armazenagem (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            codigo TEXT UNIQUE NOT NULL, -- Ex: A-01-02-01
            armazem TEXT DEFAULT 'Principal',
            rua TEXT NOT NULL, -- Ex: A
            estante TEXT NOT NULL, -- Ex: 01
            nivel TEXT NOT NULL, -- Ex: 02
            posicao TEXT NOT NULL, -- Ex: 01
            tipo TEXT DEFAULT 'picking', -- picking, pulmao, quarentena, avarias
            capacidade_maxima REAL DEFAULT 0,
            peso_max_kg REAL DEFAULT 0,
            status TEXT DEFAULT 'ativo', -- ativo, inativo, manutencao
            observacoes TEXT,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS produtos_enderecos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            codigo_produto TEXT NOT NULL,
            local_id INTEGER NOT NULL,
            tipo TEXT DEFAULT 'principal', -- principal, reserva, temporario
            quantidade_atual REAL DEFAULT 0,
            capacidade_maxima REAL DEFAULT 0,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (local_id) REFERENCES locais_armazenagem(id) ON DELETE CASCADE,
            UNIQUE(codigo_produto, local_id)
        );

        CREATE TABLE IF NOT EXISTS recebimentos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            numero_documento TEXT, -- Número NF-e ou Pedido de Compra
            serie_documento TEXT DEFAULT '1',
            chave_nfe TEXT, -- Chave de 44 dígitos
            fornecedor_nome TEXT,
            fornecedor_cnpj TEXT,
            fornecedor_uf TEXT,
            valor_total REAL DEFAULT 0,
            status TEXT DEFAULT 'pendente', -- pendente, em_conferencia, divergencia, conferido, armazenado, cancelado
            total_itens INTEGER DEFAULT 0,
            itens_conferidos INTEGER DEFAULT 0,
            quantidade_total_esperada REAL DEFAULT 0,
            quantidade_total_conferida REAL DEFAULT 0,
            data_emissao DATETIME,
            data_recebimento DATETIME,
            data_armazenamento DATETIME,
            operador TEXT,
            observacoes TEXT,
            xml_raw TEXT,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS recebimento_itens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recebimento_id INTEGER NOT NULL,
            codigo_produto TEXT,
            ean TEXT,
            descricao TEXT,
            unidade TEXT DEFAULT 'UN',
            quantidade_esperada REAL DEFAULT 0,
            quantidade_conferida REAL DEFAULT 0,
            valor_unitario REAL DEFAULT 0,
            lote TEXT,
            data_validade DATE,
            local_sugerido_id INTEGER,
            local_armazenado_id INTEGER,
            status TEXT DEFAULT 'pendente', -- pendente, parcial, conferido, divergente
            FOREIGN KEY (recebimento_id) REFERENCES recebimentos(id) ON DELETE CASCADE,
            FOREIGN KEY (local_sugerido_id) REFERENCES locais_armazenagem(id) ON DELETE SET NULL,
            FOREIGN KEY (local_armazenado_id) REFERENCES locais_armazenagem(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS logs_bipagem_recebimento (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recebimento_id INTEGER NOT NULL,
            codigo_bipado TEXT,
            codigo_produto_identificado TEXT,
            tipo_leitura TEXT, -- camera, pistola, manual
            resultado TEXT, -- sucesso, produto_nao_pertence, quantidade_excedida, erro
            operador TEXT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (recebimento_id) REFERENCES recebimentos(id) ON DELETE CASCADE
        );

        -- =====================================================================
        -- FASE 2: INVENTÁRIO CÍCLICO & CONTAGEM DE ESTOQUE (CYCLE COUNTING)
        -- =====================================================================
        CREATE TABLE IF NOT EXISTS inventarios (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            titulo TEXT NOT NULL,
            tipo TEXT DEFAULT 'localizacao', -- localizacao, sku, total
            modo TEXT DEFAULT 'cego', -- cego (sem qtd esperada visível), aberto
            armazem TEXT DEFAULT 'Principal',
            rua_inicio TEXT,
            rua_fim TEXT,
            status TEXT DEFAULT 'pendente', -- pendente, em_contagem, finalizado, cancelado, ajustado
            total_itens_esperados REAL DEFAULT 0,
            total_itens_contados REAL DEFAULT 0,
            acuracidade_pct REAL DEFAULT 100.0,
            operador TEXT,
            observacoes TEXT,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            finalizado_em DATETIME
        );

        CREATE TABLE IF NOT EXISTS inventario_itens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            inventario_id INTEGER NOT NULL,
            local_id INTEGER NOT NULL,
            codigo_produto TEXT NOT NULL,
            ean TEXT,
            descricao TEXT,
            quantidade_sistema REAL DEFAULT 0,
            quantidade_contada REAL DEFAULT 0,
            divergencia REAL DEFAULT 0, -- quantidade_contada - quantidade_sistema
            status TEXT DEFAULT 'pendente', -- pendente, contado, ajustado, divergente
            contado_por TEXT,
            contado_em DATETIME,
            FOREIGN KEY (inventario_id) REFERENCES inventarios(id) ON DELETE CASCADE,
            FOREIGN KEY (local_id) REFERENCES locais_armazenagem(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS logs_bipagem_inventario (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            inventario_id INTEGER NOT NULL,
            local_id INTEGER,
            codigo_bipado TEXT,
            codigo_produto_identificado TEXT,
            tipo_leitura TEXT, -- camera, pistola, manual
            operador TEXT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (inventario_id) REFERENCES inventarios(id) ON DELETE CASCADE
        );

        -- =====================================================================
        -- FASE 2: GESTÃO DE DEVOLUÇÕES & LOGÍSTICA REVERSA (RETURNS MANAGEMENT)
        -- =====================================================================
        CREATE TABLE IF NOT EXISTS devolucoes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            numero_pedido_origem INTEGER,
            cliente_nome TEXT,
            codigo_rastreio TEXT,
            motivo_principal TEXT, -- arrependimento, defeito, produto_errado, avaria_transporte, insucesso_entrega, outro
            status TEXT DEFAULT 'recebido', -- recebido, em_inspecao, concluido, rejeitado
            operador TEXT,
            observacoes TEXT,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS devolucao_itens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            devolucao_id INTEGER NOT NULL,
            codigo_produto TEXT NOT NULL,
            ean TEXT,
            descricao TEXT,
            quantidade REAL DEFAULT 1,
            condicao TEXT DEFAULT 'perfeito', -- perfeito, avariado, incompleto, embalagem_violada
            motivo TEXT,
            acao_destinatario TEXT DEFAULT 'reestocar_picking', -- reestocar_picking, reestocar_pulmao, quarentena, descarte
            local_armazenagem_id INTEGER,
            status TEXT DEFAULT 'pendente', -- pendente, reestocado, descartado, devolvido_fornecedor
            inspecionado_por TEXT,
            inspecionado_em DATETIME,
            FOREIGN KEY (devolucao_id) REFERENCES devolucoes(id) ON DELETE CASCADE,
            FOREIGN KEY (local_armazenagem_id) REFERENCES locais_armazenagem(id) ON DELETE SET NULL
        );

        -- =====================================================================
        -- FASE 3: SEPARAÇÃO EM ONDA / LOTE (WAVE PICKING) & PACKING STATION
        -- =====================================================================
        CREATE TABLE IF NOT EXISTS ondas_separacao (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            codigo_onda TEXT NOT NULL UNIQUE,
            status TEXT DEFAULT 'pendente', -- pendente, em_separacao, separado, concluido, cancelado
            total_pedidos INTEGER DEFAULT 0,
            total_itens INTEGER DEFAULT 0,
            total_unidades REAL DEFAULT 0,
            unidades_coletadas REAL DEFAULT 0,
            operador TEXT,
            observacoes TEXT,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            finalizado_em DATETIME
        );

        CREATE TABLE IF NOT EXISTS onda_pedidos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            onda_id INTEGER NOT NULL,
            numero_pedido INTEGER NOT NULL,
            cliente TEXT,
            caixa_box_numero INTEGER DEFAULT 1, -- Número da colmeia/box (1..N)
            status TEXT DEFAULT 'pendente', -- pendente, coletando, separado, embalado
            FOREIGN KEY (onda_id) REFERENCES ondas_separacao(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS onda_itens_consolidados (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            onda_id INTEGER NOT NULL,
            codigo_produto TEXT NOT NULL,
            ean TEXT,
            descricao TEXT,
            local_id INTEGER,
            quantidade_total REAL DEFAULT 0,
            quantidade_coletada REAL DEFAULT 0,
            status TEXT DEFAULT 'pendente', -- pendente, parcial, coletado
            FOREIGN KEY (onda_id) REFERENCES ondas_separacao(id) ON DELETE CASCADE,
            FOREIGN KEY (local_id) REFERENCES locais_armazenagem(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS logs_bipagem_onda (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            onda_id INTEGER NOT NULL,
            numero_pedido INTEGER,
            codigo_bipado TEXT,
            codigo_produto_identificado TEXT,
            caixa_box_numero INTEGER,
            tipo_leitura TEXT, -- camera, pistola, manual
            operador TEXT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (onda_id) REFERENCES ondas_separacao(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS packing_checkouts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            numero_pedido INTEGER NOT NULL,
            cliente TEXT,
            peso_teorico_kg REAL DEFAULT 0,
            peso_balanca_kg REAL DEFAULT 0,
            diferenca_peso_g REAL DEFAULT 0,
            volumes_total INTEGER DEFAULT 1,
            status_peso TEXT DEFAULT 'ok', -- ok, divergente
            observacoes TEXT,
            operador TEXT,
            finalizado_em DATETIME DEFAULT CURRENT_TIMESTAMP
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
        CREATE INDEX IF NOT EXISTS idx_locais_codigo ON locais_armazenagem(codigo);
        CREATE INDEX IF NOT EXISTS idx_locais_armazem_rua ON locais_armazenagem(armazem, rua, estante, nivel, posicao);
        CREATE INDEX IF NOT EXISTS idx_prod_end_prod ON produtos_enderecos(codigo_produto);
        CREATE INDEX IF NOT EXISTS idx_prod_end_local ON produtos_enderecos(local_id);
        CREATE INDEX IF NOT EXISTS idx_receb_status ON recebimentos(status);
        CREATE INDEX IF NOT EXISTS idx_receb_doc ON recebimentos(numero_documento);
        CREATE INDEX IF NOT EXISTS idx_receb_chave ON recebimentos(chave_nfe);
        CREATE INDEX IF NOT EXISTS idx_receb_itens_rec ON recebimento_itens(recebimento_id);
        CREATE INDEX IF NOT EXISTS idx_receb_itens_cod ON recebimento_itens(codigo_produto);
        CREATE INDEX IF NOT EXISTS idx_receb_itens_ean ON recebimento_itens(ean);
        CREATE INDEX IF NOT EXISTS idx_inv_status ON inventarios(status);
        CREATE INDEX IF NOT EXISTS idx_inv_itens_inv ON inventario_itens(inventario_id);
        CREATE INDEX IF NOT EXISTS idx_inv_itens_local ON inventario_itens(local_id);
        CREATE INDEX IF NOT EXISTS idx_inv_itens_cod ON inventario_itens(codigo_produto);
        CREATE INDEX IF NOT EXISTS idx_dev_status ON devolucoes(status);
        CREATE INDEX IF NOT EXISTS idx_dev_ped ON devolucoes(numero_pedido_origem);
        CREATE INDEX IF NOT EXISTS idx_dev_itens_dev ON devolucao_itens(devolucao_id);
        CREATE INDEX IF NOT EXISTS idx_dev_itens_cod ON devolucao_itens(codigo_produto);
        CREATE INDEX IF NOT EXISTS idx_ondas_cod ON ondas_separacao(codigo_onda);
        CREATE INDEX IF NOT EXISTS idx_ondas_status ON ondas_separacao(status);
        CREATE INDEX IF NOT EXISTS idx_onda_ped_onda ON onda_pedidos(onda_id);
        CREATE INDEX IF NOT EXISTS idx_onda_ped_num ON onda_pedidos(numero_pedido);
        CREATE INDEX IF NOT EXISTS idx_onda_itens_onda ON onda_itens_consolidados(onda_id);
        CREATE INDEX IF NOT EXISTS idx_onda_itens_cod ON onda_itens_consolidados(codigo_produto);
        CREATE INDEX IF NOT EXISTS idx_packing_ped ON packing_checkouts(numero_pedido);
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
        'locais_visualizar' => [
            'id' => 'locais_visualizar',
            'nome' => 'Visualizar Endereçamento',
            'descricao' => 'Consultar estrutura física do armazém, ruas, prateleiras e posições.',
            'categoria' => 'Endereçamento',
            'icone' => 'fa-solid fa-location-dot'
        ],
        'locais_gerenciar' => [
            'id' => 'locais_gerenciar',
            'nome' => 'Gerenciar Endereços & Saldo',
            'descricao' => 'Cadastrar, editar, gerar posições em lote e vincular produtos a locais.',
            'categoria' => 'Endereçamento',
            'icone' => 'fa-solid fa-warehouse'
        ],
        'recebimento_visualizar' => [
            'id' => 'recebimento_visualizar',
            'nome' => 'Visualizar Recebimentos',
            'descricao' => 'Consultar notas fiscais e ordens de recebimento de fornecedores.',
            'categoria' => 'Recebimento',
            'icone' => 'fa-solid fa-truck-ramp-box'
        ],
        'recebimento_criar' => [
            'id' => 'recebimento_criar',
            'nome' => 'Importar XML / Criar Entrada',
            'descricao' => 'Fazer upload de XML de NF-e e cadastrar novas ordens de recebimento.',
            'categoria' => 'Recebimento',
            'icone' => 'fa-solid fa-file-arrow-up'
        ],
        'recebimento_conferir' => [
            'id' => 'recebimento_conferir',
            'nome' => 'Conferir Entrada / Bipagem',
            'descricao' => 'Realizar a conferência cega ou guiada de produtos recebidos do fornecedor.',
            'categoria' => 'Recebimento',
            'icone' => 'fa-solid fa-barcode'
        ],
        'recebimento_armazenar' => [
            'id' => 'recebimento_armazenar',
            'nome' => 'Guarda & Putaway',
            'descricao' => 'Confirmar o armazenamento dos produtos nas prateleiras de destino.',
            'categoria' => 'Recebimento',
            'icone' => 'fa-solid fa-boxes-packing'
        ],
        'inventario_visualizar' => [
            'id' => 'inventario_visualizar',
            'nome' => 'Visualizar Inventários',
            'descricao' => 'Consultar contagens de estoque, relatórios de acuracidade e divergências.',
            'categoria' => 'Inventário',
            'icone' => 'fa-solid fa-clipboard-list'
        ],
        'inventario_criar' => [
            'id' => 'inventario_criar',
            'nome' => 'Criar Ordem de Inventário',
            'descricao' => 'Gerar sessões de contagem cíclica por localização ou curva de produtos.',
            'categoria' => 'Inventário',
            'icone' => 'fa-solid fa-folder-plus'
        ],
        'inventario_contar' => [
            'id' => 'inventario_contar',
            'nome' => 'Contagem Cega & Scanner',
            'descricao' => 'Escanear e registrar contagens de produtos nas posições físicas do armazém.',
            'categoria' => 'Inventário',
            'icone' => 'fa-solid fa-barcode'
        ],
        'inventario_aprovar' => [
            'id' => 'inventario_aprovar',
            'nome' => 'Aprovar Conciliação & Ajuste',
            'descricao' => 'Aprovar divergências e ajustar saldos de estoque automaticamente no WMS.',
            'categoria' => 'Inventário',
            'icone' => 'fa-solid fa-check-double'
        ],
        'devolucao_visualizar' => [
            'id' => 'devolucao_visualizar',
            'nome' => 'Visualizar Devoluções',
            'descricao' => 'Consultar ordens de logística reversa e histórico de devoluções.',
            'categoria' => 'Devoluções',
            'icone' => 'fa-solid fa-rotate-left'
        ],
        'devolucao_criar' => [
            'id' => 'devolucao_criar',
            'nome' => 'Registrar Devolução',
            'descricao' => 'Cadastrar entrada de devolução vinculada a pedido de venda ou rastreio.',
            'categoria' => 'Devoluções',
            'icone' => 'fa-solid fa-box-open'
        ],
        'devolucao_inspecionar' => [
            'id' => 'devolucao_inspecionar',
            'nome' => 'Triagem, Inspeção & Reestocagem',
            'descricao' => 'Inspecionar avarias, definir motivo e direcionar item para picking ou quarentena.',
            'categoria' => 'Devoluções',
            'icone' => 'fa-solid fa-microscope'
        ],
        'onda_visualizar' => [
            'id' => 'onda_visualizar',
            'nome' => 'Visualizar Ondas de Separação',
            'descricao' => 'Consultar listas de separação em lote / onda (Wave Picking).',
            'categoria' => 'Wave Picking',
            'icone' => 'fa-solid fa-layer-group'
        ],
        'onda_criar' => [
            'id' => 'onda_criar',
            'nome' => 'Criar Onda de Separação',
            'descricao' => 'Agrupar múltiplos pedidos em lote e gerar rota unificada.',
            'categoria' => 'Wave Picking',
            'icone' => 'fa-solid fa-wand-magic-sparkles'
        ],
        'onda_separar' => [
            'id' => 'onda_separar',
            'nome' => 'Executar Separação de Onda',
            'descricao' => 'Bipar itens da onda e distribuir nas caixas/colmeias (Put-to-Box).',
            'categoria' => 'Wave Picking',
            'icone' => 'fa-solid fa-barcode'
        ],
        'packing_visualizar' => [
            'id' => 'packing_visualizar',
            'nome' => 'Visualizar Packing Station',
            'descricao' => 'Acessar a bancada de embalagem e checkout de pedidos.',
            'categoria' => 'Packing Station',
            'icone' => 'fa-solid fa-box-check'
        ],
        'packing_embalar' => [
            'id' => 'packing_embalar',
            'nome' => 'Conferir & Concluir Embalagem',
            'descricao' => 'Realizar conferência final, validação de peso e despacho de volumes.',
            'categoria' => 'Packing Station',
            'icone' => 'fa-solid fa-check-double'
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
                'locais_visualizar',
                'locais_gerenciar',
                'recebimento_visualizar',
                'recebimento_criar',
                'recebimento_conferir',
                'recebimento_armazenar',
                'inventario_visualizar',
                'inventario_criar',
                'inventario_contar',
                'inventario_aprovar',
                'devolucao_visualizar',
                'devolucao_criar',
                'devolucao_inspecionar',
                'onda_visualizar',
                'onda_criar',
                'onda_separar',
                'packing_visualizar',
                'packing_embalar',
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
                'locais_visualizar',
                'recebimento_visualizar',
                'recebimento_conferir',
                'recebimento_armazenar',
                'inventario_visualizar',
                'inventario_contar',
                'devolucao_visualizar',
                'devolucao_inspecionar',
                'onda_visualizar',
                'onda_separar',
                'packing_visualizar',
                'packing_embalar',
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
                'historico_visualizar',
                'locais_visualizar',
                'recebimento_visualizar',
                'recebimento_conferir',
                'inventario_visualizar',
                'inventario_contar',
                'devolucao_visualizar',
                'onda_visualizar',
                'onda_separar',
                'packing_visualizar',
                'packing_embalar'
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

/**
 * Obtém o endereço principal de armazenagem de um SKU
 */
function obterEnderecoPrincipalProduto(string $codigoProduto, ?PDO $db = null): ?array {
    $cleanCod = trim($codigoProduto);
    if (empty($cleanCod)) return null;

    $db = $db ?? getDB();
    $stmt = $db->prepare("
        SELECT pe.*, l.codigo AS local_codigo, l.armazem, l.rua, l.estante, l.nivel, l.posicao, l.tipo AS local_tipo
        FROM produtos_enderecos pe
        INNER JOIN locais_armazenagem l ON l.id = pe.local_id
        WHERE pe.codigo_produto = ?
        ORDER BY CASE WHEN pe.tipo = 'principal' THEN 0 ELSE 1 END, pe.id ASC
        LIMIT 1
    ");
    $stmt->execute([$cleanCod]);
    $res = $stmt->fetch();
    return $res ?: null;
}

/**
 * Obtém todos os endereços vinculados a um SKU
 */
function obterEnderecosProduto(string $codigoProduto, ?PDO $db = null): array {
    $cleanCod = trim($codigoProduto);
    if (empty($cleanCod)) return [];

    $db = $db ?? getDB();
    $stmt = $db->prepare("
        SELECT pe.*, l.codigo AS local_codigo, l.armazem, l.rua, l.estante, l.nivel, l.posicao, l.tipo AS local_tipo
        FROM produtos_enderecos pe
        INNER JOIN locais_armazenagem l ON l.id = pe.local_id
        WHERE pe.codigo_produto = ?
        ORDER BY CASE WHEN pe.tipo = 'principal' THEN 0 ELSE 1 END, pe.id ASC
    ");
    $stmt->execute([$cleanCod]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Atribui ou atualiza endereço de armazenagem para um SKU
 */
function atribuirEnderecoProduto(string $codigoProduto, int $localId, float $quantidade = 0, string $tipo = 'principal', ?PDO $db = null): bool {
    $cleanCod = trim($codigoProduto);
    if (empty($cleanCod) || $localId <= 0) return false;

    $db = $db ?? getDB();
    $stmt = $db->prepare("
        INSERT INTO produtos_enderecos (codigo_produto, local_id, tipo, quantidade_atual, atualizado_em)
        VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(codigo_produto, local_id) DO UPDATE SET
            tipo = excluded.tipo,
            quantidade_atual = CASE WHEN excluded.quantidade_atual > 0 THEN excluded.quantidade_atual ELSE produtos_enderecos.quantidade_atual END,
            atualizado_em = CURRENT_TIMESTAMP
    ");
    return $stmt->execute([$cleanCod, $localId, $tipo, $quantidade]);
}

