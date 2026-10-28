<?php
// vitrine.php
require_once 'config.php';
require_once 'auth.php';
requireLogin();

$db = getDB();

// ============================================================
// FILTROS
// ============================================================
$busca = $_GET['busca'] ?? '';
$editora_id = $_GET['editora_id'] ?? '';
$formato_id = $_GET['formato_id'] ?? '';
$ordenar = $_GET['ordenar'] ?? 'titulo_asc';
$jornaleiro = $_GET['jornaleiro'] ?? '';
$pagina = max(1, intval($_GET['pagina'] ?? 1));
$porPagina = intval($_GET['por_pagina'] ?? 50);
if (!in_array($porPagina, [50,100,200,500,1000])) $porPagina = 50;
$modo = $_GET['modo'] ?? 'colecoes';
$view = $_GET['view'] ?? 'grid';
$ajax = isset($_GET['ajax']);

// ============================================================
// RESUMO
// ============================================================
$total = $db->query("SELECT COUNT(*) FROM hqs WHERE status='ativo'")->fetchColumn();
$porFormato = $db->query("SELECT f.nome, COUNT(h.id) as qtd FROM hqs h JOIN formatos f ON h.formato_id=f.id WHERE h.status='ativo' GROUP BY f.nome ORDER BY qtd DESC")->fetchAll();
$porSituacao = $db->query("SELECT jornaleiro, COUNT(*) as qtd FROM hqs WHERE status='ativo' GROUP BY jornaleiro")->fetchAll();
$totalColecoes = $db->query("SELECT COUNT(*) FROM colecoes")->fetchColumn();

// Helper para badges
function badgeJornaleiro($jornaleiro) {
    return match($jornaleiro) {
        'Adquirido' => '<span class="badge badge-tenho"><i class="fas fa-check-circle"></i> Tenho</span>',
        'Na Banca' => '<span class="badge badge-banca"><i class="fas fa-store"></i> Banca</span>',
        default => '<span class="badge badge-anuncio"><i class="fas fa-bullhorn"></i> Anúncio</span>'
    };
}
function tarjaJornaleiro($jornaleiro) {
    return match($jornaleiro) {
        'Adquirido' => 'tarja-verde',
        'Na Banca' => 'tarja-cinza',
        default => 'tarja-vermelha'
    };
}
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

// ============================================================
// BUSCAR DADOS
// ============================================================
$itens = [];

