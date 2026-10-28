<?php
// hqs_editar.php
require_once 'config.php';
require_once 'auth.php';
requireLogin();

$db = getDB();
$id = intval($_GET['id'] ?? 0);
$msg = '';
$erro = '';

$stmt = $db->prepare("SELECT * FROM hqs WHERE id = ?");
$stmt->execute([$id]);
$hq = $stmt->fetch();

if (!$hq) { header('Location: hqs_lista.php'); exit; }

$isJornaleiro = ($_SESSION['usuario']['tipo'] === 'jornaleiro');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isJornaleiro) {
        $db->prepare("UPDATE hqs SET jornaleiro=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
           ->execute([$_POST['jornaleiro'], $id]);
        $msg = 'Situação atualizada.';
    } else {
        $especial = isset($_POST['especial']) ? 1 : 0;
        $colecao_id = (!$especial && !empty($_POST['colecao_id'])) ? intval($_POST['colecao_id']) : null;
        
        $data_publi = null;
        if (!empty($_POST['mes_ano_publicacao'])) $data_publi = parseDataPublicacao($_POST['mes_ano_publicacao']);
        
        $db->prepare("UPDATE hqs SET 
            editora_id = ?, formato_id = ?, titulo = ?, numero_edicao = ?, 
            preco_compra = ?, mes_ano_publicacao = ?, data_publicacao = ?, 
            jornaleiro = ?, observacao = ?, link_origem = ?, imagem_capa = ?, 
            local_prateleira = ?, especial = ?, colecao_id = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?")
           ->execute([
               $_POST['editora_id'] ?: null, $_POST['formato_id'] ?: null,
               $_POST['titulo'], $_POST['numero_edicao'], floatval($_POST['preco_compra']),
               $_POST['mes_ano_publicacao'], $data_publi,
               $_POST['jornaleiro'], $_POST['observacao'],
               $_POST['link_origem'], $_POST['imagem_capa'], $_POST['local_prateleira'],
               $especial, $colecao_id, $id
           ]);
        $msg = 'HQ atualizada com sucesso!';
        
        $stmt = $db->prepare("SELECT * FROM hqs WHERE id = ?");
        $stmt->execute([$id]);
        $hq = $stmt->fetch();
    }
}

$editoras = $db->query("SELECT * FROM editoras ORDER BY nome")->fetchAll();
$formatos = $db->query("SELECT * FROM formatos ORDER BY nome")->fetchAll();
$colecoes = $db->query("SELECT id, nome FROM colecoes ORDER BY nome")->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar HQ</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .page-header h2 { font-size: 16px; font-weight: 600; margin: 0; }
        .form-section { background: var(--surface); border-radius: 8px; padding: 12px; margin-bottom: 12px; border: 1px solid var(--border); }
        .form-section h3 { font-size: 13px; font-weight: 600; margin: 0 0 10px 0; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px; }
        .form-group { display: flex; flex-direction: column; gap: 3px; }
        .form-group label { font-size: 11px; color: var(--text-secondary); font-weight: 500; }
        .form-group input, .form-group select, .form-group textarea { padding: 5px 8px; font-size: 12px; border: 1px solid var(--border); border-radius: 4px; background: var(--bg); color: var(--text); }
        .form-group input:disabled, .form-group select:disabled { opacity: 0.6; cursor: not-allowed; }
        .form-group-full { grid-column: 1 / -1; }
        .form-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 10px; }
        .capa-preview { max-width: 120px; border-radius: 6px; margin-top: 6px; }
        
        /* Bloco Especial */
        .especial-box {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 16px;
            background: linear-gradient(135deg, rgba(155, 89, 182, 0.06), rgba(155, 89, 182, 0.02));
            border: 1px dashed rgba(155, 89, 182, 0.4);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-top: 4px;
        }
        .especial-box:hover {
            background: linear-gradient(135deg, rgba(155, 89, 182, 0.1), rgba(155, 89, 182, 0.04));
            border-color: rgba(155, 89, 182, 0.6);
        }
        .especial-box input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin: 0;
            flex-shrink: 0;
            cursor: pointer;
            accent-color: #9b59b6;
        }
        .especial-box-texto {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .especial-box-texto strong {
            font-size: 13px;
            font-weight: 600;
            color: var(--text);
        }
        .especial-box-texto small {
            font-size: 11px;
            color: var(--text-secondary);
            line-height: 1.4;
        }
        [data-theme="dark"] .especial-box {
            background: linear-gradient(135deg, rgba(155, 89, 182, 0.12), rgba(155, 89, 182, 0.04));
            border-color: rgba(155, 89, 182, 0.5);
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <main class="main-content">
        <div class="page-header">
            <h2>Editar HQ</h2>
            <div style="display:flex; gap:6px;">
                <a href="hq_detalhe.php?id=<?= $id ?>" class="btn btn-outline btn-sm" target="_blank"><i class="fas fa-eye"></i> Ver</a>
                <a href="hqs_lista.php" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
            </div>
        </div>

        <?php if ($msg): ?><div class="alert alert-success"><?= $msg ?></div><?php endif; ?>
        <?php if ($erro): ?><div class="alert alert-error"><?= $erro ?></div><?php endif; ?>

        <form method="POST">
            <div class="form-section">
                <h3>Dados da HQ</h3>
                <div class="form-grid">
                    <?php if (!$isJornaleiro): ?>
                    <div class="form-group"><label>Editora</label><select name="editora_id"><option value="">Selecione...</option><?php foreach ($editoras as $e): ?><option value="<?= $e['id'] ?>" <?= $hq['editora_id']==$e['id']?'selected':'' ?>><?= htmlspecialchars($e['nome']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Formato</label><select name="formato_id"><option value="">Selecione...</option><?php foreach ($formatos as $f): ?><option value="<?= $f['id'] ?>" <?= $hq['formato_id']==$f['id']?'selected':'' ?>><?= htmlspecialchars($f['nome']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group form-group-full"><label>Título *</label><input type="text" name="titulo" value="<?= htmlspecialchars($hq['titulo']) ?>" required></div>
                    <div class="form-group"><label>Número/Edição</label><input type="text" name="numero_edicao" value="<?= htmlspecialchars($hq['numero_edicao']) ?>"></div>
                    <div class="form-group"><label>Preço de Compra</label><input type="number" step="0.01" name="preco_compra" value="<?= $hq['preco_compra'] ?>"></div>
                    <div class="form-group"><label>Mês/Ano Publicação</label><input type="text" name="mes_ano_publicacao" value="<?= htmlspecialchars($hq['mes_ano_publicacao']) ?>"></div>
                    <?php endif; ?>
                    
                    <div class="form-group"><label>Jornaleiro</label><select name="jornaleiro"><option value="Nao Informado" <?= $hq['jornaleiro']=='Nao Informado'?'selected':'' ?>>Não Informado</option><option value="Na Banca" <?= $hq['jornaleiro']=='Na Banca'?'selected':'' ?>>Na Banca</option><option value="Adquirido" <?= $hq['jornaleiro']=='Adquirido'?'selected':'' ?>>Adquirido</option></select></div>
                    
                    <?php if (!$isJornaleiro): ?>
                    <div class="form-group"><label>Local Prateleira</label><input type="text" name="local_prateleira" value="<?= htmlspecialchars($hq['local_prateleira']) ?>"></div>
                    
                    <div class="form-group">
                        <label>Coleção</label>
                        <select name="colecao_id" id="colecao-select" <?= $hq['especial'] ? 'disabled' : '' ?>>
                            <option value="">- Sem coleção -</option>
                            <?php foreach ($colecoes as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= ($hq['colecao_id']==$c['id'] && !$hq['especial'])?'selected':'' ?>><?= htmlspecialchars($c['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$isJornaleiro): ?>
            <div class="form-section">
                <h3>Classificação</h3>
                <label class="especial-box" for="especial-check">
                    <input type="checkbox" name="especial" id="especial-check" value="1" <?= $hq['especial'] ? 'checked' : '' ?> onchange="toggleColecao(this)">
                    <span class="especial-box-texto">
                        <strong>Edição Especial / Única</strong>
                        <small>Marque se esta HQ não pertence a nenhuma coleção. Ao marcar, o vínculo com coleção será removido automaticamente.</small>
                    </span>
                </label>
            </div>
            <?php endif; ?>

            <?php if (!$isJornaleiro): ?>
            <div class="form-section">
                <h3>Informações Adicionais</h3>
                <div class="form-grid">
                    <div class="form-group form-group-full"><label>Link de Origem</label><input type="url" name="link_origem" value="<?= htmlspecialchars($hq['link_origem']) ?>"></div>
                    <div class="form-group form-group-full">
                        <label>Imagem da Capa</label>
                        <input type="text" name="imagem_capa" value="<?= htmlspecialchars($hq['imagem_capa']) ?>">
                        <?php if ($hq['imagem_capa']): ?><img src="<?= htmlspecialchars($hq['imagem_capa']) ?>" class="capa-preview"><?php endif; ?>
                    </div>
                    <div class="form-group form-group-full"><label>Observação</label><textarea name="observacao" rows="3"><?= htmlspecialchars($hq['observacao']) ?></textarea></div>
                </div>
            </div>
            <?php endif; ?>

            <div class="form-actions">
                <a href="hqs_lista.php" class="btn btn-outline"><i class="fas fa-times"></i> Cancelar</a>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar</button>
            </div>
        </form>
    </main>

    <script>
        function toggleColecao(checkbox) {
            var sel = document.getElementById('colecao-select');
            if (!sel) return;
            if (checkbox.checked) {
                sel.value = '';
                sel.disabled = true;
            } else {
                sel.disabled = false;
            }
        }
    </script>
</body>
</html>