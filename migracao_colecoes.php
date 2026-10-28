<?php
// migracao_colecoes.php
require_once 'config.php';

$db = getDB();

echo "<pre>";
echo "=== Migração de Coleções ===\n\n";

// ============================================================
// 1. CRIAR TABELA COLECOES
// ============================================================
$db->exec("
    CREATE TABLE IF NOT EXISTS colecoes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nome TEXT UNIQUE NOT NULL,
        tipo TEXT DEFAULT 'colecao',
        status TEXT DEFAULT 'aberta',
        temporada INTEGER,
        total_previsto INTEGER,
        editora_id INTEGER,
        capa_id INTEGER,
        observacao TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");
echo "✓ Tabela 'colecoes' criada.\n";

// ============================================================
// 2. ADICIONAR COLUNAS EM HQS
// ============================================================
$cols = $db->query("PRAGMA table_info(hqs)")->fetchAll();
$hasColecaoId = false;
$hasOrdem = false;
foreach ($cols as $c) {
    if ($c['name'] === 'colecao_id') $hasColecaoId = true;
    if ($c['name'] === 'ordem') $hasOrdem = true;
}

if (!$hasColecaoId) {
    $db->exec("ALTER TABLE hqs ADD COLUMN colecao_id INTEGER");
    echo "✓ Coluna 'colecao_id' adicionada em hqs.\n";
} else {
    echo "- Coluna 'colecao_id' já existe.\n";
}

if (!$hasOrdem) {
    $db->exec("ALTER TABLE hqs ADD COLUMN ordem INTEGER");
    echo "✓ Coluna 'ordem' adicionada em hqs.\n";
} else {
    echo "- Coluna 'ordem' já existe.\n";
}

// ============================================================
// 3. FUNÇÕES AUXILIARES
// ============================================================

function normalizarTitulo($titulo) {
    $t = mb_strtolower(trim($titulo), 'UTF-8');
    $t = preg_replace('/[áàãâä]/u', 'a', $t);
    $t = preg_replace('/[éèêë]/u', 'e', $t);
    $t = preg_replace('/[íìîï]/u', 'i', $t);
    $t = preg_replace('/[óòõôö]/u', 'o', $t);
    $t = preg_replace('/[úùûü]/u', 'u', $t);
    $t = preg_replace('/[ç]/u', 'c', $t);
    $t = preg_replace('/\s+/', ' ', $t);
    return trim($t);
}

function extrairBaseETemporada($titulo) {
    $base = $titulo;
    $temporada = null;

    // Remove sufixos de numeração: "n° X", "Nº X", "Vol. X", "Volume X", "#X", "(2022)", "X/Y", números soltos no fim
    $base = preg_replace('/\s*[-–—]?\s*n[°ºo]?\s*\d+.*$/iu', '', $base);
    $base = preg_replace('/\s*[-–—]?\s*vol\.?\s*\d+.*$/iu', '', $base);
    $base = preg_replace('/\s*[-–—]?\s*volume\s*\d+.*$/iu', '', $base);
    $base = preg_replace('/\s*#\s*\d+.*$/u', '', $base);
    $base = preg_replace('/\s*\(\d{4}\).*$/u', '', $base);
    $base = preg_replace('/\s+\d+(\/\d+)?\s*$/u', '', $base);
    $base = preg_replace('/\s*[-–—]\s*\d+\s*$/u', '', $base);

    // Detecta temporada: "Temporada 2", "2ª Temporada", "Temporada 1/25"
    if (preg_match('/(\d+)\s*[ªa]?\s*temporada/iu', $titulo, $m)) {
        $temporada = intval($m[1]);
        $base = preg_replace('/\s*[-–—]?\s*\d+\s*[ªa]?\s*temporada.*$/iu', '', $base);
    } elseif (preg_match('/temporada\s*(\d+)/iu', $titulo, $m)) {
        $temporada = intval($m[1]);
        $base = preg_replace('/\s*[-–—]?\s*temporada\s*\d+.*$/iu', '', $base);
    }

    $base = trim($base);
    return [$base, $temporada];
}

// ============================================================
// 4. CLASSIFICAÇÃO AUTOMÁTICA
// ============================================================
echo "\n=== Iniciando classificação automática ===\n";

// Buscar todas as HQs ativas sem coleção
$hqs = $db->query("SELECT id, titulo, numero_edicao, editora_id FROM hqs WHERE status='ativo' AND (colecao_id IS NULL OR colecao_id = 0) ORDER BY titulo")->fetchAll();
echo "HQs sem coleção: " . count($hqs) . "\n";

// Agrupar por base + editora
$grupos = [];
foreach ($hqs as $hq) {
    list($base, $temporada) = extrairBaseETemporada($hq['titulo']);
    if (strlen($base) < 3) continue; // ignora títulos muito curtos
    $chave = normalizarTitulo($base) . '|' . intval($hq['editora_id']) . '|' . intval($temporada);
    if (!isset($grupos[$chave])) {
        $grupos[$chave] = [
            'nome' => $base,
            'temporada' => $temporada,
            'editora_id' => $hq['editora_id'],
            'hqs' => []
        ];
    }
    $grupos[$chave]['hqs'][] = $hq;
}

echo "Grupos identificados: " . count($grupos) . "\n\n";

$criadas = 0;
$vinculadas = 0;

foreach ($grupos as $chave => $g) {
    // Só cria coleção se tiver 2 ou mais HQs no grupo
    if (count($g['hqs']) < 2) continue;

    $nome = $g['nome'];
    if ($g['temporada']) $nome .= ' - Temporada ' . $g['temporada'];

    // Verificar se já existe
    $stmt = $db->prepare("SELECT id FROM colecoes WHERE nome = ?");
    $stmt->execute([$nome]);
    $existe = $stmt->fetch();
    if ($existe) {
        $colecao_id = $existe['id'];
    } else {
        // Detectar tipo e status básicos
        $tipo = 'colecao';
        if ($g['temporada']) $tipo = 'temporada';

        $stmt = $db->prepare("INSERT INTO colecoes (nome, tipo, status, temporada, editora_id, total_previsto) VALUES (?, ?, 'aberta', ?, ?, ?)");
        $stmt->execute([$nome, $tipo, $g['temporada'], $g['editora_id'], count($g['hqs'])]);
        $colecao_id = $db->lastInsertId();
        $criadas++;

        // Definir capa como a primeira HQ
        $db->prepare("UPDATE colecoes SET capa_id = ? WHERE id = ?")->execute([$g['hqs'][0]['id'], $colecao_id]);
    }

    // Vincular HQs à coleção
    foreach ($g['hqs'] as $hq) {
        // Extrair número da edição para ordem
        $ordem = null;
        if (preg_match('/n[°ºo]?\s*(\d+)/iu', $hq['titulo'], $m)) $ordem = intval($m[1]);
        elseif (preg_match('/vol\.?\s*(\d+)/iu', $hq['titulo'], $m)) $ordem = intval($m[1]);
        elseif (preg_match('/(\d+)\s*$/u', $hq['titulo'], $m)) $ordem = intval($m[1]);
        elseif (is_numeric($hq['numero_edicao'])) $ordem = intval($hq['numero_edicao']);

        $db->prepare("UPDATE hqs SET colecao_id = ?, ordem = ? WHERE id = ?")->execute([$colecao_id, $ordem, $hq['id']]);
        $vinculadas++;
    }
}

echo "Coleções criadas: $criadas\n";
echo "HQs vinculadas: $vinculadas\n\n";
echo "=== Migração concluída! ===\n";
echo "Acesse 'colecoes.php' para validar as coleções sugeridas.\n";
echo "</pre>";