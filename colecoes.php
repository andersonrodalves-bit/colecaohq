<?php
// colecoes.php
require_once 'config.php';
require_once 'auth.php';
requireAdmin();

$db = getDB();

// ============================================================
// AÇÕES (POST)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {
    header('Content-Type: application/json');
    $acao = $_POST['acao'];
    $id = $_POST['id'] ?? 0;
    
    try {
        if ($acao === 'excluir') {
            $db->prepare("UPDATE hqs SET colecao_id = NULL, ordem = NULL WHERE colecao_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM colecoes WHERE id = ?")->execute([$id]);
            echo json_encode(['ok' => true, 'msg' => 'Coleção excluída']); exit;
        }
        if ($acao === 'mesclar') {
            $destino = intval($_POST['destino'] ?? 0);
            $origens = json_decode($_POST['origens'] ?? '[]', true);
            if (!$destino || empty($origens)) { echo json_encode(['ok' => false, 'msg' => 'Dados inválidos']); exit; }
            foreach ($origens as $orig) {
                if ($orig == $destino) continue;
                $db->prepare("UPDATE hqs SET colecao_id = ? WHERE colecao_id = ?")->execute([$destino, $orig]);
                $db->prepare("DELETE FROM colecoes WHERE id = ?")->execute([$orig]);
            }
            echo json_encode(['ok' => true, 'msg' => count($origens) . ' coleção(ões) mesclada(s)']); exit;
        }
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]); exit;
    }
}

// ============================================================
// FILTROS
// ============================================================
$busca = $_GET['busca'] ?? '';
$tipo = $_GET['tipo'] ?? '';
$status = $_GET['status'] ?? '';
$editora_id = $_GET['editora_id'] ?? '';

$query = "SELECT c.*, e.nome as editora_nome,
          (SELECT COUNT(*) FROM hqs h WHERE h.colecao_id = c.id AND h.status='ativo') as qtd_hqs
          FROM colecoes c
          LEFT JOIN editoras e ON c.editora_id = e.id
          WHERE 1=1";
$params = [];

if ($busca) { $query .= " AND c.nome LIKE ?"; $params[] = "%$busca%"; }
if ($tipo) { $query .= " AND c.tipo = ?"; $params[] = $tipo; }
if ($status) { $query .= " AND c.status = ?"; $params[] = $status; }
if ($editora_id) { $query .= " AND c.editora_id = ?"; $params[] = $editora_id; }

$query .= " ORDER BY c.nome ASC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$colecoes = $stmt->fetchAll();

$editoras = $db->query("SELECT * FROM editoras ORDER BY nome")->fetchAll();
$totalSemColecao = $db->query("SELECT COUNT(*) FROM hqs WHERE status='ativo' AND colecao_id IS NULL AND especial=0")->fetchColumn();
$totalEspeciais = $db->query("SELECT COUNT(*) FROM hqs WHERE status='ativo' AND especial=1")->fetchColumn();
$totalColecoes = count($colecoes);

function tarjaStatus($status) {
    return match($status) {
        'concluida' => 'tarja-verde',
        'incompleta' => 'tarja-amarela',
        'cancelada' => 'tarja-vermelha',
        default => 'tarja-cinza'
    };
}

