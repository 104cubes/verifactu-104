<?php
/*  Verifactu104 - Módulo Veri*Factu para Dolibarr
 *  (C) 2025 104 CUBES S.L (Wayhoy!)
 *  Licencia GPL v3
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonhookactions.class.php';

use setasign\Fpdi\Tcpdf\Fpdi;

dol_syslog("VERIFACTU TEST CRÍTICO: ARCHIVO DE HOOK INCLUIDO Y PROCESADO", LOG_DEBUG);

class ActionsVerifactu104 extends CommonHookActions
{
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Hook: pdfgeneration
     * Añade página adicional con QR y hash al generar el PDF de una factura validada.
     */
    public function afterPDFCreation($parameters, &$pdf, &$action, $hookmanager)
    {
        global $conf;

        dol_syslog("VERIFACTU_HOOK: afterPDFCreation() EJECUTADO", LOG_DEBUG);

        if (empty($parameters['object'])) return 0;
        $object = $parameters['object'];

        // Solo facturas validadas
        if ($object->statut != 1) {
            dol_syslog("VERIFACTU_HOOK: Factura no validada → no insertar QR", LOG_DEBUG);
            return 0;
        }

        // Rutas de archivos
        $ref = dol_sanitizeFileName($object->ref);
        $pdf_file = $conf->facture->dir_output . "/" . $ref . "/" . $ref . ".pdf";
        $qr_file  = $conf->facture->dir_output . "/" . $ref . "/verifactu_qr.png";

        if (!file_exists($pdf_file) || !file_exists($qr_file)) {
            dol_syslog("VERIFACTU_HOOK: Falta PDF o QR → NO SE INSERTA", LOG_DEBUG);
            return 0;
        }

        try {
            // Cargar FPDI
            require_once DOL_DOCUMENT_ROOT . '/custom/verifactu104/lib/FPDI/src/autoload.php';

            $newpdf = new Fpdi();
            $pagecount = $newpdf->setSourceFile($pdf_file);

            for ($i = 1; $i <= $pagecount; $i++) {
                $tpl = $newpdf->importPage($i);
                $size = $newpdf->getTemplateSize($tpl);
                $newpdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $newpdf->useTemplate($tpl);
            }

            // Nueva página certificada
            $newpdf->AddPage('P', 'A4');
            $newpdf->SetMargins(20, 20, 20);

            // Título centrado
            $newpdf->SetFont('Helvetica', 'B', 14);
            $newpdf->Ln(5);
            $newpdf->Cell(0, 10, 'Certificación de Integridad de Factura (Veri*Factu)', 0, 1, 'C');

            $newpdf->SetLineWidth(0.3);
            $newpdf->Line(20, $newpdf->GetY(), 190, $newpdf->GetY());
            $newpdf->Ln(8);

            // QR centrado
            $newpdf->Image($qr_file, 80, $newpdf->GetY(), 50, 50, 'PNG');
            $newpdf->Ln(60);

           
            // Hash
            $sql = "SELECT hash_verifactu 
                    FROM " . MAIN_DB_PREFIX . "facture_extrafields 
                    WHERE fk_object = " . (int)$object->id;
            $resql = $this->db->query($sql);
            $hash_val = ($resql && $obj = $this->db->fetch_object($resql)) ? $obj->hash_verifactu : '';

            $newpdf->SetFont('Helvetica', '', 10);
            $newpdf->MultiCell(0, 6, "Hash criptográfico (SHA256):\n" . $hash_val, 0, 'L');
            $newpdf->Ln(5);

            $newpdf->MultiCell(
                0,
                6,
                "Esta factura ha sido firmada electrónicamente conforme a la normativa Veri*Factu, "
                    . "mediante un encadenamiento criptográfico que garantiza que no ha sido alterada.",
                0,
                'L'
            );

            $newpdf->Ln(10);
            $newpdf->SetFont('Helvetica', 'I', 9);
            $newpdf->Cell(0, 5, 'Documento generado automáticamente.', 0, 1, 'C');

            // Guardar PDF final
            $newpdf->Output($pdf_file, 'F');

            dol_syslog("VERIFACTU_HOOK: PDF actualizado correctamente", LOG_DEBUG);
        } catch (Exception $e) {
            dol_syslog("VERIFACTU_HOOK: ERROR FPDI → " . $e->getMessage(), LOG_ERR);
            return -1;
        }
// === GENERAR XML VERIFACTU ===
$xml_path = $conf->facture->dir_output . "/" . $ref . "/verifactu_" . $ref . ".xml";

$dom = new DOMDocument('1.0', 'UTF-8');
$dom->formatOutput = true;
$root = $dom->createElementNS('https://www.agenciatributaria.gob.es/verifactu/v1', 'RegistroFacturacionAlta');
$dom->appendChild($root);

// --- Emisor ---
$emisor = $dom->createElement('Emisor');
$emisor->appendChild($dom->createElement('NIF', $object->thirdparty->idprof1));
$emisor->appendChild($dom->createElement('NombreRazon', $object->thirdparty->name));
$root->appendChild($emisor);

// --- Receptor ---
$receptor = $dom->createElement('Receptor');
$receptor->appendChild($dom->createElement('NIF', $object->thirdparty->idprof1));
$receptor->appendChild($dom->createElement('NombreRazon', $object->thirdparty->name));
$root->appendChild($receptor);

// --- Factura ---
$factura = $dom->createElement('Factura');
$factura->appendChild($dom->createElement('NumeroFactura', $object->ref));
$factura->appendChild($dom->createElement('SerieFactura', $object->ref_client));
$factura->appendChild($dom->createElement('FechaExpedicion', substr($object->date, 0, 10)));
$factura->appendChild($dom->createElement('FechaOperacion', substr($object->date, 0, 10)));
$factura->appendChild($dom->createElement('TipoFactura', 'F1'));
$factura->appendChild($dom->createElement('DescripcionOperacion', $object->note_public));
$factura->appendChild($dom->createElement('BaseImponible', number_format($object->total_ht, 2, '.', '')));
$factura->appendChild($dom->createElement('TipoIVA', '21.00'));
$factura->appendChild($dom->createElement('CuotaIVA', number_format($object->total_tva, 2, '.', '')));
$factura->appendChild($dom->createElement('ImporteTotal', number_format($object->total_ttc, 2, '.', '')));
$root->appendChild($factura);

// --- Sistema ---
$sistema = $dom->createElement('Sistema');
$sistema->appendChild($dom->createElement('CodigoSistema', 'VERIFACTU104'));
$sistema->appendChild($dom->createElement('Productor', '104 CUBES S.L.'));
$sistema->appendChild($dom->createElement('HashAnterior', '')); // Recuperar de extra field si existe
$sistema->appendChild($dom->createElement('HashActual', $hash_val));
$sistema->appendChild($dom->createElement('FechaHoraGeneracion', date('c')));
$root->appendChild($sistema);

// Guardar XML
$dom->save($xml_path);
dol_syslog("VERIFACTU_HOOK: XML VeriFactu generado en $xml_path", LOG_DEBUG);

        return 0;
    }

}
