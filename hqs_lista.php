<?php
// hqs_lista.php
require_once 'config.php';
require_once 'auth.php';
requireAdmin();

$db = getDB();

// ============================================================
// AÇÃO EM LOTE (POST)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao_lote'])) {
    header('Content-Type: application/json');
    $ids = json_decode($_POST['ids'] ?? '[]', true);
    $campos = [];
    if (!empty($_POST['jornaleiro'])) $campos['jornaleiro'] = $_POST['jornaleiro'];
    if (!empty($_POST['formato_id'])) $campos['formato_id'] = $_POST['formato_id'];
    if (!empty($_POST['editora_id'])) $campos['editora_id'] = $_POST['editora_id'];
    if (!empty($_POST['local_prateleira'])) $campos['local_prateleira'] = $_POST['local_prateleira'];
    if (isset($_POST['colecao_id']) && $_POST['colecao_id'] !== '') {
        $campos['colecao_id'] = $_POST['colecao_id'] === '0' ? null : $_POST['colecao_id'];
    }
    if (isset($_POST['especial']) && $_POST['especial'] !== '') $campos['especial'] = intval($_POST['especial']);
    
    if (empty($ids) || empty($campos)) { echo json_encode(['ok' => false, 'msg' => 'Nenhum item ou campo']); exit; }
    $sets = []; $params = [];
    foreach ($campos as $col => $val) { $sets[] = "$col = ?"; $params[] = $val; }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge($params, $ids);
    $db->prepare("UPDATE hqs SET " . implode(', ', $sets) . ", updated_at=CURRENT_TIMESTAMP WHERE id IN ($placeholders)")->execute($params);
    echo json_encode(['ok' => true, 'msg' => count($ids) . ' HQ(s) atualizadas']); exit;
}

