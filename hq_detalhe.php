<?php
// hq_detalhe.php
require_once 'config.php';
require_once 'auth.php';
requireLogin();

$id = intval($_GET['id'] ?? 0);
$db = getDB();

$stmt = $db->prepare("SELECT h.*, e.nome as editora_nome, f.nome as formato_nome, c.nome as colecao_nome 
                      FROM hqs h 
                      LEFT JOIN editoras e ON h.editora_id=e.id 
                      LEFT JOIN formatos f ON h.formato_id=f.id 
                      LEFT JOIN colecoes c ON h.colecao_id=c.id
                      WHERE h.id=?");
$stmt->execute([$id]);
$hq = $stmt->fetch();

if (!$hq) { header('Location: vitrine.php'); exit; }

$isAdmin = isAdmin();

	// Detectar de onde o usuário veio para montar o link "Voltar"
	$referer = $_SERVER['HTTP_REFERER'] ?? '';
	$linkVoltar = 'hqs_lista.php'; // padrão

	if (strpos($referer, 'hqs_editar.php') !== false) {
	    // Veio da edição - volta para a edição
	    $linkVoltar = 'hqs_editar.php?id=' . $id;
	} elseif (strpos($referer, 'hqs_lista.php') !== false) {
	    $linkVoltar = 'hqs_lista.php';
	} elseif (strpos($referer, 'vitrine.php') !== false) {
	    $linkVoltar = 'vitrine.php';
	} elseif (strpos($referer, 'colecao_detalhe.php') !== false) {
	    $linkVoltar = $referer; // volta para a coleção
	} elseif (strpos($referer, 'colecoes.php') !== false) {
	    $linkVoltar = 'colecoes.php';
	} elseif ($isAdmin) {
	    $linkVoltar = 'hqs_lista.php';
	} else {
	    $linkVoltar = 'vitrine.php';
	}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($hq['titulo']) ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 8px; }
        .page-header h2 { font-size: 18px; font-weight: 600; margin: 0; }
        .detail-grid { display: grid; grid-template-columns: 250px 1fr; gap: 25px; }
        .detail-image img { width: 100%; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.15); }
        .detail-info p { margin-bottom: 10px; font-size: 14px; line-height: 1.5; }
        .detail-info a { color: var(--primary); word-break: break-all; }
        .badge-especial { padding: 2px 8px; border-radius: 10px; font-size: 11px; background: #fce7f3; color: #9d174d; font-weight: 500; display: inline-block; }
        [data-theme="dark"] .badge-especial { background: #3b1235; color: #f9a8d4; }
        .badge-colecao { padding: 2px 8px; border-radius: 10px; font-size: 11px; background: #e0f2fe; color: #0369a1; font-weight: 500; display: inline-block; }
        [data-theme="dark"] .badge-colecao { background: #1e3a5f; color: #7dd3fc; }
        .capa-vazia { width: 100%; height: 350px; background: var(--bg); display: flex; align-items: center; justify-content: center; border-radius: 8px; color: var(--text-secondary); }
        @media (max-width: 600px) {
            .detail-grid { grid-template-columns: 1fr; }
            .detail-image { max-width: 200px; margin: 0 auto; }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <main class="main-content">
        <div class="page-header">
            <h2><?= htmlspecialchars($hq['titulo']) ?></h2>
            <div style="display:flex; gap:6px;">
                <?php if ($isAdmin): ?>
                    <a href="hqs_editar.php?id=<?= $hq['id'] ?>" class="btn btn-primary btn-sm"><i class="fas fa-edit"></i> Editar</a>
                <?php endif; ?>
                <a href="<?= $linkVoltar ?>" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
            </div>
        </div>
        
        <div class="detail-grid">
            <div class="detail-image">
                <?php if ($hq['imagem_capa']): ?>
                    <img src="<?= htmlspecialchars($hq['imagem_capa']) ?>" alt="<?= htmlspecialchars($hq['titulo']) ?>">
                <?php else: ?>
                    <div class="capa-vazia"><i class="fas fa-book-open" style="font-size:64px;"></i></div>
                <?php endif; ?>
            </div>
            <div class="detail-info">
                <?php if ($hq['especial']): ?>
                    <p><span class="badge-especial"><i class="fas fa-star"></i> Edição Especial</span></p>
                <?php endif; ?>
                <?php if ($hq['colecao_nome']): ?>
                    <p><span class="badge-colecao"><i class="fas fa-layer-group"></i> <?= htmlspecialchars($hq['colecao_nome']) ?></span></p>
                <?php endif; ?>
                <p><strong>Editora:</strong> <?= htmlspecialchars($hq['editora_nome'] ?? '-') ?></p>
                <p><strong>Formato:</strong> <?= htmlspecialchars($hq['formato_nome'] ?? '-') ?></p>
                <p><strong>Edição:</strong> <?= htmlspecialchars($hq['numero_edicao'] ?: '-') ?></p>
                <p><strong>Preço:</strong> R$ <?= number_format($hq['preco_compra'], 2, ',', '.') ?></p>
                <p><strong>Publicação:</strong> <?= htmlspecialchars($hq['mes_ano_publicacao'] ?: '-') ?></p>
                <p><strong>Jornaleiro:</strong> <?= htmlspecialchars($hq['jornaleiro']) ?></p>
                <p><strong>Prateleira:</strong> <?= htmlspecialchars($hq['local_prateleira'] ?: '-') ?></p>
                <?php if ($hq['link_origem']): ?>
                    <p><strong>Link:</strong> <a href="<?= htmlspecialchars($hq['link_origem']) ?>" target="_blank"><?= htmlspecialchars($hq['link_origem']) ?></a></p>
                <?php endif; ?>
                <?php if ($hq['observacao']): ?>
                    <p><strong>Observação:</strong><br><?= nl2br(htmlspecialchars($hq['observacao'])) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>