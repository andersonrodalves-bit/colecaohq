<?php
// colecao_detalhe.php
require_once 'config.php';
require_once 'auth.php';
requireLogin();

$db = getDB();
$id = intval($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT c.*, e.nome as editora_nome FROM colecoes c LEFT JOIN editoras e ON c.editora_id=e.id WHERE c.id = ?");
$stmt->execute([$id]);
$colecao = $stmt->fetch();

if (!$colecao) { header('Location: vitrine.php'); exit; }

// HQs da coleção
$stmt = $db->prepare("SELECT h.*, e.nome as editora_nome, f.nome as formato_nome 
    FROM hqs h 
    LEFT JOIN editoras e ON h.editora_id=e.id 
    LEFT JOIN formatos f ON h.formato_id=f.id 
    WHERE h.colecao_id = ? AND h.status='ativo' 
    ORDER BY h.ordem ASC, h.titulo ASC");
$stmt->execute([$id]);
$hqs = $stmt->fetchAll();

$qtdTotal = count($hqs);
$qtdAdquiridas = 0;
$qtdNaBanca = 0;
$qtdNaoInfo = 0;
$valorTotal = 0;

foreach ($hqs as $hq) {
    $valorTotal += floatval($hq['preco_compra']);
    if ($hq['jornaleiro'] === 'Adquirido') $qtdAdquiridas++;
    elseif ($hq['jornaleiro'] === 'Na Banca') $qtdNaBanca++;
    else $qtdNaoInfo++;
}

$isAdmin = isAdmin();

// Labels
$labelTipo = match($colecao['tipo']) {
    'miniserie' => 'Minissérie',
    'temporada' => 'Temporada',
    default => 'Coleção'
};
$labelStatus = match($colecao['status']) {
    'concluida' => 'Concluída',
    'incompleta' => 'Incompleta',
    'cancelada' => 'Cancelada',
    default => 'Aberta'
};
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($colecao['nome']) ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 8px; }
        .page-header h2 { font-size: 18px; font-weight: 600; margin: 0; }
        .col-info-bar { display: flex; gap: 16px; flex-wrap: wrap; padding: 10px 14px; background: var(--surface); border-radius: 8px; border: 1px solid var(--border); margin-bottom: 16px; font-size: 12px; }
        .col-info-item { display: flex; align-items: center; gap: 6px; }
        .badge-tipo { padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 500; }
        .badge-tipo.colecao { background: #e0f2fe; color: #0369a1; }
        .badge-tipo.miniserie { background: #ede9fe; color: #6d28d9; }
        .badge-tipo.temporada { background: #fef3c7; color: #92400e; }
        [data-theme="dark"] .badge-tipo.colecao { background: #1e3a5f; color: #7dd3fc; }
        [data-theme="dark"] .badge-tipo.miniserie { background: #2e1e5f; color: #c4b5fd; }
        [data-theme="dark"] .badge-tipo.temporada { background: #3b2e0a; color: #fcd34d; }
        
        .badge-status { padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 500; }
        .badge-status.aberta { background: #dbeafe; color: #1e40af; }
        .badge-status.concluida { background: #d4edda; color: #155724; }
        .badge-status.incompleta { background: #fff3cd; color: #856404; }
        .badge-status.cancelada { background: #f8d7da; color: #721c24; }
        [data-theme="dark"] .badge-status.aberta { background: #1e3a5f; color: #93c5fd; }
        [data-theme="dark"] .badge-status.concluida { background: #0a2e1a; color: #6ee7b7; }
        [data-theme="dark"] .badge-status.incompleta { background: #3b2e0a; color: #fcd34d; }
        [data-theme="dark"] .badge-status.cancelada { background: #3b1219; color: #fda4af; }
        
        .progresso-bar { width: 200px; height: 8px; background: var(--bg); border-radius: 4px; overflow: hidden; border: 1px solid var(--border); }
        .progresso-fill { height: 100%; background: linear-gradient(90deg, #2a9d8f, #43aa8b); transition: width 0.3s; }
        
        /* Grid de HQs da coleção */
        .hq-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; }
        .hq-card { background: var(--surface); border-radius: 8px; overflow: hidden; text-decoration: none; color: inherit; border: 1px solid var(--border); transition: transform 0.2s, box-shadow 0.2s; display: block; }
        .hq-card:hover { transform: translateY(-4px); box-shadow: 0 8px 20px rgba(0,0,0,0.1); }
        .hq-card.tarja-verde { border-left: 3px solid #2a9d8f; }
        .hq-card.tarja-cinza { border-left: 3px solid #6c757d; }
        .hq-card.tarja-vermelha { border-left: 3px solid #e63946; }
        .hq-card-image { height: 220px; background: var(--bg); display: flex; align-items: center; justify-content: center; overflow: hidden; position: relative; }
        .hq-card-image img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s; }
        .hq-card:hover .hq-card-image img { transform: scale(1.05); }
        .hq-card-overlay { position: absolute; top:0; left:0; right:0; bottom:0; background: rgba(0,0,0,0.6); color: white; display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.2s; font-size: 12px; }
        .hq-card:hover .hq-card-overlay { opacity: 1; }
        .hq-card-info { padding: 8px 10px; }
        .hq-card-info h3 { font-size: 12px; font-weight: 600; margin: 0 0 4px 0; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .hq-card-details { display: flex; flex-wrap: wrap; gap: 3px; font-size: 10px; color: var(--text-secondary); }
        .hq-price { color: var(--success); font-weight: 600; }
        .ordem-badge { position: absolute; top: 6px; left: 6px; background: rgba(0,0,0,0.7); color: white; padding: 2px 6px; border-radius: 8px; font-size: 10px; font-weight: 600; }
        
        .empty-state { text-align: center; padding: 40px; color: var(--text-secondary); font-size: 13px; }
        
        /* Tabela (modo lista) */
        .data-table { width: 100%; border-collapse: collapse; background: var(--surface); border-radius: 8px; overflow: hidden; border: 1px solid var(--border); }
        .data-table th { background: var(--bg); padding: 8px 10px; text-align: left; font-size: 10px; text-transform: uppercase; color: var(--text-secondary); }
        .data-table td { padding: 6px 10px; border-bottom: 1px solid var(--border); font-size: 12px; }
        .data-table img { border-radius: 3px; }
        
        .view-toggle { display: flex; gap: 2px; }
        .view-toggle .btn-icon { padding: 4px 8px; font-size: 13px; }
        .view-toggle .btn-icon.active { background: var(--primary); color: white; border-color: var(--primary); }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <main class="main-content">
        <div class="page-header">
            <h2><?= htmlspecialchars($colecao['nome']) ?></h2>
            <div style="display:flex; gap:6px;">
                <?php if ($isAdmin): ?>
                    <a href="colecao_editar.php?id=<?= $colecao['id'] ?>" class="btn btn-primary btn-sm"><i class="fas fa-edit"></i> Editar Coleção</a>
                <?php endif; ?>
                <a href="<?= $_SERVER['HTTP_REFERER'] ?? 'vitrine.php' ?>" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
            </div>
        </div>

        <div class="col-info-bar">
            <div class="col-info-item"><span class="badge-tipo <?= $colecao['tipo'] ?>"><?= $labelTipo ?></span></div>
            <div class="col-info-item"><span class="badge-status <?= $colecao['status'] ?>"><?= $labelStatus ?></span></div>
            <?php if ($colecao['temporada']): ?><div class="col-info-item"><strong>Temporada:</strong> <?= $colecao['temporada'] ?></div><?php endif; ?>
            <?php if ($colecao['editora_nome']): ?><div class="col-info-item"><strong>Editora:</strong> <?= htmlspecialchars($colecao['editora_nome']) ?></div><?php endif; ?>
            <div class="col-info-item">
                <strong>Progresso:</strong> <?= $qtdAdquiridas ?> / <?= $colecao['total_previsto'] ?: $qtdTotal ?>
                <div class="progresso-bar"><div class="progresso-fill" style="width:<?= ($colecao['total_previsto'] ? min(100, ($qtdAdquiridas / $colecao['total_previsto']) * 100) : ($qtdTotal > 0 ? ($qtdAdquiridas / $qtdTotal) * 100 : 0)) ?>%"></div></div>
            </div>
            <div class="col-info-item"><i class="fas fa-check-circle" style="color:#2a9d8f"></i> <?= $qtdAdquiridas ?> Adquiridas</div>
            <div class="col-info-item"><i class="fas fa-shopping-cart" style="color:#6c757d"></i> <?= $qtdNaBanca ?> Na Banca</div>
            <div class="col-info-item"><i class="fas fa-question-circle" style="color:#e63946"></i> <?= $qtdNaoInfo ?> Não Informado</div>
        </div>

        <?php if ($colecao['observacao']): ?>
            <div style="padding:10px 14px; background:var(--surface); border-radius:8px; border:1px solid var(--border); margin-bottom:16px; font-size:12px;">
                <strong>Observação:</strong> <?= nl2br(htmlspecialchars($colecao['observacao'])) ?>
            </div>
        <?php endif; ?>

        <?php if (empty($hqs)): ?>
            <div class="empty-state"><i class="fas fa-inbox" style="font-size:48px; margin-bottom:10px;"></i><p>Nenhuma edição vinculada a esta coleção.</p></div>
        <?php else: ?>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <h3 style="font-size:14px; margin:0;">Edições (<?= $qtdTotal ?>)</h3>
                <div class="view-toggle">
                    <a href="#" class="btn-icon active" data-mode="grid"><i class="fas fa-th"></i></a>
                    <a href="#" class="btn-icon" data-mode="list"><i class="fas fa-list"></i></a>
                </div>
            </div>

            <div id="view-grid" class="hq-grid">
                <?php foreach ($hqs as $hq): 
                    $tarja = match($hq['jornaleiro']) { 'Adquirido' => 'tarja-verde', 'Na Banca' => 'tarja-cinza', default => 'tarja-vermelha' };
                ?>
                    <a href="hq_detalhe.php?id=<?= $hq['id'] ?>" class="hq-card <?= $tarja ?>">
                        <div class="hq-card-image">
                            <?php if ($hq['imagem_capa']): ?><img src="<?= htmlspecialchars($hq['imagem_capa']) ?>" alt=""><?php else: ?><i class="fas fa-book-open" style="font-size:36px; color:var(--text-secondary);"></i><?php endif; ?>
                            <?php if ($hq['ordem']): ?><span class="ordem-badge">#<?= $hq['ordem'] ?></span><?php endif; ?>
                            <div class="hq-card-overlay">Ver detalhes</div>
                        </div>
                        <div class="hq-card-info">
                            <h3><?= htmlspecialchars($hq['titulo']) ?></h3>
                            <div class="hq-card-details">
                                <?php if ($hq['mes_ano_publicacao']): ?><span><?= htmlspecialchars($hq['mes_ano_publicacao']) ?></span><?php endif; ?>
                                <span class="hq-price">R$ <?= number_format($hq['preco_compra'], 2, ',', '.') ?></span>
                                <span><?= htmlspecialchars($hq['jornaleiro']) ?></span>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

            <div id="view-list" style="display:none;">
                <table class="data-table">
                    <thead><tr><th>Capa</th><th>#</th><th>Título</th><th>Editora</th><th>Formato</th><th>Publicação</th><th>Preço</th><th>Situação</th></tr></thead>
                    <tbody>
                        <?php foreach ($hqs as $hq): ?>
                            <tr>
                                <td><?php if ($hq['imagem_capa']): ?><img src="<?= htmlspecialchars($hq['imagem_capa']) ?>" width="30"><?php else: ?><i class="fas fa-book-open"></i><?php endif; ?></td>
                                <td><?= $hq['ordem'] ?: '-' ?></td>
                                <td><a href="hq_detalhe.php?id=<?= $hq['id'] ?>" style="color:var(--text); text-decoration:none;"><?= htmlspecialchars($hq['titulo']) ?></a></td>
                                <td><?= htmlspecialchars($hq['editora_nome']) ?></td>
                                <td><?= htmlspecialchars($hq['formato_nome']) ?></td>
                                <td><?= htmlspecialchars($hq['mes_ano_publicacao']) ?></td>
                                <td>R$ <?= number_format($hq['preco_compra'], 2, ',', '.') ?></td>
                                <td><?= htmlspecialchars($hq['jornaleiro']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>

    <script>
        document.querySelectorAll('.view-toggle .btn-icon').forEach(function(btn) {
            btn.onclick = function(e) {
                e.preventDefault();
                var mode = this.dataset.mode;
                document.querySelectorAll('.view-toggle .btn-icon').forEach(function(b) { b.classList.remove('active'); });
                this.classList.add('active');
                document.getElementById('view-grid').style.display = mode === 'grid' ? 'grid' : 'none';
                document.getElementById('view-list').style.display = mode === 'list' ? 'block' : 'none';
            };
        });
    </script>
</body>
</html>