<?php
/*  Verifactu104 - Módulo Veri*Factu para Dolibarr
 *  (C) 2025 104 CUBES S.L (Wayhoy!)
 *  Licencia GPL v3
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonhookactions.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/verifactu104/lib/verifactu104.lib.php';

use setasign\Fpdi\Tcpdf\Fpdi;

dol_syslog("VERIFACTU TEST CRÍTICO: ARCHIVO DE HOOK INCLUIDO Y PROCESADO", LOG_DEBUG);

class ActionsVerifactu104 extends CommonHookActions
{
    public function __construct($db)
    {
        $this->db = $db;
    }

    public function doActions($parameters, &$object, &$action, $hookmanager) {
        if ($action == 'verifactu_send') {
            return $this->actionEnviarAEAT($object);
        }
        if ($action == 'verifactu_subsanar') {
            return $this->actionSubsanarAEAT($object);
        }
        return 0;
    }

    private function getHashPrev($object) {
        global $db;
        $serie = preg_replace('/[^A-Za-z]/','', $object->ref);
        $sql = "SELECT ef.options_hash_verifactu as hash, ef.options_verifactu_timestamp as ts
                FROM ".MAIN_DB_PREFIX."facture_extrafields ef
                JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid=ef.fk_object
                WHERE f.ref LIKE '".$db->escape($serie)."%' AND ef.options_hash_verifactu IS NOT NULL";
        $res = $db->query($sql);
        $records = [];
        while ($obj = $db->fetch_object($res)) {
            $records[] = ['hash'=>$obj->hash, 'ts'=>(int)$obj->ts];
        }
        $sql2="SELECT ace.verifactu_event_hash as hash, ace.verifactu_event_timestamp as ts
               FROM ".MAIN_DB_PREFIX."actioncomm ac
               JOIN ".MAIN_DB_PREFIX."actioncomm_extrafields ace ON ac.id=ace.fk_object
               WHERE ac.elementtype='facture'
               AND ac.fk_element IN (
                    SELECT f2.rowid FROM ".MAIN_DB_PREFIX."facture f2
                    WHERE f2.ref LIKE '".$db->escape($serie)."%'
               )";
        $res2 = $db->query($sql2);
        while ($o=$db->fetch_object($res2)) {
            $records[]=['hash'=>$o->hash, 'ts'=>(int)$o->ts];
        }
        usort($records,function($a,$b){return $b['ts']<$a['ts']?1:-1;});
        return $records[0]['hash'] ?? '';
    }

    private function registerEvent($object,$tipo,$hash_actual,$hash_prev,$timestamp){
        global $db;
        require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
        $ev=new ActionComm($db);
        $ev->elementtype='facture';
        $ev->fk_element=$object->id;
        $ev->code='VERIFACTU_EVENT';
        $ev->label='Evento VeriFactu '.$tipo;
        $ev->datep=dol_now();
        $ev->note='Evento '.$tipo;
        global $user;
        $ev->create($user);
        $db->query("UPDATE ".MAIN_DB_PREFIX."actioncomm_extrafields
                    SET verifactu_event_hash='".$db->escape($hash_actual)."',
                        verifactu_event_hash_prev='".$db->escape($hash_prev)."',
                        verifactu_event_timestamp=".(int)$timestamp.",
                        verifactu_event_tipo='".$db->escape($tipo)."'
                    WHERE fk_object=".(int)$ev->id);
    }

    private function actionSubsanarAEAT($object){
        global $conf;
        require_once DOL_DOCUMENT_ROOT.'/custom/verifactu104/class/VerifactuXMLBuilder.class.php';
        $object->fetch_thirdparty();
        $object->fetch_optionals();
        $hash_prev=$this->getHashPrev($object);
        $hash_actual=hash('sha256',uniqid('vf',true));
        $timestamp=dol_now();
        $this->registerEvent($object,'subsanacion',$hash_actual,$hash_prev,$timestamp);
        $ref=dol_sanitizeFileName($object->ref);
        $xml_path=$conf->facture->dir_output."/".$ref."/subsanacion_".$ref.".xml";
        $builder=new VerifactuXMLBuilder($this->db,$conf);
        $builder->buildAltaSoapAndSave($object,$hash_prev,$hash_actual,$timestamp,$xml_path,true);
        return $this->sendToAEAT($xml_path,$object);
    }

    // Método que envía el XML a la AEAT
    public function sendToAEAT($xml_path, $object)
    {
        global $conf, $db;

        require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
        require_once DOL_DOCUMENT_ROOT . '/core/lib/fichinter.lib.php';

        dol_syslog("VERIFACTU_SEND: Iniciando envío a AEAT", LOG_DEBUG);
        verifactu_add_history($object, 'SIF_SEND_START', 'Iniciando envío a AEAT');

        // Solo enviar si el auto envío está activado
        if (empty($conf->global->VERIFACTU_AUTO_SEND)) {
            dol_syslog("VERIFACTU_SEND: Envío automático desactivado", LOG_INFO);
            return 0;
        }
        // Determinar endpoint según hash y estado de la factura anterior ---
        // Nueva lógica simplificada — se basa únicamente en el último hash generado de la serie
        $hash_prev_global = $this->getHashPrev($object);
        $is_first_or_not_sent = empty($hash_prev_global);

        if ($conf->global->VERIFACTU_MODE == 'test') {
            $base = 'https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/';
        } elseif ($conf->global->VERIFACTU_MODE == 'prod') {
            $base = 'https://www.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/';
        } else {
            dol_syslog("VERIFACTU_SEND: Modo no definido, no se envía nada", LOG_INFO);
            return 0;
        }

        // Si no hay hash previo o la factura anterior no está enviada → usar RequerimientoSOAP
        if ($is_first_or_not_sent) {
            $url = $base . 'RequerimientoSOAP';
            dol_syslog("VERIFACTU_SEND: Usando endpoint RequerimientoSOAP (primera factura o pendiente)", LOG_DEBUG);
        } else {
            $url = $base . 'VerifactuSOAP';
            dol_syslog("VERIFACTU_SEND: Usando endpoint VerifactuSOAP (cadena activa)", LOG_DEBUG);
        }

        // Rutas de certificados
        $cert_file = DOL_DATA_ROOT . '/verifactu104/certs/cert.pem';
        $key_file  = DOL_DATA_ROOT . '/verifactu104/certs/key.pem';

        if (!file_exists($xml_path)) {
            dol_syslog("VERIFACTU_SEND: XML no encontrado en $xml_path", LOG_ERR);
            return false;
        }

        $xml_data = file_get_contents($xml_path);

        // === Envío real ===
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_SSLCERT        => $cert_file,
            CURLOPT_SSLKEY         => $key_file,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $xml_data,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: ""',
                'User-Agent: VeriFactu104/1.0'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        // === Analizar resultado ===
        $facture_dir = dirname($xml_path);
        $resp_path = $facture_dir . '/acuse_verifactu.xml';


        try {
            $dom_resp = new DOMDocument('1.0', 'UTF-8');
            $dom_resp->formatOutput = true;

            if (empty($response)) {
                // Crear XML genérico si no hay respuesta o es ilegible
                $root = $dom_resp->createElement('ErrorAEAT');
                $dom_resp->appendChild($root);
                $root->appendChild($dom_resp->createElement('Codigo', $http_code));
                $root->appendChild($dom_resp->createElement('Descripcion', $curl_error ?: 'Sin respuesta o XML inválido'));
                $dom_resp->save($resp_path);
            } else {
                // Guardar XML devuelto por AEAT tal cual
                file_put_contents($resp_path, $response);
            }

            dol_syslog("VERIFACTU_SEND: Acuse XML guardado en $resp_path", LOG_DEBUG);
            verifactu_add_history($object, 'SIF_RESP_SAVED', 'Respuesta guardada');
        } catch (Exception $e) {
            dol_syslog("VERIFACTU_SEND: ERROR al guardar XML de respuesta → " . $e->getMessage(), LOG_ERR);
        }
        // === Analizar resultado ===
        $estado = 'error';
        $mensaje = "Factura NO enviada, revisa el XML generado para ver los motivos.";
        $type = 'errors';
        $retryDetected = false;

        if (!empty($response)) {
            libxml_use_internal_errors(true);
            $dom = new DOMDocument();

            if ($dom->loadXML($response)) {
                // Buscar <Resultado>
                $resultado_nodes = $dom->getElementsByTagName('Resultado');
                if ($resultado_nodes->length > 0) {
                    $resultado = strtoupper(trim($resultado_nodes->item(0)->nodeValue));

                    if ($resultado === 'OK') {
                        $estado = 'enviado';
                        $mensaje = "Factura enviada correctamente a AEAT.";
                        $type = 'mesgs';
                    } else {
                        // Error estructurado (rechazo)
                        $estado = 'rechazado';
                        $mensaje = "Factura rechazada por AEAT: " . $resultado;
                    }
                }
            }

            // Detectar RetryAfter
            $retry_nodes = $dom->getElementsByTagName('RetryAfter');
            if ($retry_nodes->length > 0) {
                $wait = (int) trim($retry_nodes->item(0)->nodeValue);
                if ($wait > 0) {
                    $retryDetected = true;
                    $estado = 'reintentar';
                    $mensaje .= " AEAT solicita reintento en {$wait} segundos.";
                    $type = 'warnings';
                }
            }
        }

        if ($estado === 'rechazado') {
            verifactu_add_history($object, 'SIF_REJECT', $mensaje);
        } elseif ($estado === 'reintentar') {
            verifactu_add_history($object, 'SIF_RETRY', $mensaje);
        } elseif ($estado === 'error') {
            verifactu_add_history($object, 'SIF_ERR', $mensaje);
        } else {
            verifactu_add_history($object, 'SIF_OK', $mensaje);
        }




        if (method_exists($object, 'updateExtraField')) {
            $object->updateExtraField('verifactu_estado', $estado);
        } else {
            $sql = "UPDATE " . MAIN_DB_PREFIX . "facture_extrafields 
                SET verifactu_estado = '" . $db->escape($estado) . "' 
                WHERE fk_object = " . (int)$object->id;
            $db->query($sql);
        }

        // === Mensaxe Dolibarr ===

        setEventMessages($mensaje, null, $type);

        dol_syslog("VERIFACTU_SEND: Envío completado (HTTP $http_code, estado=$estado). Acuse guardado en $resp_path", LOG_DEBUG);

        return ($estado == 'enviado');
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
        // === GENERAR XML VERIFACTU (usando VerifactuXMLBuilder) ===
        require_once DOL_DOCUMENT_ROOT . '/custom/verifactu104/class/VerifactuXMLBuilder.class.php';

        $ref = dol_sanitizeFileName($object->ref);
        $xml_path = $conf->facture->dir_output . "/" . $ref . "/verifactu_" . $ref . ".xml";

        // Cargar factura completa
        $facture = new Facture($this->db);
        $facture->fetch($object->id);
        $facture->fetch_thirdparty();

        // Obtener hash anterior desde extrafields
        $facture->fetch_optionals();
        $hash_prev = $facture->array_options['options_hash_prev'] ?? '';
        $hash_actual = $facture->array_options['options_hash_verifactu'] ?? '';

        // Timestamp del registro (extrafield)
        $timestamp = $facture->array_options['options_verifactu_timestamp'] ?? dol_now();

        // Construir XML
        $builder = new VerifactuXMLBuilder($this->db, $conf);
        $xml_soap = $builder->buildAltaSoapAndSave($facture, $hash_prev, $hash_actual, $timestamp, $xml_path);

        verifactu_add_history($object, 'SIF_XML', 'XML generado (builder): ' . basename($xml_path));
        dol_syslog("VERIFACTU_HOOK: XML VeriFactu SOAP generado con builder en $xml_path", LOG_DEBUG);
         /* Envío automático a AEAT si está habilitado
        if (!empty($conf->global->VERIFACTU_AUTO_SEND) && in_array($conf->global->VERIFACTU_MODE, ['test', 'prod'])) {
            $this->sendToAEAT($xml_path, $object);
        }*/

        return 0;
    }
}
// Inserta o script só na páxina de facturas
if (strpos($_SERVER["PHP_SELF"], '/compta/facture/card.php') !== false) {
    print '<script>
    jQuery(document).ready(function($) {

        // Engadimos o spinner global
        var spinner = $("<div>", {
            id: "verifactu_spinner",
            html: "<div class=\'vf-spinner\'></div><div class=\'vf-text\'>Enviando factura a Hacienda...</div>"
        }).css({
            "display": "none",
            "position": "fixed",
            "top": "0",
            "left": "0",
            "width": "100%",
            "height": "100%",
            "background": "rgba(255,255,255,0.7)",
            "z-index": "9999",
            "text-align": "center",
            "padding-top": "200px",
            "font-size": "16px",
            "color": "#333"
        });

        $("body").append(spinner);

        // Cando o usuario preme o botón de validar
        $(document).on("click", "a[href*=\"action=valid\"]", function() {
            $("#verifactu_spinner").fadeIn(200);
        });

        // Animación do círculo
        var style = "<style>\
        .vf-spinner {border: 6px solid #f3f3f3; border-top: 6px solid #3498db; border-radius: 50%; width: 50px; height: 50px; animation: spin 1s linear infinite; margin: 0 auto;}\
        @keyframes spin {0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); }}\
        </style>";
        $("head").append(style);
                // === Botones VeriFactu: Enviar / Subsanar ===
        var factureId = new URL(window.location.href).searchParams.get("id");

        var tabs = $(".tabsAction");
        if (tabs.length > 0 && factureId) {
            var btnEnviar = $("<a>", {
                class: "butAction",
                text: "Enviar a AEAT",
                href: window.location.pathname + "?id=" + factureId + "&action=verifactu_send"
            });

            var btnSubsanar = $("<a>", {
                class: "butAction",
                text: "Subsanar y reenviar",
                href: window.location.pathname + "?id=" + factureId + "&action=verifactu_subsanar"
            });

            tabs.append(btnEnviar);
            tabs.append(btnSubsanar);
        }


    });
    </script>';
}
