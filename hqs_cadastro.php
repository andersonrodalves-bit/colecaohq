<?php
// hqs_cadastro.php
require_once 'config.php';
require_once 'auth.php';
requireAdmin();

$db = getDB();
$msg = '';
$erro = '';
$debugInfo = [];
$imgDir = __DIR__ . '/img';

// Sites suportados
$sitesSuportados = [
    'panini' => ['nome' => 'Panini', 'dominios' => ['panini.com.br'], 'funcao' => 'scrapePanini'],
    'guiadosquadrinhos' => ['nome' => 'Guia dos Quadrinhos', 'dominios' => ['guiadosquadrinhos.com', 'www.guiadosquadrinhos.com'], 'funcao' => 'scrapeGuiaQuadrinhos'],
];

function detectarSite($url, $sites) {
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return null;
    foreach ($sites as $key => $site) {
        foreach ($site['dominios'] as $dominio) {
            if (strpos($host, $dominio) !== false) return $key;
        }
    }
    return null;
}

function normalizarTitulo($titulo) {
    $t = mb_strtolower(trim($titulo), 'UTF-8');
    $t = preg_replace('/[áàãâä]/u', 'a', $t);
    $t = preg_replace('/[éèêë]/u', 'e', $t);
    $t = preg_replace('/[íìîï]/u', 'i', $t);
    $t = preg_replace('/[óòõôö]/u', 'o', $t);
    $t = preg_replace('/[úùûü]/u', 'u', $t);
    $t = preg_replace('/[ç]/u', 'c', $t);
    $t = preg_replace('/\b(a|o|the|os|as)\b/', '', $t);
    $t = preg_replace('/[^a-z0-9\s]/', '', $t);
    $t = preg_replace('/\s+/', ' ', $t);
    return trim($t);
}

function verificarSimilares($db, $titulo, $numero, $link) {
    if (empty($titulo)) return [];
    $norm = normalizarTitulo($titulo);
    if (strlen($norm) < 5) return [];
    $todas = $db->query("SELECT id, titulo, numero_edicao, link_origem FROM hqs WHERE status='ativo'")->fetchAll();
    $similares = [];
    foreach ($todas as $hq) {
        if (!empty($link) && $hq['link_origem'] === $link) continue;
        if ($hq['numero_edicao'] !== $numero) continue;
        $normExistente = normalizarTitulo($hq['titulo']);
        similar_text($norm, $normExistente, $percent);
        if ($percent > 75) $similares[] = ['id' => $hq['id'], 'titulo' => $hq['titulo'], 'numero' => $hq['numero_edicao'], 'similaridade' => round($percent)];
    }
    return array_slice($similares, 0, 5);
}

function scrapePanini($xpath, $url) {
    $titulo = ''; $preco = 0; $imagem = ''; $descricao = ''; $formato = ''; $mes = ''; $ano = '';
    $meta = $xpath->query("//meta[@property='og:title']")->item(0);
    if ($meta) $titulo = $meta->getAttribute('content');
    $meta = $xpath->query("//meta[@property='product:price:amount']")->item(0);
    if ($meta) $preco = floatval($meta->getAttribute('content'));
    $meta = $xpath->query("//meta[@property='og:image']")->item(0);
    if ($meta) $imagem = $meta->getAttribute('content');
    $meta = $xpath->query("//meta[@property='og:description']")->item(0);
    if ($meta) $descricao = $meta->getAttribute('content');
    $tds = $xpath->query("//td[@data-th]");
    foreach ($tds as $td) {
        $th = $td->getAttribute('data-th');
        $val = trim($td->textContent);
        if ($th == 'Encadernação') $formato = $val;
        if ($th == 'Mês') $mes = $val;
        if ($th == 'Ano de publicação') $ano = $val;
    }
    $numero = '';
    if (preg_match('/\s(\d{1,3})$/', $titulo, $m)) $numero = $m[1];
    return ['titulo' => $titulo, 'editora_nome' => 'Panini Comics', 'formato_nome' => $formato, 'numero_edicao' => $numero, 'preco' => $preco, 'mes_ano' => $mes && $ano ? "$mes/$ano" : ($mes ?: $ano ?: ''), 'observacao' => substr($descricao, 0, 500), 'link' => $url, 'imagem' => $imagem];
}

