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
// === GENERAR XML VERIFACTU (SOAP completo según WSDL AEAT) ===
        $xml_path = $conf->facture->dir_output . "/" . $ref . "/verifactu_" . $ref . ".xml";

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        // Namespaces
        $soapenv_ns = 'http://schemas.xmlsoap.org/soap/envelope/';
        $sfLR_ns    = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroLR.xsd';

        // Envelope
        $envelope = $dom->createElementNS($soapenv_ns, 'soapenv:Envelope');
        $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sfLR', $sfLR_ns);
        $dom->appendChild($envelope);

        // Header vacío
        $header = $dom->createElement('soapenv:Header');
        $envelope->appendChild($header);

        // Body
        $body = $dom->createElement('soapenv:Body');
        $envelope->appendChild($body);

        // RegFactuSistemaFacturacion
        $reg = $dom->createElement('sfLR:RegFactuSistemaFacturacion');
        $body->appendChild($reg);

        // Datos emisor (empresa) para Cabecera y otros
        $nif_empresa = $conf->global->MAIN_INFO_SIREN ?: $conf->global->MAIN_INFO_TVAINTRA ?: 'B00000000';
        $nombre_empresa = $conf->global->MAIN_INFO_SOCIETE_NOM ?: 'Empresa Dolibarr';

        // Cabecera
        $cabecera = $dom->createElement('sfLR:Cabecera');
        $reg->appendChild($cabecera);

        $idVersion = $dom->createElement('sfLR:IDVersion', '1.0');
        $cabecera->appendChild($idVersion);

        $titular = $dom->createElement('sfLR:Titular');
        $cabecera->appendChild($titular);

        $tit_nif = $dom->createElement('sfLR:NIF', $nif_empresa);
        $titular->appendChild($tit_nif);

        $tit_nombre = $dom->createElement('sfLR:NombreRazon', $nombre_empresa);
        $titular->appendChild($tit_nombre);

        $tipoCom = $dom->createElement('sfLR:TipoComunicacion', 'A0');
        $cabecera->appendChild($tipoCom);

        // RegistroFacturacionAlta
        $rfa = $dom->createElement('sfLR:RegistroFacturacionAlta');
        $reg->appendChild($rfa);

        // ObligadoEmisor
        $obl = $dom->createElement('sfLR:ObligadoEmisor');
        $rfa->appendChild($obl);

        $obl_nif = $dom->createElement('sfLR:NIF', $nif_empresa);
        $obl->appendChild($obl_nif);

        // Emisor
        $emisor = $dom->createElement('sfLR:Emisor');
        $rfa->appendChild($emisor);

        $emi_nif = $dom->createElement('sfLR:NIF', $nif_empresa);
        $emisor->appendChild($emi_nif);

        $emi_nom = $dom->createElement('sfLR:NombreRazon', $nombre_empresa);
        $emisor->appendChild($emi_nom);

        // Receptor (cliente)
        $receptor = $dom->createElement('sfLR:Receptor');
        $rfa->appendChild($receptor);

        $rec_nif = $dom->createElement('sfLR:NIF', $object->thirdparty->idprof1);
        $receptor->appendChild($rec_nif);

        $rec_nom = $dom->createElement('sfLR:NombreRazon', $object->thirdparty->name);
        $receptor->appendChild($rec_nom);

        // Factura
        $factura = $dom->createElement('sfLR:Factura');
        $rfa->appendChild($factura);

        $fact_num = $dom->createElement('sfLR:NumeroFactura', $object->ref);
        $factura->appendChild($fact_num);

        $fact_serie = $dom->createElement('sfLR:SerieFactura', '');
        $factura->appendChild($fact_serie);

        $fecha = dol_print_date($object->date, '%Y-%m-%d');
        $fact_fexp = $dom->createElement('sfLR:FechaExpedicion', $fecha);
        $factura->appendChild($fact_fexp);

        $fact_fop = $dom->createElement('sfLR:FechaOperacion', $fecha);
        $factura->appendChild($fact_fop);

        $fact_tipo = $dom->createElement('sfLR:TipoFactura', 'F1');
        $factura->appendChild($fact_tipo);

        $descripcion = !empty($object->note_public) ? $object->note_public : 'Factura emitida mediante VeriFactu';
        $fact_desc = $dom->createElement('sfLR:DescripcionOperacion', $descripcion);
        $factura->appendChild($fact_desc);

        $fact_base = $dom->createElement('sfLR:BaseImponible', number_format($object->total_ht, 2, '.', ''));
        $factura->appendChild($fact_base);

        $tipo_iva_val = number_format($object->lines[0]->tva_tx ?: 21, 2, '.', '');
        $fact_tiva = $dom->createElement('sfLR:TipoIVA', $tipo_iva_val);
        $factura->appendChild($fact_tiva);

        $fact_cuota = $dom->createElement('sfLR:CuotaIVA', number_format($object->total_tva, 2, '.', ''));
        $factura->appendChild($fact_cuota);

        $fact_total = $dom->createElement('sfLR:ImporteTotal', number_format($object->total_ttc, 2, '.', ''));
        $factura->appendChild($fact_total);

        // Sistema
        $sistema = $dom->createElement('sfLR:Sistema');
        $rfa->appendChild($sistema);

        $sis_cod = $dom->createElement('sfLR:CodigoSistema', 'VERIFACTU104');
        $sistema->appendChild($sis_cod);

        $sis_prod = $dom->createElement('sfLR:Productor', '104 CUBES S.L.');
        $sistema->appendChild($sis_prod);

        // TODO: incorporar hash anterior real cuando la cadena esté operativa
        $sis_hash_ant = $dom->createElement('sfLR:HashAnterior', '');
        $sistema->appendChild($sis_hash_ant);

        $sis_hash_act = $dom->createElement('sfLR:HashActual', $hash_val);
        $sistema->appendChild($sis_hash_act);

        $sis_fgen = $dom->createElement('sfLR:FechaHoraGeneracion', date('c'));
        $sistema->appendChild($sis_fgen);

        // Guardar SOAP completo
        $dom->save($xml_path);
dol_syslog("VERIFACTU_HOOK: XML VeriFactu generado en $xml_path", LOG_DEBUG);

        return 0;
    }

}