if ($modo === 'edicoes') {
    $query = "SELECT h.*, e.nome as editora_nome, f.nome as formato_nome, c.nome as colecao_nome 
              FROM hqs h 
              LEFT JOIN editoras e ON h.editora_id=e.id 
              LEFT JOIN formatos f ON h.formato_id=f.id 
              LEFT JOIN colecoes c ON h.colecao_id=c.id
              WHERE h.status='ativo'";
    $params = [];
    
    if ($busca) {
        $clauses = [];
        foreach (['h.titulo','h.numero_edicao','e.nome','f.nome','CAST(h.preco_compra AS TEXT)','h.mes_ano_publicacao','h.local_prateleira','h.jornaleiro'] as $c) {
            $clauses[] = "$c LIKE ?"; $params[] = "%$busca%";
        }
        $query .= " AND (" . implode(" OR ", $clauses) . ")";
    }
    if ($editora_id) { $query .= " AND h.editora_id = ?"; $params[] = $editora_id; }
    if ($formato_id) { $query .= " AND h.formato_id = ?"; $params[] = $formato_id; }
    if ($jornaleiro) { $query .= " AND h.jornaleiro = ?"; $params[] = $jornaleiro; }
    
    switch ($ordenar) {
        case 'titulo_desc': $order = 'h.titulo DESC'; break;
        case 'data_asc': $order = "CASE WHEN h.data_publicacao IS NULL THEN 1 ELSE 0 END, h.data_publicacao ASC"; break;
        case 'data_desc': $order = "CASE WHEN h.data_publicacao IS NULL THEN 1 ELSE 0 END, h.data_publicacao DESC"; break;
        case 'preco_asc': $order = 'h.preco_compra ASC'; break;
        case 'preco_desc': $order = 'h.preco_compra DESC'; break;
        default: $order = 'h.titulo ASC';
    }
    $query .= " ORDER BY $order";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $hqs = $stmt->fetchAll();
    
    foreach ($hqs as $hq) {
        $itens[] = ['tipo' => 'hq', 'dados' => $hq];
    }
} else {
    $queryCol = "SELECT c.*, e.nome as editora_nome,
        (SELECT COUNT(*) FROM hqs h WHERE h.colecao_id = c.id AND h.status='ativo') as qtd_hqs,
        (SELECT COUNT(*) FROM hqs h WHERE h.colecao_id = c.id AND h.status='ativo' AND h.jornaleiro='Adquirido') as qtd_adquiridas,
        (SELECT COUNT(*) FROM hqs h WHERE h.colecao_id = c.id AND h.status='ativo' AND h.jornaleiro='Na Banca') as qtd_banca,
        (SELECT h.imagem_capa FROM hqs h WHERE h.colecao_id = c.id AND h.status='ativo' AND h.imagem_capa != '' ORDER BY h.ordem ASC LIMIT 1) as capa_img
        FROM colecoes c
        LEFT JOIN editoras e ON c.editora_id = e.id
        WHERE 1=1";
    $paramsCol = [];
    
    if ($busca) { $queryCol .= " AND c.nome LIKE ?"; $paramsCol[] = "%$busca%"; }
    if ($editora_id) { $queryCol .= " AND c.editora_id = ?"; $paramsCol[] = $editora_id; }
    if ($formato_id) {
        $queryCol .= " AND c.id IN (SELECT DISTINCT colecao_id FROM hqs WHERE formato_id = ? AND colecao_id IS NOT NULL AND status='ativo')";
        $paramsCol[] = $formato_id;
    }
    if ($jornaleiro) {
        $queryCol .= " AND c.id IN (SELECT DISTINCT colecao_id FROM hqs WHERE jornaleiro = ? AND colecao_id IS NOT NULL AND status='ativo')";
        $paramsCol[] = $jornaleiro;
    }
    
    $stmtCol = $db->prepare($queryCol);
    $stmtCol->execute($paramsCol);
    $colecoes = $stmtCol->fetchAll();
    
    foreach ($colecoes as $c) {
        $itens[] = ['tipo' => 'colecao', 'dados' => $c];
    }
    
    $queryHq = "SELECT h.*, e.nome as editora_nome, f.nome as formato_nome 
                FROM hqs h 
                LEFT JOIN editoras e ON h.editora_id=e.id 
                LEFT JOIN formatos f ON h.formato_id=f.id 
                WHERE h.status='ativo' AND h.colecao_id IS NULL";
    $paramsHq = [];
    
    if ($busca) {
        $clauses = [];
        foreach (['h.titulo','h.numero_edicao','e.nome','f.nome','CAST(h.preco_compra AS TEXT)','h.mes_ano_publicacao','h.local_prateleira','h.jornaleiro'] as $c) {
            $clauses[] = "$c LIKE ?"; $paramsHq[] = "%$busca%";
        }
        $queryHq .= " AND (" . implode(" OR ", $clauses) . ")";
    }
    if ($editora_id) { $queryHq .= " AND h.editora_id = ?"; $paramsHq[] = $editora_id; }
    if ($formato_id) { $queryHq .= " AND h.formato_id = ?"; $paramsHq[] = $formato_id; }
    if ($jornaleiro) { $queryHq .= " AND h.jornaleiro = ?"; $paramsHq[] = $jornaleiro; }
    
    $queryHq .= " ORDER BY h.titulo ASC";
    $stmtHq = $db->prepare($queryHq);
    $stmtHq->execute($paramsHq);
    $hqsIndividuais = $stmtHq->fetchAll();
    
    foreach ($hqsIndividuais as $hq) {
        $itens[] = ['tipo' => 'hq', 'dados' => $hq];
    }
    
    usort($itens, function($a, $b) use ($ordenar) {
        $nomeA = $a['tipo'] === 'colecao' ? $a['dados']['nome'] : $a['dados']['titulo'];
        $nomeB = $b['tipo'] === 'colecao' ? $b['dados']['nome'] : $b['dados']['titulo'];
        
        if ($ordenar === 'titulo_desc') return strcasecmp($nomeB, $nomeA);
        if ($ordenar === 'preco_asc') return floatval($a['dados']['preco_compra'] ?? 0) <=> floatval($b['dados']['preco_compra'] ?? 0);
        if ($ordenar === 'preco_desc') return floatval($b['dados']['preco_compra'] ?? 0) <=> floatval($a['dados']['preco_compra'] ?? 0);
        if ($ordenar === 'data_asc' || $ordenar === 'data_desc') {
            $dataA = $a['dados']['data_publicacao'] ?? '';
            $dataB = $b['dados']['data_publicacao'] ?? '';
            return $ordenar === 'data_desc' ? strcmp($dataB, $dataA) : strcmp($dataA, $dataB);
        }
        return strcasecmp($nomeA, $nomeB);
    });
}

// ============================================================
// PAGINAÇÃO
// ============================================================
$totalRegistros = count($itens);
$totalPaginas = $totalRegistros > 0 ? ceil($totalRegistros / $porPagina) : 1;
if ($pagina > $totalPaginas) $pagina = $totalPaginas;
$offset = ($pagina - 1) * $porPagina;
$itensPagina = array_slice($itens, $offset, $porPagina);