function scrapeGuiaQuadrinhos($xpath, $url) {
    $titulo = ''; $preco = 0; $imagem = ''; $editora = ''; $formato = ''; $numero = ''; $mes_ano = '';
    $el = $xpath->query("//*[@id='nome_titulo_lb']")->item(0);
    if ($el) {
        $titulo = trim($el->textContent);
        $nobrs = $el->getElementsByTagName('nobr');
        if ($nobrs->length >= 2) $numero = trim(str_replace('n°', '', $nobrs->item(1)->textContent));
        elseif (preg_match('/n[°º]\s*(\d+)/', $titulo, $m)) $numero = $m[1];
    }
    $el = $xpath->query("//*[@id='preco']")->item(0);
    if ($el) { $txt = str_replace('R$', '', trim($el->textContent)); $preco = floatval(str_replace(',', '.', str_replace('.', '', $txt))); }
    $el = $xpath->query("//*[@id='licenciador']")->item(0);
    if ($el) $editora = trim($el->textContent);
    $el = $xpath->query("//*[@id='formato']")->item(0);
    if ($el) { $formatoHTML = $el->ownerDocument->saveHTML($el); $formato = strip_tags(trim(explode('<br>', $formatoHTML)[0])); }
    $el = $xpath->query("//*[@id='data_publi']")->item(0);
    if ($el) $mes_ano = trim($el->textContent);
    $imgs = $xpath->query("//img[contains(@src, 'ShowImage')]");
    if ($imgs->length > 0) {
        $src = $imgs->item(0)->getAttribute('src');
        if ($src) { if (strpos($src, 'http') !== 0) $src = 'http://www.guiadosquadrinhos.com' . $src; $imagem = $src; }
    }
    return ['titulo' => $titulo, 'editora_nome' => $editora, 'formato_nome' => $formato, 'numero_edicao' => $numero, 'preco' => $preco, 'mes_ano' => $mes_ano, 'observacao' => '', 'link' => $url, 'imagem' => $imagem];
}

function baixarImagem($url, $pasta) {
    $ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
    if (!$ext || strlen($ext) > 5) $ext = 'jpg';
    $nome = 'capa_' . date('YmdHis') . '_' . uniqid() . '.' . $ext;
    $caminho = $pasta . '/' . $nome;
    $img = @file_get_contents($url, false, stream_context_create(['http' => ['header' => "User-Agent: Mozilla/5.0\r\n", 'timeout' => 10]]));
    if ($img && file_put_contents($caminho, $img)) return $nome;
    return false;
}