// ============================================================
// EXCLUSÃO/RESTAURAÇÃO INDIVIDUAL
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {
    header('Content-Type: application/json');
    $id = $_POST['id'] ?? 0; $acao = $_POST['acao'];
    try {
        if ($acao === 'excluir') {
            $stmt = $db->prepare("SELECT * FROM hqs WHERE id=? AND status='ativo'"); $stmt->execute([$id]); $hq = $stmt->fetch();
            if (!$hq) { echo json_encode(['ok' => false]); exit; }
            $db->prepare("INSERT INTO hqs_excluidas (id, editora_id, formato_id, titulo, numero_edicao, preco_compra, mes_ano_publicacao, data_publicacao, jornaleiro, observacao, link_origem, imagem_capa, local_prateleira) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$hq['id'], $hq['editora_id'], $hq['formato_id'], $hq['titulo'], $hq['numero_edicao'], $hq['preco_compra'], $hq['mes_ano_publicacao'], $hq['data_publicacao'], $hq['jornaleiro'], $hq['observacao'], $hq['link_origem'], $hq['imagem_capa'], $hq['local_prateleira']]);
            $db->prepare("UPDATE hqs SET status='excluido', updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$id]);
            echo json_encode(['ok' => true]); exit;
        } elseif ($acao === 'restaurar') {
            $db->prepare("UPDATE hqs SET status='ativo', updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$id]);
            $db->prepare("DELETE FROM hqs_excluidas WHERE id=?")->execute([$id]);
            echo json_encode(['ok' => true]); exit;
        } elseif ($acao === 'excluir_permanente') {
            $stmt = $db->prepare("SELECT imagem_capa FROM hqs WHERE id=? AND status='excluido'"); $stmt->execute([$id]); $hq = $stmt->fetch();
            if ($hq && !empty($hq['imagem_capa'])) { $c = __DIR__ . '/' . $hq['imagem_capa']; if (file_exists($c)) unlink($c); }
            $db->prepare("DELETE FROM hqs WHERE id=? AND status='excluido'")->execute([$id]);
            $db->prepare("DELETE FROM hqs_excluidas WHERE id=?")->execute([$id]);
            echo json_encode(['ok' => true]); exit;
        }
    } catch (Exception $e) { echo json_encode(['ok' => false, 'msg' => $e->getMessage()]); exit; }
}

// ============================================================
// RESUMO
// ============================================================
$totalAtivos = $db->query("SELECT COUNT(*) FROM hqs WHERE status='ativo'")->fetchColumn();
$totalExcluidos = $db->query("SELECT COUNT(*) FROM hqs WHERE status='excluido'")->fetchColumn();

// ============================================================
// FILTROS E PAGINAÇÃO
// ============================================================
$busca = $_GET['busca'] ?? '';
$editora_id = $_GET['editora_id'] ?? '';
$formato_id = $_GET['formato_id'] ?? '';
$ordenar = $_GET['ordenar'] ?? 'titulo_asc';
$jornaleiro = $_GET['jornaleiro'] ?? '';
$status = $_GET['status'] ?? 'ativo';
$sem_colecao = isset($_GET['sem_colecao']) ? 1 : 0;
$especial = $_GET['especial'] ?? '';
$pagina = max(1, intval($_GET['pagina'] ?? 1));
$porPagina = intval($_GET['por_pagina'] ?? 50);
if (!in_array($porPagina, [50,100,200,500,1000])) $porPagina = 50;

$query = "SELECT h.*, e.nome as editora_nome, f.nome as formato_nome, c.nome as colecao_nome FROM hqs h LEFT JOIN editoras e ON h.editora_id=e.id LEFT JOIN formatos f ON h.formato_id=f.id LEFT JOIN colecoes c ON h.colecao_id=c.id WHERE h.status=?";
$countQuery = "SELECT COUNT(*) FROM hqs h LEFT JOIN editoras e ON h.editora_id=e.id LEFT JOIN formatos f ON h.formato_id=f.id WHERE h.status=?";
$params = [$status]; $countParams = [$status];

if ($busca) {
    $clauses = []; foreach (['h.titulo','h.numero_edicao','e.nome','f.nome','CAST(h.preco_compra AS TEXT)','h.mes_ano_publicacao','h.local_prateleira','h.jornaleiro'] as $c) { $clauses[] = "$c LIKE ?"; $params[] = "%$busca%"; }
    $extra = " AND (" . implode(" OR ", $clauses) . ")"; $query .= $extra; $countQuery .= $extra; $countParams = $params;
}
if ($editora_id) { $query .= " AND h.editora_id = ?"; $params[] = $editora_id; $countQuery .= " AND h.editora_id = ?"; $countParams[] = $editora_id; }
if ($formato_id) { $query .= " AND h.formato_id = ?"; $params[] = $formato_id; $countQuery .= " AND h.formato_id = ?"; $countParams[] = $formato_id; }
if ($jornaleiro) { $query .= " AND h.jornaleiro = ?"; $params[] = $jornaleiro; $countQuery .= " AND h.jornaleiro = ?"; $countParams[] = $jornaleiro; }
if ($sem_colecao) { $query .= " AND h.colecao_id IS NULL AND h.especial = 0"; $countQuery .= " AND h.colecao_id IS NULL AND h.especial = 0"; }
if ($especial !== '') { $query .= " AND h.especial = ?"; $params[] = intval($especial); $countQuery .= " AND h.especial = ?"; $countParams[] = intval($especial); }

$stmtCount = $db->prepare($countQuery); $stmtCount->execute($countParams);
$totalRegistros = $stmtCount->fetchColumn();
$totalPaginas = $totalRegistros > 0 ? ceil($totalRegistros / $porPagina) : 1;
if ($pagina > $totalPaginas) $pagina = $totalPaginas;
$offset = ($pagina - 1) * $porPagina;

switch ($ordenar) {
    case 'titulo_asc': $order = 'h.titulo ASC'; break;
    case 'titulo_desc': $order = 'h.titulo DESC'; break;
    case 'data_asc': $order = "CASE WHEN h.data_publicacao IS NULL THEN 1 ELSE 0 END, h.data_publicacao ASC"; break;
    case 'data_desc': $order = "CASE WHEN h.data_publicacao IS NULL THEN 1 ELSE 0 END, h.data_publicacao DESC"; break;
    case 'preco_asc': $order = 'h.preco_compra ASC'; break;
    case 'preco_desc': $order = 'h.preco_compra DESC'; break;
    default: $order = 'h.titulo ASC';
}
$query .= " ORDER BY $order LIMIT ? OFFSET ?"; $params[] = $porPagina; $params[] = $offset;
$stmt = $db->prepare($query); $stmt->execute($params); $hqs = $stmt->fetchAll();

$editoras = $db->query("SELECT * FROM editoras ORDER BY nome")->fetchAll();
$formatos = $db->query("SELECT * FROM formatos ORDER BY nome")->fetchAll();
$colecoes = $db->query("SELECT id, nome FROM colecoes ORDER BY nome")->fetchAll();

$qs = function($overrides = []) use ($busca, $editora_id, $formato_id, $jornaleiro, $ordenar, $status, $sem_colecao, $especial, $pagina, $porPagina) {
    $p = ['busca' => $busca, 'editora_id' => $editora_id, 'formato_id' => $formato_id, 'jornaleiro' => $jornaleiro, 'ordenar' => $ordenar, 'status' => $status, 'pagina' => $pagina, 'por_pagina' => $porPagina];
    if ($sem_colecao) $p['sem_colecao'] = 1;
    if ($especial !== '') $p['especial'] = $especial;
    foreach ($overrides as $k => $v) $p[$k] = $v;
    return http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null));
};
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar HQs</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .painel-controle { position: sticky; top: 52px; background: var(--bg); z-index: 999; padding: 8px 0 4px 0; border-bottom: 1px solid var(--border); }
        .painel-topo { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 4px; }
        .painel-topo h2 { font-size: 16px; font-weight: 600; margin: 0; white-space: nowrap; }
        .resumo-inline { font-size: 12px; display: flex; align-items: center; gap: 6px; flex: 1; min-width: 0; }
        .filter-bar { display: flex; gap: 4px; flex-wrap: wrap; align-items: center; }
        .filter-bar input, .filter-bar select { padding: 4px 6px; font-size: 11px; border: 1px solid var(--border); border-radius: 4px; background: var(--surface); color: var(--text); }
        .filter-bar input { width: 130px; } .filter-bar select { max-width: 120px; }
        .paginacao { display: flex; align-items: center; gap: 4px; font-size: 11px; margin-top: 2px; flex-wrap: wrap; }
        .paginacao select { padding: 2px 4px; font-size: 11px; border: 1px solid var(--border); border-radius: 4px; background: var(--surface); color: var(--text); }
        .paginacao a, .paginacao .btn-pagina { padding: 2px 6px; border: 1px solid var(--border); border-radius: 4px; text-decoration: none; color: var(--text); background: var(--surface); font-size: 11px; }
        .paginacao a.active, .paginacao .btn-pagina.active { background: var(--primary); color: white; border-color: var(--primary); }
        .row-excluido { opacity: 0.5; } .actions { white-space: nowrap; }
        .btn-relatorio { padding: 3px 8px; font-size: 11px; border: 1px solid var(--border); border-radius: 4px; text-decoration: none; color: var(--text); background: var(--surface); margin-left: 8px; }
        .btn-relatorio:hover { background: var(--surface-hover); }
        .btn-relatorio.excel { color: #217346; border-color: #217346; }
        .btn-relatorio.pdf { color: #e63946; border-color: #e63946; }
        
        .batch-bar { position: fixed; bottom: 0; left: 200px; right: 0; background: var(--surface); border-top: 1px solid var(--border); padding: 8px 20px; display: none; align-items: center; gap: 10px; z-index: 1001; font-size: 12px; flex-wrap: wrap; box-shadow: 0 -2px 10px rgba(0,0,0,0.05); }
        .batch-bar.show { display: flex; }
        .batch-bar select, .batch-bar input[type="text"] { padding: 3px 6px; font-size: 11px; border: 1px solid var(--border); border-radius: 4px; background: var(--surface); color: var(--text); }
        .batch-bar select { max-width: 130px; } .batch-bar input[type="text"] { width: 120px; }
        .batch-count { font-weight: 600; white-space: nowrap; }
        
        .tarja-verde { border-left: 3px solid #2a9d8f; } .tarja-cinza { border-left: 3px solid #6c757d; } .tarja-vermelha { border-left: 3px solid #e63946; }
        .badge-especial { padding: 1px 6px; border-radius: 8px; font-size: 9px; background: #fce7f3; color: #9d174d; font-weight: 500; }
        [data-theme="dark"] .badge-especial { background: #3b1235; color: #f9a8d4; }
        .badge-colecao { padding: 1px 6px; border-radius: 8px; font-size: 9px; background: #e0f2fe; color: #0369a1; font-weight: 500; }
        [data-theme="dark"] .badge-colecao { background: #1e3a5f; color: #7dd3fc; }
        @media (max-width: 768px) { .painel-controle { position: static; } .filter-bar input, .filter-bar select { width: 100%; max-width: none; } .batch-bar { left: 0; padding: 8px 10px; gap: 6px; } }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <main class="main-content">
        <div class="painel-controle">
            <div class="painel-topo">
                <h2>Gerenciar HQs</h2>
                <a href="hqs_cadastro.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Cadastrar</a>
                <div class="resumo-inline"><span><strong><?= $totalAtivos ?> ativas</strong> &middot; <strong><?= $totalExcluidos ?> excluídas</strong></span></div>
            </div>
            <form class="filter-bar" method="GET" id="filter-form">
                <input type="text" name="busca" placeholder="Buscar..." value="<?= htmlspecialchars($busca) ?>" oninput="clearTimeout(this.timer); this.timer=setTimeout(()=>this.form.submit(),800)">
                <select name="editora_id" onchange="this.form.submit()"><option value="">Editoras</option><?php foreach ($editoras as $e): ?><option value="<?= $e['id'] ?>" <?= $editora_id==$e['id']?'selected':'' ?>><?= htmlspecialchars($e['nome']) ?></option><?php endforeach; ?></select>
                <select name="formato_id" onchange="this.form.submit()"><option value="">Formatos</option><?php foreach ($formatos as $f): ?><option value="<?= $f['id'] ?>" <?= $formato_id==$f['id']?'selected':'' ?>><?= htmlspecialchars($f['nome']) ?></option><?php endforeach; ?></select>
                <select name="jornaleiro" onchange="this.form.submit()"><option value="">Situação</option><option value="Nao Informado" <?= $jornaleiro=='Nao Informado'?'selected':'' ?>>Não Informado</option><option value="Na Banca" <?= $jornaleiro=='Na Banca'?'selected':'' ?>>Na Banca</option><option value="Adquirido" <?= $jornaleiro=='Adquirido'?'selected':'' ?>>Adquirido</option></select>
                <select name="especial" onchange="this.form.submit()">
                    <option value="">Todas</option>
                    <option value="0" <?= $especial==='0'?'selected':'' ?>>Não especiais</option>
                    <option value="1" <?= $especial==='1'?'selected':'' ?>>Especiais</option>
                </select>
                <select name="status" onchange="this.form.submit()"><option value="ativo" <?= $status=='ativo'?'selected':'' ?>>Ativos</option><option value="excluido" <?= $status=='excluido'?'selected':'' ?>>Excluídos</option></select>
                <select name="ordenar" onchange="this.form.submit()"><option value="titulo_asc" <?= $ordenar=='titulo_asc'?'selected':'' ?>>A-Z</option><option value="titulo_desc" <?= $ordenar=='titulo_desc'?'selected':'' ?>>Z-A</option><option value="data_desc" <?= $ordenar=='data_desc'?'selected':'' ?>>+Novo</option><option value="data_asc" <?= $ordenar=='data_asc'?'selected':'' ?>>+Antigo</option><option value="preco_asc" <?= $ordenar=='preco_asc'?'selected':'' ?>>+Barato</option><option value="preco_desc" <?= $ordenar=='preco_desc'?'selected':'' ?>>+Caro</option></select>
                <?php if ($sem_colecao): ?><input type="hidden" name="sem_colecao" value="1"><?php endif; ?>
            </form>
            <?php if ($sem_colecao): ?>
                <div style="font-size:11px; margin-top:2px; color:var(--primary);"><i class="fas fa-filter"></i> Filtrando apenas HQs sem coleção · <a href="hqs_lista.php" style="color:var(--text-secondary)">limpar filtro</a></div>
            <?php endif; ?>
            <div class="paginacao">
                <span><?= $totalRegistros ?> de <?= $totalPaginas ?> pág.</span>
                <select name="por_pagina" onchange="var p=new URLSearchParams(window.location.search);p.set('por_pagina',this.value);p.set('pagina','1');window.location.search=p.toString();">
                    <?php foreach ([50,100,200,500,1000] as $v): ?><option value="<?= $v ?>" <?= $porPagina==$v?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
                </select>
                <?php if ($pagina > 1): ?><a href="?<?= $qs(['pagina' => $pagina-1]) ?>">&#8249;</a><?php endif; ?>
                <?php for ($p = max(1, $pagina-2); $p <= min($totalPaginas, $pagina+2); $p++): ?>
                    <a href="?<?= $qs(['pagina' => $p]) ?>" class="<?= $p==$pagina?'active':'' ?>"><?= $p ?></a>
                <?php endfor; ?>
                <?php if ($pagina < $totalPaginas): ?><a href="?<?= $qs(['pagina' => $pagina+1]) ?>">&#8250;</a><?php endif; ?>
                <a href="relatorios.php?<?= $qs() ?>&tipo=excel" class="btn-relatorio excel" target="_blank"><i class="fas fa-file-excel"></i> Excel</a>
                <a href="relatorios.php?<?= $qs() ?>&tipo=pdf" class="btn-relatorio pdf" target="_blank"><i class="fas fa-file-pdf"></i> PDF</a>
            </div>
        </div>

        <div class="table-container">
            <table class="data-table" id="hq-table">
                <thead><tr><th><input type="checkbox" id="select-all" title="Todos"></th><th>Capa</th><th>Título</th><th>Edição</th><th>Editora</th><th>Formato</th><th>Publicação</th><th>Preço</th><th>Situação</th><th>Coleção</th><th>Ações</th></tr></thead>
                <tbody id="hq-table-body">
                    <?php foreach ($hqs as $hq): 
                        $tarja = match($hq['jornaleiro']) { 'Adquirido' => 'tarja-verde', 'Na Banca' => 'tarja-cinza', default => 'tarja-vermelha' };
                    ?>
                        <tr class="<?= $tarja ?> <?= $hq['status']=='excluido'?'row-excluido':'' ?>" data-id="<?= $hq['id'] ?>">
                            <td><input type="checkbox" class="item-check" data-especial="<?= $hq['especial'] ?>"></td>
                            <td><?php if ($hq['imagem_capa']): ?><img src="<?= htmlspecialchars($hq['imagem_capa']) ?>" width="40"><?php else: ?><i class="fas fa-book-open"></i><?php endif; ?></td>
                            <td>
                                <?= htmlspecialchars($hq['titulo']) ?>
                                <?php if ($hq['especial']): ?><span class="badge-especial">Especial</span><?php endif; ?>
                            </td>
                            <td><?= $hq['numero_edicao'] ?></td>
                            <td><?= htmlspecialchars($hq['editora_nome']) ?></td>
                            <td><?= htmlspecialchars($hq['formato_nome']) ?></td>
                            <td><?= htmlspecialchars($hq['mes_ano_publicacao']) ?></td>
                            <td>R$ <?= number_format($hq['preco_compra'], 2, ',', '.') ?></td>
                            <td><?= $hq['jornaleiro'] ?></td>
                            <td>
                                <?php if ($hq['colecao_nome']): ?>
                                    <span class="badge-colecao"><?= htmlspecialchars($hq['colecao_nome']) ?></span>
                                <?php elseif ($hq['especial']): ?>
                                    <span style="font-size:10px; color:var(--text-secondary);">-</span>
                                <?php else: ?>
                                    <span style="font-size:10px; color:var(--text-secondary);">Sem coleção</span>
                                <?php endif; ?>
                            </td>
                            <td class="actions">
                                <?php if ($hq['status'] == 'ativo'): ?>
                                    <a href="hqs_editar.php?id=<?= $hq['id'] ?>" class="btn-icon"><i class="fas fa-edit"></i></a>
                                    <button class="btn-icon btn-excluir" data-id="<?= $hq['id'] ?>"><i class="fas fa-trash"></i></button>
                                <?php else: ?>
                                    <button class="btn btn-outline btn-xs btn-restaurar" data-id="<?= $hq['id'] ?>"><i class="fas fa-undo"></i> Rest.</button>
                                    <button class="btn btn-danger btn-xs btn-excluir-permanente" data-id="<?= $hq['id'] ?>"><i class="fas fa-trash-alt"></i> Excl.</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>

    <div class="batch-bar" id="batch-bar">
        <span class="batch-count" id="batch-count">0 itens</span>
        <select id="batch-jornaleiro"><option value="">Situação</option><option value="Nao Informado">Não Informado</option><option value="Na Banca">Na Banca</option><option value="Adquirido">Adquirido</option></select>
        <select id="batch-formato"><option value="">Formato</option><?php foreach ($formatos as $f): ?><option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['nome']) ?></option><?php endforeach; ?></select>
        <select id="batch-editora"><option value="">Editora</option><?php foreach ($editoras as $e): ?><option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['nome']) ?></option><?php endforeach; ?></select>
        <select id="batch-colecao"><option value="">Coleção</option><option value="0">- Remover coleção -</option><?php foreach ($colecoes as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nome']) ?></option><?php endforeach; ?></select>
        <select id="batch-especial"><option value="">Especial</option><option value="1">Marcar especial</option><option value="0">Desmarcar especial</option></select>
        <input type="text" id="batch-prateleira" placeholder="Prateleira">
        <button class="btn btn-primary btn-sm" id="batch-aplicar"><i class="fas fa-check"></i> Aplicar</button>
        <button class="btn btn-outline btn-sm" id="batch-cancelar">Cancelar</button>
    </div>

    <script>
        var selectAll = document.getElementById('select-all');
        var batchBar = document.getElementById('batch-bar');
        var batchCount = document.getElementById('batch-count');
        function atualizarBatchBar() {
            var checks = document.querySelectorAll('.item-check:checked');
            batchCount.textContent = checks.length + ' itens';
            batchBar.classList.toggle('show', checks.length > 0);
        }
        selectAll.onchange = function() { document.querySelectorAll('.item-check').forEach(function(c) { c.checked = selectAll.checked; }); atualizarBatchBar(); };
        document.querySelectorAll('.item-check').forEach(function(c) { c.onchange = atualizarBatchBar; });
        document.getElementById('batch-cancelar').onclick = function() { document.querySelectorAll('.item-check').forEach(function(c) { c.checked = false; }); selectAll.checked = false; atualizarBatchBar(); };
        document.getElementById('batch-aplicar').onclick = function() {
            var ids = []; 
            document.querySelectorAll('.item-check:checked').forEach(function(c) { 
                var especial = c.dataset.especial === '1';
                var colVal = document.getElementById('batch-colecao').value;
                if (colVal && colVal !== '0' && especial) return;
                ids.push(c.closest('tr').dataset.id); 
            });
            if (!ids.length) { alert('Nenhum item válido selecionado'); return; }
            
            var f = new FormData(); f.append('acao_lote', 1); f.append('ids', JSON.stringify(ids));
            f.append('jornaleiro', document.getElementById('batch-jornaleiro').value);
            f.append('formato_id', document.getElementById('batch-formato').value);
            f.append('editora_id', document.getElementById('batch-editora').value);
            var col = document.getElementById('batch-colecao').value;
            if (col === '0') { f.append('colecao_id', '0'); } else if (col) { f.append('colecao_id', col); }
            f.append('especial', document.getElementById('batch-especial').value);
            f.append('local_prateleira', document.getElementById('batch-prateleira').value);
            
            fetch('hqs_lista.php', { method: 'POST', body: f }).then(function(r) { return r.json(); }).then(function(d) { if (d.ok) { alert(d.msg); location.reload(); } else alert(d.msg); });
        };
        function enviarAcao(acao, id) {
            var f = new FormData(); f.append('acao', acao); f.append('id', id);
            return fetch('hqs_lista.php', { method: 'POST', body: f }).then(function(r) { return r.json(); }).then(function(d) { if (d.ok) location.reload(); else alert(d.msg); });
        }
        document.querySelectorAll('.btn-excluir').forEach(function(b) { b.onclick = function() { if (confirm('Excluir?')) enviarAcao('excluir', this.dataset.id); }; });
        document.querySelectorAll('.btn-restaurar').forEach(function(b) { b.onclick = function() { if (confirm('Restaurar?')) enviarAcao('restaurar', this.dataset.id); }; });
        document.querySelectorAll('.btn-excluir-permanente').forEach(function(b) { b.onclick = function() { if (confirm('EXCLUIR PERMANENTE?')) enviarAcao('excluir_permanente', this.dataset.id); }; });
    </script>
</body>
</html>