// ============================================================
// AJAX
// ============================================================
if ($ajax) {
    if ($view === 'grid') {
        foreach ($itensPagina as $item) {
            if ($item['tipo'] === 'colecao') {
                $c = $item['dados'];
                $tarja = tarjaStatus($c['status']);
                $labelTipo = labelTipo($c['tipo']);
                $progresso = $c['total_previsto'] > 0 ? min(100, ($c['qtd_adquiridas'] / $c['total_previsto']) * 100) : ($c['qtd_hqs'] > 0 ? ($c['qtd_adquiridas'] / $c['qtd_hqs']) * 100 : 0);
                ?>
                <a href="colecao_detalhe.php?id=<?= $c['id'] ?>" class="hq-card <?= $tarja ?>">
                    <div class="hq-card-image">
                        <?php if ($c['capa_img']): ?><img src="<?= htmlspecialchars($c['capa_img']) ?>" alt=""><?php else: ?><i class="fas fa-layer-group" style="font-size:36px; color:var(--text-secondary);"></i><?php endif; ?>
                        <span class="badge-colecao-grande"><i class="fas fa-layer-group"></i> <?= $labelTipo ?></span>
                        <div class="hq-card-overlay">Ver coleção</div>
                    </div>
                    <div class="hq-card-info">
                        <h3><?= htmlspecialchars($c['nome']) ?></h3>
                        <div class="hq-card-details">
                            <span><?= $c['qtd_hqs'] ?><?= $c['total_previsto'] ? '/'.$c['total_previsto'] : '' ?> edições</span>
                            <span style="color: var(--success); font-weight:600;"><?= $c['qtd_adquiridas'] ?> adq.</span>
                            <?php if ($c['qtd_banca'] > 0): ?><span style="color:#a34d00;"><?= $c['qtd_banca'] ?> banca</span><?php endif; ?>
                        </div>
                        <div class="progresso-bar-mini"><div class="progresso-fill-mini" style="width:<?= $progresso ?>%"></div></div>
                    </div>
                </a>
                <?php
            } else {
                $hq = $item['dados'];
                $tarja = tarjaJornaleiro($hq['jornaleiro']);
                ?>
                <a href="hq_detalhe.php?id=<?= $hq['id'] ?>" class="hq-card <?= $tarja ?>">
                    <div class="hq-card-image">
                        <?php if ($hq['imagem_capa']): ?><img src="<?= htmlspecialchars($hq['imagem_capa']) ?>" alt=""><?php else: ?><i class="fas fa-book-open" style="font-size:36px; color:var(--text-secondary);"></i><?php endif; ?>
                        <?php if ($hq['especial']): ?><span class="badge-especial-grande"><i class="fas fa-star"></i> Especial</span><?php endif; ?>
                        <div class="hq-card-overlay">Ver detalhes</div>
                    </div>
                    <div class="hq-card-info">
                        <h3><?= htmlspecialchars($hq['titulo']) ?></h3>
                        <div class="hq-card-details">
                            <?php if ($hq['numero_edicao']): ?><span>Edição: <?= htmlspecialchars($hq['numero_edicao']) ?></span><?php endif; ?>
                            <?php if ($hq['mes_ano_publicacao']): ?><span><?= htmlspecialchars($hq['mes_ano_publicacao']) ?></span><?php endif; ?>
                            <span class="hq-price">R$ <?= number_format($hq['preco_compra'], 2, ',', '.') ?></span>
                            <?= badgeJornaleiro($hq['jornaleiro']) ?>
                        </div>
                    </div>
                </a>
                <?php
            }
        }
    } else {
        ?>
        <table class="data-table">
            <thead><tr><th>Capa</th><th>Título</th><th>Tipo</th><th>Editora</th><th>Publicação</th><th>Preço</th><th>Situação</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($itensPagina as $item): 
                    if ($item['tipo'] === 'colecao') {
                        $c = $item['dados'];
                        $tarja = tarjaStatus($c['status']);
                        $labelTipo = labelTipo($c['tipo']);
                ?>
                    <tr class="<?= $tarja ?>">
                        <td><?php if ($c['capa_img']): ?><img src="<?= htmlspecialchars($c['capa_img']) ?>" width="30"><?php else: ?><i class="fas fa-layer-group"></i><?php endif; ?></td>
                        <td><a href="colecao_detalhe.php?id=<?= $c['id'] ?>" style="color:var(--text); text-decoration:none; font-weight:600;"><?= htmlspecialchars($c['nome']) ?></a></td>
                        <td><span class="badge-tipo <?= $c['tipo'] ?>"><?= $labelTipo ?></span></td>
                        <td><?= htmlspecialchars($c['editora_nome'] ?? '-') ?></td>
                        <td>-</td>
                        <td>-</td>
                        <td><?= $c['qtd_hqs'] ?><?= $c['total_previsto'] ? '/'.$c['total_previsto'] : '' ?> ed. (<?= $c['qtd_adquiridas'] ?> adq.)</td>
                        <td><a href="colecao_detalhe.php?id=<?= $c['id'] ?>" class="btn-icon"><i class="fas fa-eye"></i></a></td>
                    </tr>
                <?php } else { 
                        $hq = $item['dados'];
                        $tarja = tarjaJornaleiro($hq['jornaleiro']);
                ?>
                    <tr class="<?= $tarja ?>">
                        <td><?php if ($hq['imagem_capa']): ?><img src="<?= htmlspecialchars($hq['imagem_capa']) ?>" width="30"><?php else: ?><i class="fas fa-book-open"></i><?php endif; ?></td>
                        <td><a href="hq_detalhe.php?id=<?= $hq['id'] ?>" style="color:var(--text); text-decoration:none;"><?= htmlspecialchars($hq['titulo']) ?></a><?= $hq['especial'] ? ' <span class="badge-especial-mini">Especial</span>' : '' ?></td>
                        <td><?= $hq['especial'] ? 'Especial' : 'Avulsa' ?></td>
                        <td><?= htmlspecialchars($hq['editora_nome']) ?></td>
                        <td><?= htmlspecialchars($hq['mes_ano_publicacao']) ?></td>
                        <td>R$ <?= number_format($hq['preco_compra'], 2, ',', '.') ?></td>
                        <td><?= badgeJornaleiro($hq['jornaleiro']) ?></td>
                        <td><a href="hq_detalhe.php?id=<?= $hq['id'] ?>" class="btn-icon"><i class="fas fa-eye"></i></a></td>
                    </tr>
                <?php } endforeach; ?>
            </tbody>
        </table>
        <?php
    }
    
    echo '<!--PAGINACAO-->';
    echo '<span>' . $totalRegistros . ' de ' . $totalPaginas . ' pág.</span>';
    echo '<select id="por_pagina">';
    foreach ([50,100,200,500,1000] as $v) echo '<option value="'.$v.'" '.($porPagina==$v?'selected':'').'>'.$v.'</option>';
    echo '</select>';
    if ($pagina > 1) echo '<a href="#" data-pagina="'.($pagina-1).'">&#8249;</a>';
    for ($p = max(1, $pagina-2); $p <= min($totalPaginas, $pagina+2); $p++) echo '<a href="#" data-pagina="'.$p.'" class="'.($p==$pagina?'active':'').'">'.$p.'</a>';
    if ($pagina < $totalPaginas) echo '<a href="#" data-pagina="'.($pagina+1).'">&#8250;</a>';
    exit;
}