function salvarHQ($db, $dados) {
    $editora_id = null;
    if (!empty($dados['editora_nome'])) {
        $stmt = $db->prepare("SELECT id FROM editoras WHERE nome = ?");
        $stmt->execute([$dados['editora_nome']]);
        $e = $stmt->fetch();
        if ($e) $editora_id = $e['id'];
        else { $db->prepare("INSERT INTO editoras (nome, observacao) VALUES (?, 'Importado')")->execute([$dados['editora_nome']]); $editora_id = $db->lastInsertId(); }
    }
    $formato_id = null;
    if (!empty($dados['formato_nome'])) {
        $stmt = $db->prepare("SELECT id FROM formatos WHERE nome = ?");
        $stmt->execute([$dados['formato_nome']]);
        $f = $stmt->fetch();
        if ($f) $formato_id = $f['id'];
        else { $db->prepare("INSERT INTO formatos (nome, observacao) VALUES (?, 'Importado')")->execute([$dados['formato_nome']]); $formato_id = $db->lastInsertId(); }
    }
    $data_publi = null;
    if (!empty($dados['mes_ano'])) $data_publi = parseDataPublicacao($dados['mes_ano']);
    
    $especial = isset($dados['especial']) ? intval($dados['especial']) : 0;
    $colecao_id = isset($dados['colecao_id']) ? $dados['colecao_id'] : null;
    // Especial nunca pode ter coleção
    if ($especial) $colecao_id = null;
    
    $db->prepare("INSERT INTO hqs (editora_id, formato_id, titulo, numero_edicao, preco_compra, mes_ano_publicacao, data_publicacao, jornaleiro, observacao, link_origem, imagem_capa, local_prateleira, especial, colecao_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([
           $editora_id, $formato_id, $dados['titulo'] ?? '', $dados['numero_edicao'] ?? '',
           $dados['preco'] ?? 0, $dados['mes_ano'] ?? '', $data_publi,
           $dados['jornaleiro'] ?? 'Nao Informado', $dados['observacao'] ?? '',
           $dados['link'] ?? '', $dados['imagem'] ?? '', $dados['prateleira'] ?? '',
           $especial, $colecao_id
       ]);
}

// ============================================================
// PROCESSAR IMPORTAÇÃO
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ler_links'])) {
    $links = array_filter(explode("\n", $_POST['links']));
    $siteSelecionado = $_POST['site'] ?? 'panini';
    $editoraForcada = $_POST['editora_forcada'] ?? '';
    $situacaoForcada = $_POST['situacao_forcada'] ?? '';
    $especialForcado = isset($_POST['especial_forcado']) ? intval($_POST['especial_forcado']) : 0;
    
    foreach ($links as $url) {
        $url = trim($url);
        if (!$url) continue;
        $debugItem = ['url' => $url, 'status' => '', 'titulo' => '', 'numero' => '', 'imagem' => '', 'formato' => '', 'erro' => ''];
        
        $siteDetectado = detectarSite($url, $sitesSuportados);
        if (!$siteDetectado) { $debugItem['status'] = 'dominio_invalido'; $debugItem['erro'] = 'Domínio não suportado'; $debugInfo[] = $debugItem; continue; }
        if ($siteDetectado !== $siteSelecionado) { $debugItem['status'] = 'site_misturado'; $debugItem['erro'] = 'Link é do site ' . $sitesSuportados[$siteDetectado]['nome']; $debugInfo[] = $debugItem; continue; }
        
        $stmt = $db->prepare("SELECT id FROM hqs WHERE link_origem = ?");
        $stmt->execute([$url]);
        if ($stmt->fetch()) { $debugItem['status'] = 'duplicado_link'; $debugItem['erro'] = 'Link já cadastrado'; $debugInfo[] = $debugItem; continue; }
        
        $html = @file_get_contents($url, false, stream_context_create(['http' => ['header' => "User-Agent: Mozilla/5.0\r\n", 'timeout' => 15]]));
        if (!$html) { $debugItem['status'] = 'erro_acesso'; $debugItem['erro'] = 'Falha ao acessar a página'; $debugInfo[] = $debugItem; continue; }
        
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($dom);
        
        $funcaoScraper = $sitesSuportados[$siteDetectado]['funcao'];
        $dados = $funcaoScraper($xpath, $url);
        
        if (!empty($editoraForcada)) $dados['editora_nome'] = $editoraForcada;
        if (!empty($situacaoForcada)) $dados['jornaleiro'] = $situacaoForcada;
        else $dados['jornaleiro'] = 'Nao Informado';
        $dados['especial'] = $especialForcado;
        
        $debugItem['titulo'] = $dados['titulo'];
        $debugItem['numero'] = $dados['numero_edicao'] ?? '';
        $debugItem['formato'] = $dados['formato_nome'] ?? '';
        
        $similares = verificarSimilares($db, $dados['titulo'], $dados['numero_edicao'], $url);
        if ($similares) $debugItem['similares'] = $similares;
        
        if (!empty($dados['imagem']) && filter_var($dados['imagem'], FILTER_VALIDATE_URL)) {
            $localImg = baixarImagem($dados['imagem'], $imgDir);
            if ($localImg) { $dados['imagem'] = 'img/' . $localImg; $debugItem['imagem'] = 'img/' . $localImg; }
        }
        
        salvarHQ($db, $dados);
        $debugItem['status'] = 'sucesso';
        $debugInfo[] = $debugItem;
    }
    $sucessos = count(array_filter($debugInfo, fn($d) => $d['status'] === 'sucesso'));
    $falhas = count(array_filter($debugInfo, fn($d) => $d['status'] !== 'sucesso'));
    $msg = "$sucessos HQ(s) importada(s), $falhas falha(s).";
}

