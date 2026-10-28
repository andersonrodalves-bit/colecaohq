<?php
// relatorios.php
require_once 'config.php';
require_once 'auth.php';
requireAdmin();
require_once __DIR__ . '/openspout/autoload.php';

use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Common\Entity\Style\Color;

$tipo = $_GET['tipo'] ?? 'excel';

$db = getDB();

$busca = $_GET['busca'] ?? '';
$editora_id = $_GET['editora_id'] ?? '';
$formato_id = $_GET['formato_id'] ?? '';
$ordenar = $_GET['ordenar'] ?? 'titulo_asc';
$jornaleiro = $_GET['jornaleiro'] ?? '';
$status = $_GET['status'] ?? 'ativo';

$query = "SELECT h.*, e.nome as editora_nome, f.nome as formato_nome FROM hqs h LEFT JOIN editoras e ON h.editora_id=e.id LEFT JOIN formatos f ON h.formato_id=f.id WHERE h.status=?";
$params = [$status];

if ($busca) {
    $clauses = [];
    foreach (['h.titulo','h.numero_edicao','e.nome','f.nome','CAST(h.preco_compra AS TEXT)','h.mes_ano_publicacao'] as $c) {
        $clauses[] = "$c LIKE ?"; $params[] = "%$busca%";
    }
    $query .= " AND (" . implode(" OR ", $clauses) . ")";
}
if ($editora_id) { $query .= " AND h.editora_id = ?"; $params[] = $editora_id; }
if ($formato_id) { $query .= " AND h.formato_id = ?"; $params[] = $formato_id; }
if ($jornaleiro) { $query .= " AND h.jornaleiro = ?"; $params[] = $jornaleiro; }

switch ($ordenar) {
    case 'titulo_asc': $order = 'h.titulo ASC'; break;
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
$agora = date('d/m/Y H:i');

// ============================================================
// EXCEL
// ============================================================
if ($tipo === 'excel') {
    $nomeArquivo = 'hqs_' . date('Y-m-d_H-i') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
    header('Cache-Control: max-age=0');

    $styleTitulo = new Style(); $styleTitulo->setFontBold(); $styleTitulo->setFontSize(14);
    $styleCabecalho = new Style(); $styleCabecalho->setFontBold(); $styleCabecalho->setFontSize(11);
    $styleCabecalho->setBackgroundColor(Color::rgb(54, 96, 146)); $styleCabecalho->setFontColor(Color::rgb(255, 255, 255));
    $styleDinheiro = new Style(); $styleDinheiro->setFormat('R$ #,##0.00');

    $writer = new Writer(); $writer->openToBrowser($nomeArquivo);
    $writer->addRow(Row::fromValues(['Catálogo de HQs']), $styleTitulo);
    $writer->addRow(Row::fromValues(['Gerado em: ' . $agora . ' | Total: ' . count($hqs) . ' HQs']));
    $writer->addRow(Row::fromValues([]));
    $cab = Row::fromValues(['Título','Edição','Editora','Formato','Publicação','Preço','Situação','Prateleira','Link']);
    $cab->setStyle($styleCabecalho); $writer->addRow($cab);
    foreach ($hqs as $hq) {
        $row = Row::fromValues([$hq['titulo'],$hq['numero_edicao'],$hq['editora_nome'],$hq['formato_nome'],$hq['mes_ano_publicacao'],floatval($hq['preco_compra']),$hq['jornaleiro'],$hq['local_prateleira'],$hq['link_origem']]);
        $row->getCellAtIndex(5)->setStyle($styleDinheiro);
        $writer->addRow($row);
    }
    $writer->close(); exit;
}

// ============================================================
// PDF
// ============================================================
if ($tipo === 'pdf') {
    require_once __DIR__ . '/tcpdf/tcpdf.php';
    
    class MYPDF extends TCPDF {
        public function Footer() {
            $this->SetY(-10);
            $this->SetFont('helvetica', 'I', 7);
            $this->Cell(0, 10, 'Página ' . $this->getAliasNumPage() . ' de ' . $this->getAliasNbPages(), 0, 0, 'C');
        }
    }
    
    $pdf = new MYPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('HQ Catalog');
    $pdf->SetTitle('Catálogo de HQs');
    $pdf->SetMargins(8, 8, 8);
    $pdf->SetAutoPageBreak(true, 12);
    $pdf->AddPage();
    
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 6, 'Catálogo de HQs', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(0, 5, 'Gerado em: ' . $agora . ' | Total: ' . count($hqs) . ' HQs', 0, 1, 'L');
    $pdf->Ln(2);
    
    $pdf->SetFillColor(54, 96, 146);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 8);
    $cols = ['Título', 'Edição', 'Editora', 'Publicação', 'Preço', 'Situação', 'Prateleira'];
    $w = [65, 15, 35, 25, 22, 25, 25];
    
    foreach ($cols as $i => $col) {
        $pdf->Cell($w[$i], 6, $col, 1, 0, 'C', true);
    }
    $pdf->Ln();
    
    $pdf->SetTextColor(51, 51, 51);
    $pdf->SetFont('helvetica', '', 7);
    
    foreach ($hqs as $hq) {
        $pdf->Cell($w[0], 5, mb_substr($hq['titulo'], 0, 55), 1);
        $pdf->Cell($w[1], 5, $hq['numero_edicao'], 1, 0, 'C');
        $pdf->Cell($w[2], 5, mb_substr($hq['editora_nome'], 0, 25), 1);
        $pdf->Cell($w[3], 5, $hq['mes_ano_publicacao'], 1, 0, 'C');
        $pdf->Cell($w[4], 5, 'R$ ' . number_format($hq['preco_compra'], 2, ',', '.'), 1, 0, 'R');
        $pdf->Cell($w[5], 5, $hq['jornaleiro'], 1, 0, 'C');
        $pdf->Cell($w[6], 5, mb_substr($hq['local_prateleira'], 0, 20), 1, 0, 'C');
        $pdf->Ln();
    }
    
    $pdf->Output('hqs_' . date('Ymd_His') . '.pdf', 'D');
    exit;
}