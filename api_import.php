<?php
// api_import.php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'config.php';

$db = getDB();
$dados = json_decode(file_get_contents('php://input'), true);

if (!$dados || empty($dados['titulo'])) {
    http_response_code(400);
    die('Dados invalidos');
}

// Verificar duplicata
if (!empty($dados['link'])) {
    $stmt = $db->prepare("SELECT id FROM hqs WHERE link_origem = ?");
    $stmt->execute([$dados['link']]);
    if ($stmt->fetch()) {
        echo "OK: Link ja cadastrado\n";
        exit;
    }
}

// Editora
$editora_id = null;
if (!empty($dados['editora'])) {
    $stmt = $db->prepare("SELECT id FROM editoras WHERE nome = ?");
    $stmt->execute([$dados['editora']]);
    $e = $stmt->fetch();
    if ($e) $editora_id = $e['id'];
    else { $db->prepare("INSERT INTO editoras (nome) VALUES (?)")->execute([$dados['editora']]); $editora_id = $db->lastInsertId(); }
}

// Formato
$formato_id = null;
if (!empty($dados['formato'])) {
    $stmt = $db->prepare("SELECT id FROM formatos WHERE nome = ?");
    $stmt->execute([$dados['formato']]);
    $f = $stmt->fetch();
    if ($f) $formato_id = $f['id'];
    else { $db->prepare("INSERT INTO formatos (nome) VALUES (?)")->execute([$dados['formato']]); $formato_id = $db->lastInsertId(); }
}

// Imagem via base64 (enviada pelo bookmarklet)
$imagem_local = '';
if (!empty($dados['imagem_base64'])) {
    $imgDir = __DIR__ . '/img';
    $nome = 'capa_' . date('YmdHis') . '_' . uniqid() . '.jpg';
    $caminho = $imgDir . '/' . $nome;
    $imgData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $dados['imagem_base64']));
    if ($imgData && file_put_contents($caminho, $imgData)) {
        $imagem_local = 'img/' . $nome;
    }
} elseif (!empty($dados['imagem'])) {
    $imagem_local = $dados['imagem'];
}

$db->prepare("INSERT INTO hqs (editora_id, formato_id, titulo, numero_edicao, preco_compra, mes_ano_publicacao, link_origem, imagem_capa) VALUES (?,?,?,?,?,?,?,?)")
   ->execute([$editora_id, $formato_id, $dados['titulo'], $dados['numero'] ?? '', floatval($dados['preco'] ?? 0), $dados['publicacao'] ?? '', $dados['link'] ?? '', $imagem_local]);

echo "OK: " . $dados['titulo'] . "\n";