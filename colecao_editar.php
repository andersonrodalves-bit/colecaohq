<?php
// colecao_editar.php
require_once 'config.php';
require_once 'auth.php';
requireAdmin();

$db = getDB();

$id = intval($_GET['id'] ?? 0);
$modo = $id ? 'editar' : 'novo';
$msg = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    if ($acao === 'salvar') {
        $nome = trim($_POST['nome'] ?? '');
        $tipo = $_POST['tipo'] ?? 'colecao';
        // Bloquear tipo especial
        if ($tipo === 'especial') $tipo = 'colecao';
        $status = $_POST['status'] ?? 'aberta';
        $temporada = $_POST['temporada'] !== '' ? intval($_POST['temporada']) : null;
        $total_previsto = $_POST['total_previsto'] !== '' ? intval($_POST['total_previsto']) : null;
        $editora_id = $_POST['editora_id'] !== '' ? intval($_POST['editora_id']) : null;
        $capa_id = isset($_POST['capa_id']) && $_POST['capa_id'] !== '' ? intval($_POST['capa_id']) : null;
        $observacao = $_POST['observacao'] ?? '';
        
        if (!$nome) {
            $erro = 'Nome é obrigatório';
        } else {
            try {
                if ($id) {
                    $db->prepare("UPDATE colecoes SET nome=?, tipo=?, status=?, temporada=?, total_previsto=?, editora_id=?, capa_id=?, observacao=? WHERE id=?")
                       ->execute([$nome, $tipo, $status, $temporada, $total_previsto, $editora_id, $capa_id, $observacao, $id]);
                    $msg = 'Coleção atualizada!';
                } else {
                    $db->prepare("INSERT INTO colecoes (nome, tipo, status, temporada, total_previsto, editora_id, capa_id, observacao) VALUES (?,?,?,?,?,?,?,?)")
                       ->execute([$nome, $tipo, $status, $temporada, $total_previsto, $editora_id, $capa_id, $observacao]);
                    $id = $db->lastInsertId();
                    $modo = 'editar';
                    $msg = 'Coleção criada!';
                }
            } catch (Exception $e) {
                $erro = 'Erro: nome já existe ou inválido';
            }
        }
    }
    
    if ($acao === 'remover_hq') {
        $hq_id = intval($_POST['hq_id'] ?? 0);
        $db->prepare("UPDATE hqs SET colecao_id = NULL, ordem = NULL WHERE id = ?")->execute([$hq_id]);
        $msg = 'HQ removida da coleção.';
    }
    
    if ($acao === 'adicionar_hqs') {
        $hq_ids = json_decode($_POST['hq_ids'] ?? '[]', true);
        foreach ($hq_ids as $hq_id) {
            // Ignorar HQs marcadas como especiais
            $stmt = $db->prepare("SELECT titulo, numero_edicao, especial FROM hqs WHERE id = ?");
            $stmt->execute([$hq_id]);
            $hq = $stmt->fetch();
            if ($hq && !$hq['especial']) {
                $ordem = null;
                if (preg_match('/n[°ºo]?\s*(\d+)/iu', $hq['titulo'], $m)) $ordem = intval($m[1]);
                elseif (preg_match('/vol\.?\s*(\d+)/iu', $hq['titulo'], $m)) $ordem = intval($m[1]);
                elseif (preg_match('/(\d+)\s*$/u', $hq['titulo'], $m)) $ordem = intval($m[1]);
                elseif (is_numeric($hq['numero_edicao'])) $ordem = intval($hq['numero_edicao']);
                $db->prepare("UPDATE hqs SET colecao_id = ?, ordem = ? WHERE id = ?")->execute([$id, $ordem, $hq_id]);
            }
        }
        $msg = 'HQ(s) adicionada(s) à coleção.';
    }
    
    if ($acao === 'salvar_ordem') {
        $ordens = json_decode($_POST['ordens'] ?? '[]', true);
        foreach ($ordens as $hq_id => $ordem) {
            $db->prepare("UPDATE hqs SET ordem = ? WHERE id = ? AND colecao_id = ?")->execute([intval($ordem), intval($hq_id), $id]);
        }
        $msg = 'Ordem atualizada.';
    }
}

