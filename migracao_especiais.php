<?php
// migracao_especiais.php
require_once 'config.php';

$db = getDB();

echo "<pre>";
echo "=== Migração: Edições Especiais ===\n\n";

// ============================================================
// 1. ADICIONAR COLUNA 'especial' EM hqs
// ============================================================
$cols = $db->query("PRAGMA table_info(hqs)")->fetchAll();
$hasEspecial = false;
foreach ($cols as $c) {
    if ($c['name'] === 'especial') $hasEspecial = true;
}

if (!$hasEspecial) {
    $db->exec("ALTER TABLE hqs ADD COLUMN especial INTEGER DEFAULT 0");
    echo "✓ Coluna 'especial' adicionada em hqs.\n";
} else {
    echo "- Coluna 'especial' já existe.\n";
}

// ============================================================
// 2. REMOVER COLEÇÕES DO TIPO 'especial' (se existirem)
// ============================================================
$stmt = $db->query("SELECT id, nome FROM colecoes WHERE tipo = 'especial'");
$especiais = $stmt->fetchAll();

if (count($especiais) > 0) {
    foreach ($especiais as $e) {
        // Desvincula HQs e marca como especial
        $db->prepare("UPDATE hqs SET colecao_id = NULL, ordem = NULL, especial = 1 WHERE colecao_id = ?")->execute([$e['id']]);
        $db->prepare("DELETE FROM colecoes WHERE id = ?")->execute([$e['id']]);
        echo "✓ Coleção removida: {$e['nome']} (HQs marcadas como Especial)\n";
    }
} else {
    echo "- Nenhuma coleção do tipo 'especial' encontrada.\n";
}

// ============================================================
// 3. DETECTAR AUTOMATICAMENTE HQs QUE SÃO ESPECIAIS
// ============================================================
// Palavras-chave no título que indicam edição especial/única
$palavrasEspeciais = [
    'edição especial', 'edicao especial', 'especial', 'one-shot', 'one shot',
    'edição única', 'edicao unica', 'edição definitiva', 'edicao definitiva',
    'edição comemorativa', 'edicao comemorativa', 'anual', 'annual',
    'edições especiais', 'edicoes especiais'
];

$hqsSemColecao = $db->query("SELECT id, titulo FROM hqs WHERE status='ativo' AND colecao_id IS NULL AND especial = 0")->fetchAll();
$marcadas = 0;

foreach ($hqsSemColecao as $hq) {
    $tituloLower = mb_strtolower($hq['titulo'], 'UTF-8');
    $tituloNorm = preg_replace('/[áàãâä]/u', 'a', $tituloLower);
    $tituloNorm = preg_replace('/[éèêë]/u', 'e', $tituloNorm);
    $tituloNorm = preg_replace('/[íìîï]/u', 'i', $tituloNorm);
    $tituloNorm = preg_replace('/[óòõôö]/u', 'o', $tituloNorm);
    $tituloNorm = preg_replace('/[úùûü]/u', 'u', $tituloNorm);
    $tituloNorm = preg_replace('/ç/u', 'c', $tituloNorm);
    
    foreach ($palavrasEspeciais as $palavra) {
        if (strpos($tituloNorm, $palavra) !== false) {
            $db->prepare("UPDATE hqs SET especial = 1 WHERE id = ?")->execute([$hq['id']]);
            $marcadas++;
            break;
        }
    }
}

echo "\n✓ HQs marcadas automaticamente como Especial: $marcadas\n";
echo "\n=== Migração concluída! ===\n";
echo "</pre>";