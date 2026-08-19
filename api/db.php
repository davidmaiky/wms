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

        CREATE INDEX IF NOT EXISTS idx_conferencias_num ON conferencias(numero_pedido);
        CREATE INDEX IF NOT EXISTS idx_conferencias_status ON conferencias(status);
        CREATE INDEX IF NOT EXISTS idx_itens_conferencia ON conferencia_itens(conferencia_id);
        CREATE INDEX IF NOT EXISTS idx_itens_codigo ON conferencia_itens(codigo_produto);
        CREATE INDEX IF NOT EXISTS idx_itens_ean ON conferencia_itens(ean);
        CREATE INDEX IF NOT EXISTS idx_ean_custom ON produtos_ean_custom(ean_adicional);
    ");

    // Inserir configurações padrão se não existirem
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO configuracoes (chave, valor) VALUES (?, ?)");
    $stmt->execute(['sige_token', DEFAULT_SIGE_TOKEN]);
    $stmt->execute(['sige_user', DEFAULT_SIGE_USER]);
    $stmt->execute(['sige_app', DEFAULT_SIGE_APP]);
    $stmt->execute(['som_habilitado', '1']);
    $stmt->execute(['modo_cego', '0']); // 0 = normal, 1 = conferência cega
    $stmt->execute(['operador_padrao', 'Operador']);
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