// ============================================================
// CADASTRO MANUAL
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_manual'])) {
    $titulo = $_POST['titulo']; $numero = $_POST['numero_edicao']; $link = $_POST['link_origem'];
    $especial = isset($_POST['especial']) ? 1 : 0;
    $colecao_id = (!$especial && !empty($_POST['colecao_id'])) ? intval($_POST['colecao_id']) : null;
    
    if (!empty($link)) { $stmt = $db->prepare("SELECT id FROM hqs WHERE link_origem = ?"); $stmt->execute([$link]); if ($stmt->fetch()) $erro = 'Link já cadastrado.'; }
    if (!$erro) {
        $similares = verificarSimilares($db, $titulo, $numero, $link);
        if ($similares) { $_SESSION['similares_manual'] = $similares; $msg = 'Possíveis duplicatas encontradas.'; }
        $dados = ['titulo' => $titulo, 'editora_nome' => '', 'formato_nome' => '', 'numero_edicao' => $numero, 'preco' => floatval($_POST['preco_compra']), 'mes_ano' => $_POST['mes_ano_publicacao'], 'jornaleiro' => $_POST['jornaleiro'], 'observacao' => $_POST['observacao'], 'link' => $link, 'imagem' => $_POST['imagem_capa'], 'prateleira' => $_POST['local_prateleira'], 'especial' => $especial, 'colecao_id' => $colecao_id];
        
        // Ajustar editora e formato via ID
        $editora_id_manual = $_POST['editora_id'] ?? null;
        $formato_id_manual = $_POST['formato_id'] ?? null;
        
        // Vamos adaptar: salvarHQ espera nome, então convertemos
        if ($editora_id_manual) {
            $stmt = $db->prepare("SELECT nome FROM editoras WHERE id = ?"); $stmt->execute([$editora_id_manual]);
            $e = $stmt->fetch(); if ($e) $dados['editora_nome'] = $e['nome'];
        }
        if ($formato_id_manual) {
            $stmt = $db->prepare("SELECT nome FROM formatos WHERE id = ?"); $stmt->execute([$formato_id_manual]);
            $f = $stmt->fetch(); if ($f) $dados['formato_nome'] = $f['nome'];
        }
        
        salvarHQ($db, $dados);
        if (!$msg) $msg = 'HQ cadastrada com sucesso!';
    }
}