$colecao = null;
if ($id) {
    $stmt = $db->prepare("SELECT * FROM colecoes WHERE id = ?");
    $stmt->execute([$id]);
    $colecao = $stmt->fetch();
    if (!$colecao) { header('Location: colecoes.php'); exit; }
}

$hqsVinculadas = [];
if ($id) {
    $stmt = $db->prepare("SELECT h.*, e.nome as editora_nome, f.nome as formato_nome FROM hqs h LEFT JOIN editoras e ON h.editora_id=e.id LEFT JOIN formatos f ON h.formato_id=f.id WHERE h.colecao_id = ? AND h.status='ativo' ORDER BY h.ordem ASC, h.titulo ASC");
    $stmt->execute([$id]);
    $hqsVinculadas = $stmt->fetchAll();
}

$editoras = $db->query("SELECT * FROM editoras ORDER BY nome")->fetchAll();

// HQs disponíveis: sem coleção ou em outra coleção E NÃO especiais
$hqsDisponiveis = $db->query("
    SELECT h.id, h.titulo, h.numero_edicao, h.colecao_id, c.nome as colecao_nome, e.nome as editora_nome
    FROM hqs h
    LEFT JOIN colecoes c ON h.colecao_id = c.id
    LEFT JOIN editoras e ON h.editora_id = e.id
    WHERE h.status='ativo' 
      AND h.especial = 0
      AND (h.colecao_id IS NULL OR h.colecao_id != " . intval($id) . ")
    ORDER BY h.titulo ASC
    LIMIT 500
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $modo === 'editar' ? 'Editar' : 'Nova' ?> Coleção</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .page-header h2 { font-size: 16px; font-weight: 600; margin: 0; }
        .form-section { background: var(--surface); border-radius: 8px; padding: 10px 12px; margin-bottom: 10px; border: 1px solid var(--border); }
        .form-section h3 { font-size: 12px; font-weight: 600; margin: 0 0 8px 0; }
        .form-linha { display: flex; gap: 6px; flex-wrap: wrap; align-items: flex-end; }
        .form-linha .form-group { display: flex; flex-direction: column; gap: 2px; }
        .form-linha label { font-size: 10px; color: var(--text-secondary); font-weight: 500; }
        .form-linha input, .form-linha select, .form-linha textarea { padding: 4px 6px; font-size: 11px; border: 1px solid var(--border); border-radius: 4px; background: var(--bg); color: var(--text); }
        .form-linha input[type="text"] { min-width: 150px; }
        .form-linha textarea { min-width: 200px; }
        .form-actions { display: flex; gap: 6px; justify-content: flex-end; margin-top: 8px; }
        
        .hq-list { max-height: 500px; overflow-y: auto; }
        .hq-item { display: flex; align-items: center; gap: 8px; padding: 4px 6px; border-bottom: 1px solid var(--border); font-size: 11px; }
        .hq-item img { width: 28px; height: 38px; object-fit: cover; border-radius: 3px; }
        .hq-item .ordem-input { width: 45px; padding: 2px 4px; font-size: 11px; text-align: center; border: 1px solid var(--border); border-radius: 3px; background: var(--bg); color: var(--text); }
        .hq-item .titulo { flex: 1; }
        
        .btn-mini { padding: 2px 7px; font-size: 10px; border-radius: 4px; border: 1px solid var(--border); background: var(--surface); color: var(--text); cursor: pointer; text-decoration: none; }
        .btn-mini:hover { background: var(--surface-hover); }
        .btn-mini.danger { color: var(--danger); border-color: var(--danger); }
        
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; }
        .modal-overlay.show { display: flex; }
        .modal { background: var(--surface); border-radius: 8px; max-width: 600px; width: 90%; max-height: 80vh; display: flex; flex-direction: column; }
        .modal-header { padding: 10px 14px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; font-size: 13px; }
        .modal-body { padding: 10px 14px; overflow-y: auto; flex: 1; }
        .modal-footer { padding: 10px 14px; border-top: 1px solid var(--border); display: flex; gap: 6px; justify-content: flex-end; }
        .hq-select-item { display: flex; align-items: center; gap: 8px; padding: 3px 0; border-bottom: 1px solid var(--border); font-size: 11px; cursor: pointer; }
        .hq-select-item input { margin: 0; }
        .hq-select-item .info { flex: 1; }
        .hq-select-item .colecao-atual { font-size: 10px; color: var(--text-secondary); }
        .search-input { width: 100%; padding: 5px 8px; font-size: 12px; border: 1px solid var(--border); border-radius: 4px; margin-bottom: 6px; background: var(--bg); color: var(--text); }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <main class="main-content">
        <div class="page-header">
            <h2><?= $modo === 'editar' ? 'Editar Coleção' : 'Nova Coleção' ?></h2>
            <a href="colecoes.php" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>

        <?php if ($msg): ?><div class="alert alert-success"><?= $msg ?></div><?php endif; ?>
        <?php if ($erro): ?><div class="alert alert-error"><?= $erro ?></div><?php endif; ?>

        <div class="form-section">
            <form method="POST">
                <input type="hidden" name="acao" value="salvar">
                <div class="form-linha">
                    <div class="form-group" style="flex:2">
                        <label>Nome *</label>
                        <input type="text" name="nome" value="<?= htmlspecialchars($colecao['nome'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Tipo</label>
                        <select name="tipo">
                            <option value="colecao" <?= ($colecao['tipo']??'')=='colecao'?'selected':'' ?>>Coleção</option>
                            <option value="miniserie" <?= ($colecao['tipo']??'')=='miniserie'?'selected':'' ?>>Minissérie</option>
                            <option value="temporada" <?= ($colecao['tipo']??'')=='temporada'?'selected':'' ?>>Temporada</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="aberta" <?= ($colecao['status']??'')=='aberta'?'selected':'' ?>>Aberta</option>
                            <option value="concluida" <?= ($colecao['status']??'')=='concluida'?'selected':'' ?>>Concluída</option>
                            <option value="incompleta" <?= ($colecao['status']??'')=='incompleta'?'selected':'' ?>>Incompleta</option>
                            <option value="cancelada" <?= ($colecao['status']??'')=='cancelada'?'selected':'' ?>>Cancelada</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Temporada</label>
                        <input type="number" name="temporada" value="<?= htmlspecialchars($colecao['temporada'] ?? '') ?>" style="width:70px">
                    </div>
                    <div class="form-group">
                        <label>Total previsto</label>
                        <input type="number" name="total_previsto" value="<?= htmlspecialchars($colecao['total_previsto'] ?? '') ?>" style="width:80px">
                    </div>
                    <div class="form-group">
                        <label>Editora</label>
                        <select name="editora_id">
                            <option value="">- Nenhuma -</option>
                            <?php foreach ($editoras as $e): ?>
                                <option value="<?= $e['id'] ?>" <?= ($colecao['editora_id']??'')==$e['id']?'selected':'' ?>><?= htmlspecialchars($e['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex:2">
                        <label>Observação</label>
                        <input type="text" name="observacao" value="<?= htmlspecialchars($colecao['observacao'] ?? '') ?>">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Salvar</button>
                </div>
            </form>
        </div>

        <?php if ($id): ?>
        <div class="form-section">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <h3 style="margin:0;">Edições na Coleção (<?= count($hqsVinculadas) ?>)</h3>
                <div style="display:flex; gap:6px;">
                    <button class="btn btn-primary btn-sm" onclick="document.getElementById('modal-add').classList.add('show')"><i class="fas fa-plus"></i> Adicionar HQs</button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="salvarOrdem()"><i class="fas fa-sort-numeric-up"></i> Salvar Ordem</button>
                </div>
            </div>
            
            <?php if (empty($hqsVinculadas)): ?>
                <p style="color:var(--text-secondary); font-size:12px; margin:0;">Nenhuma HQ vinculada ainda.</p>
            <?php else: ?>
                <form method="POST" id="form-ordem">
                    <input type="hidden" name="acao" value="salvar_ordem">
                    <input type="hidden" name="ordens" id="ordens-json">
                </form>
                <div class="hq-list">
                    <?php foreach ($hqsVinculadas as $hq): ?>
                        <div class="hq-item">
                            <?php if ($hq['imagem_capa']): ?><img src="<?= htmlspecialchars($hq['imagem_capa']) ?>"><?php else: ?><div style="width:28px; height:38px; background:var(--bg); border-radius:3px;"></div><?php endif; ?>
                            <input type="number" class="ordem-input ordem-field" data-hq-id="<?= $hq['id'] ?>" value="<?= $hq['ordem'] ?? '' ?>" placeholder="#">
                            <div class="titulo">
                                <strong><?= htmlspecialchars($hq['titulo']) ?></strong>
                                <div style="font-size:10px; color:var(--text-secondary);"><?= htmlspecialchars($hq['editora_nome']) ?> · <?= htmlspecialchars($hq['mes_ano_publicacao']) ?></div>
                            </div>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Remover esta HQ da coleção?')">
                                <input type="hidden" name="acao" value="remover_hq">
                                <input type="hidden" name="hq_id" value="<?= $hq['id'] ?>">
                                <button type="submit" class="btn-mini danger"><i class="fas fa-unlink"></i></button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </main>

    <div class="modal-overlay" id="modal-add">
        <div class="modal">
            <div class="modal-header">
                <strong>Adicionar HQs à Coleção</strong>
                <button class="btn-icon" onclick="document.getElementById('modal-add').classList.remove('show')">✕</button>
            </div>
            <div class="modal-body">
                <input type="text" class="search-input" id="search-hq" placeholder="Filtrar por título..." oninput="filtrarHQs()">
                <div id="lista-hqs">
                    <?php foreach ($hqsDisponiveis as $hq): ?>
                        <label class="hq-select-item" data-titulo="<?= htmlspecialchars(mb_strtolower($hq['titulo'])) ?>">
                            <input type="checkbox" class="hq-check" value="<?= $hq['id'] ?>">
                            <div class="info">
                                <strong><?= htmlspecialchars($hq['titulo']) ?></strong>
                                <?php if ($hq['colecao_nome']): ?>
                                    <div class="colecao-atual">Já em: <?= htmlspecialchars($hq['colecao_nome']) ?></div>
                                <?php endif; ?>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline btn-sm" onclick="document.getElementById('modal-add').classList.remove('show')">Cancelar</button>
                <button class="btn btn-primary btn-sm" onclick="adicionarHQs()"><i class="fas fa-check"></i> Adicionar</button>
            </div>
        </div>
    </div>

    <script>
        function filtrarHQs() {
            var q = document.getElementById('search-hq').value.toLowerCase();
            document.querySelectorAll('.hq-select-item').forEach(function(el) {
                el.style.display = el.dataset.titulo.indexOf(q) > -1 ? 'flex' : 'none';
            });
        }
        
        function adicionarHQs() {
            var ids = [];
            document.querySelectorAll('.hq-check:checked').forEach(function(c) { ids.push(c.value); });
            if (ids.length === 0) { alert('Selecione pelo menos 1 HQ'); return; }
            var f = document.createElement('form');
            f.method = 'POST';
            f.innerHTML = '<input type="hidden" name="acao" value="adicionar_hqs"><input type="hidden" name="hq_ids" value=\'' + JSON.stringify(ids) + '\'>';
            document.body.appendChild(f);
            f.submit();
        }
        
        function salvarOrdem() {
            var ordens = {};
            document.querySelectorAll('.ordem-field').forEach(function(inp) {
                ordens[inp.dataset.hqId] = inp.value;
            });
            document.getElementById('ordens-json').value = JSON.stringify(ordens);
            document.getElementById('form-ordem').submit();
        }
    </script>
</body>
</html>