function labelTipo($tipo) {
    return match($tipo) {
        'miniserie' => 'Minissérie',
        'temporada' => 'Temporada',
        default => 'Coleção'
    };
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coleções</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .painel-controle { position: sticky; top: 52px; background: var(--bg); z-index: 1000; padding: 8px 0 4px 0; border-bottom: 1px solid var(--border); }
        .painel-topo { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 4px; }
        .painel-topo h2 { font-size: 16px; font-weight: 600; margin: 0; white-space: nowrap; }
        .resumo-inline { font-size: 12px; display: flex; align-items: center; gap: 6px; flex: 1; min-width: 0; }
        .filter-bar { display: flex; gap: 4px; flex-wrap: wrap; align-items: center; }
        .filter-bar input, .filter-bar select { padding: 4px 6px; font-size: 11px; border: 1px solid var(--border); border-radius: 4px; background: var(--surface); color: var(--text); }
        .filter-bar input { width: 150px; }
        .filter-bar select { max-width: 130px; }
        
        .tarja-verde { border-left: 3px solid #2a9d8f; }
        .tarja-amarela { border-left: 3px solid #e9c46a; }
        .tarja-vermelha { border-left: 3px solid #e63946; }
        .tarja-cinza { border-left: 3px solid #6c757d; }
        
        tr.tarja-verde td:first-child { border-left: 3px solid #2a9d8f; }
        tr.tarja-amarela td:first-child { border-left: 3px solid #e9c46a; }
        tr.tarja-vermelha td:first-child { border-left: 3px solid #e63946; }
        tr.tarja-cinza td:first-child { border-left: 3px solid #6c757d; }
        
        .badge-tipo { padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 500; }
        .badge-tipo.colecao { background: #e0f2fe; color: #0369a1; }
        .badge-tipo.miniserie { background: #ede9fe; color: #6d28d9; }
        .badge-tipo.temporada { background: #fef3c7; color: #92400e; }
        [data-theme="dark"] .badge-tipo.colecao { background: #1e3a5f; color: #7dd3fc; }
        [data-theme="dark"] .badge-tipo.miniserie { background: #2e1e5f; color: #c4b5fd; }
        [data-theme="dark"] .badge-tipo.temporada { background: #3b2e0a; color: #fcd34d; }
        
        .badge-status { padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 500; }
        .badge-status.aberta { background: #dbeafe; color: #1e40af; }
        .badge-status.concluida { background: #d4edda; color: #155724; }
        .badge-status.incompleta { background: #fff3cd; color: #856404; }
        .badge-status.cancelada { background: #f8d7da; color: #721c24; }
        [data-theme="dark"] .badge-status.aberta { background: #1e3a5f; color: #93c5fd; }
        [data-theme="dark"] .badge-status.concluida { background: #0a2e1a; color: #6ee7b7; }
        [data-theme="dark"] .badge-status.incompleta { background: #3b2e0a; color: #fcd34d; }
        [data-theme="dark"] .badge-status.cancelada { background: #3b1219; color: #fda4af; }
        
        .batch-bar { position: fixed; bottom: 0; left: 200px; right: 0; background: var(--surface); border-top: 1px solid var(--border); padding: 8px 20px; display: none; align-items: center; gap: 10px; z-index: 1001; font-size: 12px; flex-wrap: wrap; box-shadow: 0 -2px 10px rgba(0,0,0,0.05); }
        .batch-bar.show { display: flex; }
        .batch-count { font-weight: 600; white-space: nowrap; }
        .batch-bar select { padding: 3px 6px; font-size: 11px; border: 1px solid var(--border); border-radius: 4px; background: var(--surface); color: var(--text); }
        
        @media (max-width: 768px) { .painel-controle { position: static; } .filter-bar input, .filter-bar select { width: 100%; max-width: none; } .batch-bar { left: 0; } }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <main class="main-content">
        <div class="painel-controle">
            <div class="painel-topo">
                <h2>Coleções</h2>
                <a href="colecao_editar.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nova Coleção</a>
                <a href="hqs_lista.php?sem_colecao=1" class="btn btn-outline btn-sm"><i class="fas fa-link"></i> Vincular HQs sem coleção (<?= $totalSemColecao ?>)</a>
                <div class="resumo-inline">
                    <span><strong><?= $totalColecoes ?> coleções</strong> &middot; <strong><?= $totalSemColecao ?></strong> sem coleção &middot; <strong><?= $totalEspeciais ?></strong> especiais</span>
                </div>
            </div>
            <form class="filter-bar" method="GET" id="filter-form">
                <input type="text" name="busca" placeholder="Buscar coleção..." value="<?= htmlspecialchars($busca) ?>" oninput="clearTimeout(this.timer); this.timer=setTimeout(()=>this.form.submit(),800)">
                <select name="tipo" onchange="this.form.submit()">
                    <option value="">Todos os tipos</option>
                    <option value="colecao" <?= $tipo=='colecao'?'selected':'' ?>>Coleção</option>
                    <option value="miniserie" <?= $tipo=='miniserie'?'selected':'' ?>>Minissérie</option>
                    <option value="temporada" <?= $tipo=='temporada'?'selected':'' ?>>Temporada</option>
                </select>
                <select name="status" onchange="this.form.submit()">
                    <option value="">Todos os status</option>
                    <option value="aberta" <?= $status=='aberta'?'selected':'' ?>>Aberta</option>
                    <option value="concluida" <?= $status=='concluida'?'selected':'' ?>>Concluída</option>
                    <option value="incompleta" <?= $status=='incompleta'?'selected':'' ?>>Incompleta</option>
                    <option value="cancelada" <?= $status=='cancelada'?'selected':'' ?>>Cancelada</option>
                </select>
                <select name="editora_id" onchange="this.form.submit()">
                    <option value="">Todas as editoras</option>
                    <?php foreach ($editoras as $e): ?>
                        <option value="<?= $e['id'] ?>" <?= $editora_id==$e['id']?'selected':'' ?>><?= htmlspecialchars($e['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="select-all" title="Todos"></th>
                        <th>Nome da Coleção</th>
                        <th>Tipo</th>
                        <th>Status</th>
                        <th>Editora</th>
                        <th>Edições</th>
                        <th>Temporada</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($colecoes)): ?>
                        <tr><td colspan="8" style="text-align:center; padding:20px; color:var(--text-secondary)">Nenhuma coleção encontrada</td></tr>
                    <?php endif; ?>
                    <?php foreach ($colecoes as $c): ?>
                        <tr class="<?= tarjaStatus($c['status']) ?>" data-id="<?= $c['id'] ?>">
                            <td><input type="checkbox" class="item-check"></td>
                            <td><strong><?= htmlspecialchars($c['nome']) ?></strong></td>
                            <td><span class="badge-tipo <?= $c['tipo'] ?>"><?= labelTipo($c['tipo']) ?></span></td>
                            <td><span class="badge-status <?= $c['status'] ?>"><?= ucfirst($c['status']) ?></span></td>
                            <td><?= htmlspecialchars($c['editora_nome'] ?? '-') ?></td>
                            <td><?= $c['qtd_hqs'] ?><?= $c['total_previsto'] ? ' / ' . $c['total_previsto'] : '' ?></td>
                            <td><?= $c['temporada'] ?: '-' ?></td>
                            <td class="actions">
                                <a href="colecao_editar.php?id=<?= $c['id'] ?>" class="btn-icon" title="Editar"><i class="fas fa-edit"></i></a>
                                <a href="colecao_detalhe.php?id=<?= $c['id'] ?>" class="btn-icon" title="Ver edições"><i class="fas fa-eye"></i></a>
                                <button class="btn-icon text-danger btn-excluir" data-id="<?= $c['id'] ?>" title="Excluir"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>

    <div class="batch-bar" id="batch-bar">
        <span class="batch-count" id="batch-count">0 coleções</span>
        <select id="batch-destino">
            <option value="">Mesclar em...</option>
            <?php foreach ($colecoes as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nome']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-primary btn-sm" id="batch-mesclar"><i class="fas fa-compress"></i> Mesclar</button>
        <button class="btn btn-outline btn-sm" id="batch-cancelar">Cancelar</button>
    </div>

    <script>
        var selectAll = document.getElementById('select-all');
        var batchBar = document.getElementById('batch-bar');
        var batchCount = document.getElementById('batch-count');
        
        function atualizarBatchBar() {
            var checks = document.querySelectorAll('.item-check:checked');
            batchCount.textContent = checks.length + ' coleções';
            batchBar.classList.toggle('show', checks.length >= 1);
        }
        
        selectAll.onchange = function() {
            document.querySelectorAll('.item-check').forEach(function(c) { c.checked = selectAll.checked; });
            atualizarBatchBar();
        };
        
        document.querySelectorAll('.item-check').forEach(function(c) { c.onchange = atualizarBatchBar; });
        
        document.getElementById('batch-cancelar').onclick = function() {
            document.querySelectorAll('.item-check').forEach(function(c) { c.checked = false; });
            selectAll.checked = false;
            atualizarBatchBar();
        };
        
        document.getElementById('batch-mesclar').onclick = function() {
            var destino = document.getElementById('batch-destino').value;
            if (!destino) { alert('Selecione a coleção de destino'); return; }
            var origens = [];
            document.querySelectorAll('.item-check:checked').forEach(function(c) {
                origens.push(c.closest('tr').dataset.id);
            });
            if (origens.length === 0) { alert('Selecione pelo menos 1 coleção'); return; }
            if (!confirm('Mesclar ' + origens.length + ' coleção(ões) na coleção destino? As HQs serão movidas e as origens excluídas.')) return;
            
            var f = new FormData();
            f.append('acao', 'mesclar');
            f.append('destino', destino);
            f.append('origens', JSON.stringify(origens));
            fetch('colecoes.php', { method: 'POST', body: f })
                .then(function(r) { return r.json(); })
                .then(function(d) { if (d.ok) { alert(d.msg); location.reload(); } else alert(d.msg); });
        };
        
        document.querySelectorAll('.btn-excluir').forEach(function(b) {
            b.onclick = function() {
                if (!confirm('Excluir esta coleção? As HQs ficarão sem coleção.')) return;
                var f = new FormData();
                f.append('acao', 'excluir');
                f.append('id', this.dataset.id);
                fetch('colecoes.php', { method: 'POST', body: f })
                    .then(function(r) { return r.json(); })
                    .then(function(d) { if (d.ok) location.reload(); else alert(d.msg); });
            };
        });
    </script>
</body>
</html>