$editoras = $db->query("SELECT * FROM editoras ORDER BY nome")->fetchAll();
$formatos = $db->query("SELECT * FROM formatos ORDER BY nome")->fetchAll();
$colecoes = $db->query("SELECT id, nome FROM colecoes ORDER BY nome")->fetchAll();
$similaresManual = $_SESSION['similares_manual'] ?? [];
unset($_SESSION['similares_manual']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastrar HQ</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .debug-box { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 12px; margin-bottom: 15px; font-size: 12px; max-height: 400px; overflow-y: auto; }
        .debug-box h4 { margin: 0 0 8px 0; }
        .debug-item { margin: 2px 0; padding: 4px 0; border-bottom: 1px solid var(--border); }
        .debug-success { color: var(--success); }
        .debug-error { color: var(--danger); }
        .debug-warning { color: #d97706; }
        .overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; }
        .overlay.active { display: flex; }
        .overlay-box { background: var(--surface); border-radius: 12px; padding: 30px; text-align: center; }
        .spinner { font-size: 32px; animation: spin 1s linear infinite; margin-bottom: 15px; }
        @keyframes spin { 100% { transform: rotate(360deg); } }
        .import-opts { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 10px; }
        .import-opts select { padding: 4px 8px; font-size: 12px; border: 1px solid var(--border); border-radius: 4px; background: var(--surface); color: var(--text); }
        .checkbox-line { display: flex; align-items: center; gap: 6px; font-size: 12px; }
        .checkbox-line input { margin: 0; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; }
        .form-group { display: flex; flex-direction: column; gap: 3px; }
        .form-group label { font-size: 11px; color: var(--text-secondary); font-weight: 500; }
        .form-group input, .form-group select, .form-group textarea { padding: 5px 8px; font-size: 12px; border: 1px solid var(--border); border-radius: 4px; background: var(--bg); color: var(--text); }
        .form-group-full { grid-column: 1 / -1; }
    </style>
</head>
<body>
    <div class="overlay" id="overlay-import">
        <div class="overlay-box">
            <div class="spinner"><i class="fas fa-spinner"></i></div>
            <p>Importando... Aguarde.</p>
        </div>
    </div>

    <?php include 'header.php'; ?>
    <main class="main-content">
        <div class="page-header"><h2>Cadastrar HQ</h2></div>
        
        <?php if ($msg): ?><div class="alert alert-success"><?= $msg ?></div><?php endif; ?>
        <?php if ($erro): ?><div class="alert alert-error"><?= $erro ?></div><?php endif; ?>

        <?php if (!empty($similaresManual)): ?>
        <div class="debug-box"><h4><i class="fas fa-exclamation-triangle"></i> Possíveis duplicatas:</h4>
            <?php foreach ($similaresManual as $s): ?><div class="debug-item debug-warning"><?= htmlspecialchars($s['titulo']) ?> (Edição: <?= $s['numero'] ?>) - <?= $s['similaridade'] ?>% similar</div><?php endforeach; ?>
        </div><?php endif; ?>
        
        <?php if (!empty($debugInfo)): ?>
        <div class="debug-box"><h4>Resultado da importação:</h4>
            <?php foreach ($debugInfo as $i => $d): ?>
                <div class="debug-item <?= $d['status'] === 'sucesso' ? 'debug-success' : 'debug-error' ?>">
                    <?= $i+1 ?>. <?= htmlspecialchars($d['titulo'] ?: 'Sem título') ?>
                    <?php if ($d['status'] === 'sucesso'): ?>
                        <br><small>Número: <?= htmlspecialchars($d['numero'] ?: '(vazio)') ?> | Imagem: <?= htmlspecialchars($d['imagem'] ?: '(vazia)') ?> | Formato: <?= htmlspecialchars($d['formato'] ?: '(vazio)') ?></small>
                        <?php if (!empty($d['similares'])): ?><br><small class="debug-warning">⚠️ Similares: <?php foreach ($d['similares'] as $s): ?>"<?= htmlspecialchars($s['titulo']) ?>" (<?= $s['similaridade'] ?>%) <?php endforeach; ?></small><?php endif; ?>
                    <?php else: ?><br><small><?= htmlspecialchars($d['erro']) ?></small><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div><?php endif; ?>

        <div class="form-section">
            <h3><i class="fas fa-link"></i> Importar por Links</h3>
            <form method="POST" id="form-import">
                <div class="form-group" style="margin-bottom:8px;">
                    <label>Site de origem</label>
                    <select name="site" style="max-width:300px;">
                        <?php foreach ($sitesSuportados as $key => $site): ?><option value="<?= $key ?>"><?= $site['nome'] ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="import-opts">
                    <select name="editora_forcada">
                        <option value="">Editora: Automático</option>
                        <?php foreach ($editoras as $e): ?><option value="<?= htmlspecialchars($e['nome']) ?>"><?= htmlspecialchars($e['nome']) ?></option><?php endforeach; ?>
                    </select>
                    <select name="situacao_forcada">
                        <option value="">Situação: Automático</option>
                        <option value="Nao Informado">Não Informado</option>
                        <option value="Na Banca">Na Banca</option>
                        <option value="Adquirido">Adquirido</option>
                    </select>
                    <label class="checkbox-line"><input type="checkbox" name="especial_forcado" value="1"> Marcar todas como Especial</label>
                </div>
                <div class="form-group">
                    <label>Links (um por linha)</label>
                    <textarea name="links" rows="4" placeholder="https://panini.com.br/exemplo-1&#10;http://www.guiadosquadrinhos.com/edicao/..."></textarea>
                </div>
                <div style="display:flex; gap:8px; margin-top:8px;">
                    <button type="submit" name="ler_links" class="btn btn-primary" onclick="document.getElementById('overlay-import').classList.add('active')"><i class="fas fa-download"></i> Ler Links e Salvar</button>
                    <a href="hqs_lista.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Voltar</a>
                </div>
            </form>
        </div>

        <div class="form-section">
            <h3><i class="fas fa-pen"></i> Cadastro Manual</h3>
            <form method="POST">
                <div class="form-grid">
                    <div class="form-group"><label>Editora</label><select name="editora_id"><option value="">Selecione...</option><?php foreach ($editoras as $e): ?><option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['nome']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Formato</label><select name="formato_id"><option value="">Selecione...</option><?php foreach ($formatos as $f): ?><option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['nome']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group form-group-full"><label>Titulo *</label><input type="text" name="titulo" required></div>
                    <div class="form-group"><label>Numero/Edicao</label><input type="text" name="numero_edicao" placeholder="Ex: 01, Vol 03"></div>
                    <div class="form-group"><label>Preco de Compra</label><input type="number" step="0.01" name="preco_compra" placeholder="0.00"></div>
                    <div class="form-group"><label>Mes/Ano Publicacao</label><input type="text" name="mes_ano_publicacao" placeholder="Ex: Janeiro/2026"></div>
                    <div class="form-group"><label>Jornaleiro</label><select name="jornaleiro"><option value="Nao Informado">Nao Informado</option><option value="Na Banca">Na Banca</option><option value="Adquirido">Adquirido</option></select></div>
                    <div class="form-group"><label>Local Prateleira</label><input type="text" name="local_prateleira" placeholder="Ex: Estante A - Prateleira 2"></div>
                    <div class="form-group"><label>Coleção</label><select name="colecao_id" id="manual-colecao"><option value="">- Sem coleção -</option><?php foreach ($colecoes as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nome']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group">
                        <label>Especial</label>
                        <label class="checkbox-line" style="padding:6px 0;">
                            <input type="checkbox" name="especial" id="manual-especial" value="1" onchange="document.getElementById('manual-colecao').disabled = this.checked; if(this.checked) document.getElementById('manual-colecao').value='';">
                            Edição única
                        </label>
                    </div>
                    <div class="form-group form-group-full"><label>Link de Origem</label><input type="url" name="link_origem" placeholder="https://..."></div>
                    <div class="form-group form-group-full"><label>Imagem da Capa (URL)</label><input type="text" name="imagem_capa" placeholder="https://..."></div>
                    <div class="form-group form-group-full"><label>Observacao</label><textarea name="observacao" rows="3" placeholder="Estado de conservacao, notas..."></textarea></div>
                </div>
                <div class="form-actions" style="margin-top:10px;"><button type="submit" name="salvar_manual" class="btn btn-primary"><i class="fas fa-save"></i> Salvar HQ</button></div>
            </form>
        </div>
    </main>
</body>
</html>