$editoras = $db->query("SELECT * FROM editoras ORDER BY nome")->fetchAll();
$formatos = $db->query("SELECT * FROM formatos ORDER BY nome")->fetchAll();

$qs = function($overrides = []) use ($busca, $editora_id, $formato_id, $jornaleiro, $ordenar, $pagina, $porPagina, $modo, $view) {
    $p = ['busca'=>$busca, 'editora_id'=>$editora_id, 'formato_id'=>$formato_id, 'jornaleiro'=>$jornaleiro, 'ordenar'=>$ordenar, 'pagina'=>$pagina, 'por_pagina'=>$porPagina, 'modo'=>$modo, 'view'=>$view];
    foreach ($overrides as $k => $v) $p[$k] = $v;
    return http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null));
};
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catálogo de HQs</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .painel-controle { position: sticky; top: 52px; background: var(--bg); z-index: 1000; padding: 8px 0 4px 0; border-bottom: 1px solid var(--border); }
        .painel-topo { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 4px; }
        .painel-topo h2 { font-size: 16px; font-weight: 600; margin: 0; white-space: nowrap; }
        .resumo-inline { font-size: 12px; display: flex; align-items: center; gap: 6px; flex: 1; min-width: 0; }
        .btn-expandir { background: none; border: none; cursor: pointer; color: var(--primary); font-size: 11px; white-space: nowrap; }
        .resumo-detalhes { font-size: 11px; display: none; gap: 12px; padding: 2px 0; }
        .resumo-detalhes.show { display: flex; flex-wrap: wrap; }
        .filter-bar { display: flex; gap: 4px; flex-wrap: wrap; align-items: center; }
        .filter-bar input, .filter-bar select { padding: 4px 6px; font-size: 11px; border: 1.5px solid var(--border); border-radius: 4px; background: var(--surface); color: var(--text); }
        .filter-bar input { width: 130px; }
        .filter-bar select { max-width: 130px; }
        .paginacao { display: flex; align-items: center; gap: 4px; font-size: 11px; margin-top: 2px; flex-wrap: wrap; }
        .paginacao select { padding: 2px 4px; font-size: 11px; border: 1.5px solid var(--border); border-radius: 4px; background: var(--surface); color: var(--text); }
        .paginacao a { padding: 2px 6px; border: 1.5px solid var(--border); border-radius: 4px; text-decoration: none; color: var(--text); background: var(--surface); font-size: 11px; }
        .paginacao a.active { background: var(--primary); color: white; border-color: var(--primary); }
        
        .view-toggle { display: flex; gap: 2px; }
        .view-toggle .btn-icon { padding: 4px 8px; font-size: 13px; }
        .view-toggle .btn-icon.active { background: var(--primary); color: white; border-color: var(--primary); }
        
        .modo-toggle { display: flex; gap: 2px; background: var(--surface); border-radius: 6px; padding: 2px; border: 1.5px solid var(--border); }
        .modo-toggle a { padding: 4px 10px; font-size: 11px; border-radius: 4px; text-decoration: none; color: var(--text-secondary); }
        .modo-toggle a.active { background: var(--primary); color: white; font-weight: 500; }
        
        .hq-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 14px; align-items: start; }
        
        .hq-card { 
            background: var(--surface); 
            border-radius: 8px; 
            overflow: hidden; 
            text-decoration: none; 
            color: inherit; 
            border: 1px solid var(--border); 
            transition: transform 0.2s, box-shadow 0.2s; 
            display: flex; 
            flex-direction: column;
            box-shadow: var(--shadow);
        }
        .hq-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); }
        
        .hq-card-image { 
            aspect-ratio: 2 / 3; 
            background: #1a1a1a; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            overflow: hidden; 
            position: relative; 
            flex-shrink: 0;
        }
        .hq-card-image img { 
            max-width: 100%; 
            max-height: 100%; 
            width: auto; 
            height: auto; 
            object-fit: contain; 
            transition: transform 0.3s; 
            display: block;
        }
        .hq-card:hover .hq-card-image img { transform: scale(1.03); }
        
        .hq-card-overlay { 
            position: absolute; 
            top: 0; left: 0; right: 0; bottom: 0; 
            background: rgba(0,0,0,0.6); 
            color: white; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            opacity: 0; 
            transition: opacity 0.2s; 
            font-size: 12px; 
        }
        .hq-card:hover .hq-card-overlay { opacity: 1; }
        
        .hq-card-info { padding: 8px 10px 10px 10px; display: flex; flex-direction: column; gap: 4px; }
        .hq-card-info h3 { font-size: 12px; font-weight: 600; margin: 0; line-height: 1.35; word-wrap: break-word; overflow-wrap: break-word; }
        .hq-card-details { display: flex; flex-wrap: wrap; gap: 4px; font-size: 10px; color: var(--text-secondary); line-height: 1.3; align-items: center; }
        .hq-price { color: var(--success); font-weight: 600; }
        
        .progresso-bar-mini { width: 100%; height: 4px; background: var(--bg); border-radius: 2px; overflow: hidden; margin-top: 2px; }
        .progresso-fill-mini { height: 100%; background: linear-gradient(90deg, #2a9d8f, #43aa8b); transition: width 0.3s; }
        
        .badge-especial-mini { padding: 1px 6px; border-radius: 8px; font-size: 9px; background: #fce7f3; color: #9d174d; font-weight: 600; }
        [data-theme="dark"] .badge-especial-mini { background: #3b1235; color: #f9a8d4; }
        
        .data-table { width: 100%; border-collapse: collapse; background: var(--surface); border-radius: 8px; overflow: hidden; border: 1px solid var(--border); box-shadow: var(--shadow); }
        .data-table th { background: var(--bg); padding: 8px 10px; text-align: left; font-size: 10px; text-transform: uppercase; color: var(--text-secondary); font-weight: 600; border-bottom: 1px solid var(--border); }
        .data-table td { padding: 6px 10px; border-bottom: 1px solid var(--border); font-size: 12px; }
        .data-table tbody tr:last-child td { border-bottom: none; }
        .data-table tbody tr:hover { background: var(--surface-hover); }
        .data-table img { border-radius: 3px; }
        
        @media (max-width: 768px) {
            .painel-controle { position: static; }
            .filter-bar input, .filter-bar select { width: 100%; max-width: none; }
            .hq-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <main class="main-content">
        <div class="painel-controle">
            <div class="painel-topo">
                <h2>Catálogo de HQs</h2>
                <div class="modo-toggle">
                    <a href="?<?= $qs(['modo' => 'colecoes', 'pagina' => 1]) ?>" class="<?= $modo=='colecoes'?'active':'' ?>"><i class="fas fa-layer-group"></i> Coleções</a>
                    <a href="?<?= $qs(['modo' => 'edicoes', 'pagina' => 1]) ?>" class="<?= $modo=='edicoes'?'active':'' ?>"><i class="fas fa-book"></i> Edições</a>
                </div>
                <div class="view-toggle">
                    <a href="?<?= $qs(['view' => 'grid']) ?>" class="btn-icon <?= $view=='grid'?'active':'' ?>"><i class="fas fa-th"></i></a>
                    <a href="?<?= $qs(['view' => 'list']) ?>" class="btn-icon <?= $view=='list'?'active':'' ?>"><i class="fas fa-list"></i></a>
                </div>
                <div class="resumo-inline">
                    <span><strong><?= $totalRegistros ?> <?= $modo=='colecoes'?'itens':'edições' ?></strong> &middot; <?= $totalColecoes ?> coleções &middot; <button class="btn-expandir" onclick="toggleResumo()">Detalhes &#9660;</button></span>
                </div>
            </div>
            <div class="resumo-detalhes" id="resumo-detalhes">
                <span><i class="fas fa-th"></i> <?php $fList = []; foreach ($porFormato as $i => $f) { if ($i < 3) $fList[] = htmlspecialchars($f['nome']) . ' (' . $f['qtd'] . ')'; } echo implode(' | ', $fList); if (count($porFormato) > 3) echo ' +' . (count($porFormato) - 3); ?></span>
                <span><i class="fas fa-tag"></i> <?php $sList = []; foreach ($porSituacao as $s) { $sList[] = $s['jornaleiro'] . ' (' . $s['qtd'] . ')'; } echo implode(' | ', $sList); ?></span>
            </div>
            
            <div class="filter-bar">
                <input type="text" id="busca" placeholder="Buscar..." value="<?= htmlspecialchars($busca) ?>">
                <select id="editora_id"><option value="">Editoras</option><?php foreach ($editoras as $e): ?><option value="<?= $e['id'] ?>" <?= $editora_id==$e['id']?'selected':'' ?>><?= htmlspecialchars($e['nome']) ?></option><?php endforeach; ?></select>
                <select id="formato_id"><option value="">Formatos</option><?php foreach ($formatos as $f): ?><option value="<?= $f['id'] ?>" <?= $formato_id==$f['id']?'selected':'' ?>><?= htmlspecialchars($f['nome']) ?></option><?php endforeach; ?></select>
                <select id="jornaleiro"><option value="">Situação</option><option value="Nao Informado" <?= $jornaleiro=='Nao Informado'?'selected':'' ?>>Não Informado</option><option value="Na Banca" <?= $jornaleiro=='Na Banca'?'selected':'' ?>>Na Banca</option><option value="Adquirido" <?= $jornaleiro=='Adquirido'?'selected':'' ?>>Adquirido</option></select>
                <select id="ordenar"><option value="titulo_asc" <?= $ordenar=='titulo_asc'?'selected':'' ?>>A-Z</option><option value="titulo_desc" <?= $ordenar=='titulo_desc'?'selected':'' ?>>Z-A</option><option value="data_desc" <?= $ordenar=='data_desc'?'selected':'' ?>>+Novo</option><option value="data_asc" <?= $ordenar=='data_asc'?'selected':'' ?>>+Antigo</option><option value="preco_asc" <?= $ordenar=='preco_asc'?'selected':'' ?>>+Barato</option><option value="preco_desc" <?= $ordenar=='preco_desc'?'selected':'' ?>>+Caro</option></select>
            </div>
            
            <div class="paginacao" id="paginacao-container">
                <span><?= $totalRegistros ?> de <?= $totalPaginas ?> pág.</span>
                <select id="por_pagina"><?php foreach ([50,100,200,500,1000] as $v): ?><option value="<?= $v ?>" <?= $porPagina==$v?'selected':'' ?>><?= $v ?></option><?php endforeach; ?></select>
                <?php if ($pagina > 1): ?><a href="#" data-pagina="<?= $pagina-1 ?>">&#8249;</a><?php endif; ?>
                <?php for ($p = max(1, $pagina-2); $p <= min($totalPaginas, $pagina+2); $p++): ?>
                    <a href="#" data-pagina="<?= $p ?>" class="<?= $p==$pagina?'active':'' ?>"><?= $p ?></a>
                <?php endfor; ?>
                <?php if ($pagina < $totalPaginas): ?><a href="#" data-pagina="<?= $pagina+1 ?>">&#8250;</a><?php endif; ?>
            </div>
        </div>

        <div id="lista-container">
            <?php if ($view === 'grid'): ?>
                <div class="hq-grid" id="view-content">
                    <?php foreach ($itensPagina as $item): 
                        if ($item['tipo'] === 'colecao') {
                            $c = $item['dados'];
                            $tarja = tarjaStatus($c['status']);
                            $labelTipo = labelTipo($c['tipo']);
                            $progresso = $c['total_previsto'] > 0 ? min(100, ($c['qtd_adquiridas'] / $c['total_previsto']) * 100) : ($c['qtd_hqs'] > 0 ? ($c['qtd_adquiridas'] / $c['qtd_hqs']) * 100 : 0);
                    ?>
                        <a href="colecao_detalhe.php?id=<?= $c['id'] ?>" class="hq-card <?= $tarja ?>">
                            <div class="hq-card-image">
                                <?php if ($c['capa_img']): ?><img src="<?= htmlspecialchars($c['capa_img']) ?>" alt=""><?php else: ?><i class="fas fa-layer-group" style="font-size:36px; color:var(--text-secondary);"></i><?php endif; ?>
                                <span class="badge-colecao-grande"><i class="fas fa-layer-group"></i> <?= $labelTipo ?></span>
                                <div class="hq-card-overlay">Ver coleção</div>
                            </div>
                            <div class="hq-card-info">
                                <h3><?= htmlspecialchars($c['nome']) ?></h3>
                                <div class="hq-card-details">
                                    <span><?= $c['qtd_hqs'] ?><?= $c['total_previsto'] ? '/'.$c['total_previsto'] : '' ?> edições</span>
                                    <span style="color: var(--success); font-weight:600;"><?= $c['qtd_adquiridas'] ?> adq.</span>
                                    <?php if ($c['qtd_banca'] > 0): ?><span style="color:#a34d00;"><?= $c['qtd_banca'] ?> banca</span><?php endif; ?>
                                </div>
                                <div class="progresso-bar-mini"><div class="progresso-fill-mini" style="width:<?= $progresso ?>%"></div></div>
                            </div>
                        </a>
                    <?php } else { 
                            $hq = $item['dados'];
                            $tarja = tarjaJornaleiro($hq['jornaleiro']);
                    ?>
                        <a href="hq_detalhe.php?id=<?= $hq['id'] ?>" class="hq-card <?= $tarja ?>">
                            <div class="hq-card-image">
                                <?php if ($hq['imagem_capa']): ?><img src="<?= htmlspecialchars($hq['imagem_capa']) ?>" alt=""><?php else: ?><i class="fas fa-book-open" style="font-size:36px; color:var(--text-secondary);"></i><?php endif; ?>
                                <?php if ($hq['especial']): ?><span class="badge-especial-grande"><i class="fas fa-star"></i> Especial</span><?php endif; ?>
                                <div class="hq-card-overlay">Ver detalhes</div>
                            </div>
                            <div class="hq-card-info">
                                <h3><?= htmlspecialchars($hq['titulo']) ?></h3>
                                <div class="hq-card-details">
                                    <?php if ($hq['numero_edicao']): ?><span>Edição: <?= htmlspecialchars($hq['numero_edicao']) ?></span><?php endif; ?>
                                    <?php if ($hq['mes_ano_publicacao']): ?><span><?= htmlspecialchars($hq['mes_ano_publicacao']) ?></span><?php endif; ?>
                                    <span class="hq-price">R$ <?= number_format($hq['preco_compra'], 2, ',', '.') ?></span>
                                    <?= badgeJornaleiro($hq['jornaleiro']) ?>
                                </div>
                            </div>
                        </a>
                    <?php } endforeach; ?>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead><tr><th>Capa</th><th>Título</th><th>Tipo</th><th>Editora</th><th>Publicação</th><th>Preço</th><th>Situação</th><th></th></tr></thead>
                    <tbody id="view-content">
                        <?php foreach ($itensPagina as $item): 
                            if ($item['tipo'] === 'colecao') {
                                $c = $item['dados'];
                                $tarja = tarjaStatus($c['status']);
                                $labelTipo = labelTipo($c['tipo']);
                        ?>
                            <tr class="<?= $tarja ?>">
                                <td><?php if ($c['capa_img']): ?><img src="<?= htmlspecialchars($c['capa_img']) ?>" width="30"><?php else: ?><i class="fas fa-layer-group"></i><?php endif; ?></td>
                                <td><a href="colecao_detalhe.php?id=<?= $c['id'] ?>" style="color:var(--text); text-decoration:none; font-weight:600;"><?= htmlspecialchars($c['nome']) ?></a></td>
                                <td><span class="badge-tipo <?= $c['tipo'] ?>"><?= $labelTipo ?></span></td>
                                <td><?= htmlspecialchars($c['editora_nome'] ?? '-') ?></td>
                                <td>-</td>
                                <td>-</td>
                                <td><?= $c['qtd_hqs'] ?><?= $c['total_previsto'] ? '/'.$c['total_previsto'] : '' ?> ed. (<?= $c['qtd_adquiridas'] ?> adq.)</td>
                                <td><a href="colecao_detalhe.php?id=<?= $c['id'] ?>" class="btn-icon"><i class="fas fa-eye"></i></a></td>
                            </tr>
                        <?php } else { 
                                $hq = $item['dados'];
                                $tarja = tarjaJornaleiro($hq['jornaleiro']);
                        ?>
                            <tr class="<?= $tarja ?>">
                                <td><?php if ($hq['imagem_capa']): ?><img src="<?= htmlspecialchars($hq['imagem_capa']) ?>" width="30"><?php else: ?><i class="fas fa-book-open"></i><?php endif; ?></td>
                                <td><a href="hq_detalhe.php?id=<?= $hq['id'] ?>" style="color:var(--text); text-decoration:none;"><?= htmlspecialchars($hq['titulo']) ?></a><?= $hq['especial'] ? ' <span class="badge-especial-mini">Especial</span>' : '' ?></td>
                                <td><?= $hq['especial'] ? 'Especial' : 'Avulsa' ?></td>
                                <td><?= htmlspecialchars($hq['editora_nome']) ?></td>
                                <td><?= htmlspecialchars($hq['mes_ano_publicacao']) ?></td>
                                <td>R$ <?= number_format($hq['preco_compra'], 2, ',', '.') ?></td>
                                <td><?= badgeJornaleiro($hq['jornaleiro']) ?></td>
                                <td><a href="hq_detalhe.php?id=<?= $hq['id'] ?>" class="btn-icon"><i class="fas fa-eye"></i></a></td>
                            </tr>
                        <?php } endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </main>

    <script>
        var currentPage = <?= $pagina ?>;
        var currentPorPagina = <?= $porPagina ?>;
        var currentModo = '<?= $modo ?>';
        var currentView = '<?= $view ?>';

        function toggleResumo() {
            var d = document.getElementById('resumo-detalhes');
            var btn = document.querySelector('.btn-expandir');
            if (d.classList.contains('show')) { d.classList.remove('show'); btn.innerHTML = 'Detalhes &#9660;'; }
            else { d.classList.add('show'); btn.innerHTML = 'Ocultar &#9650;'; }
        }

        function getFiltros() {
            return {
                busca: document.getElementById('busca').value,
                editora_id: document.getElementById('editora_id').value,
                formato_id: document.getElementById('formato_id').value,
                jornaleiro: document.getElementById('jornaleiro').value,
                ordenar: document.getElementById('ordenar').value,
                por_pagina: currentPorPagina,
                pagina: currentPage,
                modo: currentModo,
                view: currentView
            };
        }

        function atualizarTudo(resetPage) {
            if (resetPage !== false) currentPage = 1;
            var params = 'ajax=1&' + new URLSearchParams(getFiltros()).toString();
            fetch('vitrine.php?' + params)
                .then(function(r) { return r.text(); })
                .then(function(html) {
                    var partes = html.split('<!--PAGINACAO-->');
                    var conteudoHtml = partes[0];
                    var paginacaoHtml = partes[1] || '';
                    
                    var container = document.getElementById('view-content');
                    if (container) container.innerHTML = conteudoHtml.trim();
                    
                    document.getElementById('paginacao-container').innerHTML = paginacaoHtml.trim();
                    reatacharEventos();
                });
        }

        function reatacharEventos() {
            var sel = document.getElementById('por_pagina');
            if (sel) sel.onchange = function() { currentPorPagina = parseInt(this.value); atualizarTudo(); };
            document.querySelectorAll('#paginacao-container a[data-pagina]').forEach(function(a) {
                a.onclick = function(e) {
                    e.preventDefault();
                    currentPage = parseInt(this.dataset.pagina);
                    atualizarTudo(false);
                };
            });
        }

        document.getElementById('busca').oninput = function() { atualizarTudo(); };
        document.getElementById('editora_id').onchange = function() { atualizarTudo(); };
        document.getElementById('formato_id').onchange = function() { atualizarTudo(); };
        document.getElementById('jornaleiro').onchange = function() { atualizarTudo(); };
        document.getElementById('ordenar').onchange = function() { atualizarTudo(); };
        reatacharEventos();
    </script>
</body>
</html>