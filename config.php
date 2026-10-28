<?php
// config.php
define('DB_PATH', __DIR__ . '/database.sqlite');

function getDB() {
    static $db = null;
    if ($db === null) {
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("PRAGMA journal_mode = WAL");
        $db->exec("PRAGMA foreign_keys = ON");
    }
    return $db;
}

function parseDataPublicacao($texto) {
    if (empty($texto)) return null;
    $texto = trim($texto);

    // Mapeamento de meses em português (com e sem acentos, abreviados e completos)
    $meses = [
        'janeiro' => '01', 'fevereiro' => '02', 'março' => '03', 'marco' => '03',
        'abril' => '04', 'maio' => '05', 'junho' => '06',
        'julho' => '07', 'agosto' => '08', 'setembro' => '09',
        'outubro' => '10', 'novembro' => '11', 'dezembro' => '12',
        'jan' => '01', 'fev' => '02', 'mar' => '03', 'abr' => '04',
        'mai' => '05', 'jun' => '06', 'jul' => '07', 'ago' => '08',
        'set' => '09', 'out' => '10', 'nov' => '11', 'dez' => '12'
    ];

    // Tenta formatos com mês textual: "Março/2026", "março de 2022", "Agosto 2026"
    if (preg_match('/(' . implode('|', array_keys($meses)) . ')[a-z]*\s*(?:\/|de\s+)?\s*(\d{4})/i', $texto, $m)) {
        $mes = strtolower($m[1]);
        $ano = $m[2];
        if (isset($meses[$mes])) {
            return "$ano-{$meses[$mes]}-01";
        }
    }

    // Tenta formatos numéricos: "05/2026", "06-2025"
    if (preg_match('/(\d{1,2})[\/\-](\d{4})/', $texto, $m)) {
        $mes = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $ano = $m[2];
        if ($mes >= 1 && $mes <= 12) {
            return "$ano-$mes-01";
        }
    }

    // Tenta ano sozinho
    if (preg_match('/\b(\d{4})\b/', $texto, $m)) {
        return $m[1] . '-01-01';
    }

    return null;
}

function initDB() {
    $db = getDB();
    $db->exec("
        CREATE TABLE IF NOT EXISTS usuarios (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL,
            email TEXT UNIQUE NOT NULL,
            senha TEXT NOT NULL,
            tipo TEXT DEFAULT 'jornaleiro',
            ativo INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS editoras (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT UNIQUE NOT NULL,
            observacao TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS formatos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT UNIQUE NOT NULL,
            observacao TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS hqs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            editora_id INTEGER,
            formato_id INTEGER,
            titulo TEXT NOT NULL,
            numero_edicao TEXT DEFAULT '',
            preco_compra REAL DEFAULT 0.0,
            mes_ano_publicacao TEXT DEFAULT '',
            data_publicacao DATE,
            jornaleiro TEXT DEFAULT 'Nao Informado',
            observacao TEXT DEFAULT '',
            link_origem TEXT DEFAULT '',
            imagem_capa TEXT DEFAULT '',
            local_prateleira TEXT DEFAULT '',
            status TEXT DEFAULT 'ativo',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (editora_id) REFERENCES editoras(id),
            FOREIGN KEY (formato_id) REFERENCES formatos(id)
        );
        CREATE TABLE IF NOT EXISTS hqs_excluidas (
            id INTEGER PRIMARY KEY,
            editora_id INTEGER,
            formato_id INTEGER,
            titulo TEXT,
            numero_edicao TEXT,
            preco_compra REAL,
            mes_ano_publicacao TEXT,
            data_publicacao DATE,
            jornaleiro TEXT,
            observacao TEXT,
            link_origem TEXT,
            imagem_capa TEXT,
            local_prateleira TEXT,
            data_exclusao DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Adiciona coluna data_publicacao se não existir (migração)
    $cols = $db->query("PRAGMA table_info(hqs)")->fetchAll();
    $hasCol = false;
    foreach ($cols as $col) {
        if ($col['name'] === 'data_publicacao') $hasCol = true;
    }
    if (!$hasCol) {
        $db->exec("ALTER TABLE hqs ADD COLUMN data_publicacao DATE");
        $db->exec("ALTER TABLE hqs_excluidas ADD COLUMN data_publicacao DATE");
    }

    // Migra dados existentes: preencher data_publicacao baseado em mes_ano_publicacao
    $hqs = $db->query("SELECT id, mes_ano_publicacao FROM hqs WHERE data_publicacao IS NULL AND mes_ano_publicacao != ''")->fetchAll();
    $stmt = $db->prepare("UPDATE hqs SET data_publicacao = ? WHERE id = ?");
    foreach ($hqs as $hq) {
        $d = parseDataPublicacao($hq['mes_ano_publicacao']);
        $stmt->execute([$d, $hq['id']]);
    }

    // Usuário admin padrão
    $stmt = $db->query("SELECT COUNT(*) FROM usuarios");
    if ($stmt->fetchColumn() == 0) {
        $db->prepare("INSERT INTO usuarios (nome, email, senha, tipo) VALUES (?, ?, ?, 'administrador')")
           ->execute(['Administrador', 'admin@admin.com', password_hash('admin123', PASSWORD_DEFAULT)]);
    }
    $editoras = ['Panini Comics', 'Abril', 'Mythos', 'Pixel Media', 'Outra'];
    $stmt = $db->prepare("INSERT OR IGNORE INTO editoras (nome) VALUES (?)");
    foreach ($editoras as $e) $stmt->execute([$e]);
    $formatos = ['Omnibus', 'Capa Dura', 'Capa Cartao', 'Brochura', 'Digital'];
    $stmt = $db->prepare("INSERT OR IGNORE INTO formatos (nome) VALUES (?)");
    foreach ($formatos as $f) $stmt->execute([$f]);
}

initDB();