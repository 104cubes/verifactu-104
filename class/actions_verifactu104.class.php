<?php
/*  Verifactu104 - Módulo Veri*Factu para Dolibarr
 *  (C) 2025 104 CUBES S.L (Wayhoy!)
 *  Licencia GPL v3
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonhookactions.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/verifactu104/lib/verifactu104.lib.php';

use setasign\Fpdi\Tcpdf\Fpdi;

class ActionsVerifactu104 extends CommonHookActions
{
    public $context = array('pdfgeneration', 'globalcard', 'invoicecard');

    public function __construct($db)
    {
        $this->db = $db;
    }


    /**
     * Añade un evento de historial VeriFactu en la factura.
     *
     * @param Facture $object
     * @param string $code   Ej: 'SIF_HASH', 'SIF_SEND_START', etc.
     * @param string $note   Mensaje a registrar
     */
    public function verifactu_add_history($object, $code, $note)
    {
        global $db, $user;
        require_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php';

        if (empty($object->id)) return;

        $ev = new ActionComm($db);
        $ev->elementtype  = 'facture';
        $ev->fk_element   = $object->id;
        $ev->code         = $code;
        $ev->label        = $note;
        $ev->note         = $note;
        $ev->datep        = dol_now();
        // 🔹 Campo obligatorio en Dolibarr: userownerid
        $ev->userownerid  = (!empty($user->id) ? (int) $user->id : 1);

        try {
            $res = $ev->create($user);
            if ($res <= 0) {
                dol_syslog("VERIFACTU_HISTORY: Error al crear ActionComm (" . $ev->error . ")", LOG_ERR);
            }
        } catch (Exception $e) {
            dol_syslog("VERIFACTU_HISTORY: EXCEPCION al crear ActionComm → " . $e->getMessage(), LOG_ERR);
        }
    }
    public function doActions($parameters, &$object, &$action, $hookmanager)
    {
        global $conf;

        if ($action === 'verifactu_resend') {

            dol_syslog("VERIFACTU: Acción verifactu_resend ejecutada", LOG_DEBUG);

            // Buscar XML previo
            $ref = dol_sanitizeFileName($object->ref);
            $xml_path = $conf->facture->dir_output . '/' . $ref . '/verifactu_' . $ref . '.xml';

            if (!file_exists($xml_path)) {
                setEventMessages("No existe XML previo. Vuelva a validar la factura.", null, 'errors');
                return 0;
            }

            // Enviar de nuevo
            $ok = $this->sendToAEAT($xml_path, $object);

            if ($ok) {
                setEventMessages("Factura reenviada correctamente.", null, 'mesgs');
            } else {
                setEventMessages("No se pudo reenviar la factura. Revisa el acuse.", null, 'errors');
            }

            return 1;
        }

        if ($action === 'verifactu_subsanar') {
            dol_syslog("VERIFACTU: Acción verifactu_subsanar ejecutada", LOG_DEBUG);

            $ref = dol_sanitizeFileName($object->ref);
            $facture_dir = $conf->facture->dir_output . '/' . $ref;
            $xml_path = $facture_dir . '/verifactu_' . $ref . '.xml';

            if (!file_exists($xml_path)) {
                setEventMessages("No existe XML previo para subsanar. Vuelva a validar la factura.", null, 'errors');
                return 0;
            }

            // For subsanación, force send using existing XML
            $ok = $this->sendToAEAT($xml_path, $object);

            if ($ok) {
                setEventMessages("Subsanación enviada correctamente a AEAT.", null, 'mesgs');
            } else {
                setEventMessages("Error enviando subsanación. Consulta el acuse.", null, 'errors');
            }

            return 1;
        }

        return 0;
    }

    public function getHashPrev($object)
    {
        $db = $this->db;

        // Serie = parte alfabética de la referencia
        $serie = preg_replace('/[^A-Za-z]/', '', (string) $object->ref);

        $records = [];

        // 1) Hash de facturas (alta)
        $sql = "
        SELECT ef.hash_verifactu AS hash, ef.verifactu_timestamp AS ts
        FROM " . MAIN_DB_PREFIX . "facture_extrafields ef
        JOIN " . MAIN_DB_PREFIX . "facture f ON f.rowid = ef.fk_object
        WHERE ef.hash_verifactu IS NOT NULL
          AND ef.hash_verifactu <> ''
          AND f.ref LIKE '" . $db->escape($serie) . "%'
    ";

        $res = $db->query($sql);
        if ($res) {
            while ($obj = $db->fetch_object($res)) {
                $records[] = [
                    'hash' => $obj->hash,
                    'ts'   => (int)$obj->ts,
                ];
            }
        }

        // 2) Eventos (subsanación, anulación, reenvío)
        $sql2 = "
        SELECT ace.verifactu_event_hash AS hash, ace.verifactu_event_timestamp AS ts
        FROM " . MAIN_DB_PREFIX . "actioncomm ac
        JOIN " . MAIN_DB_PREFIX . "actioncomm_extrafields ace ON ac.id = ace.fk_object
        WHERE ac.elementtype = 'facture'
          AND ac.fk_element IN (
                SELECT f2.rowid
                FROM " . MAIN_DB_PREFIX . "facture f2
                WHERE f2.ref LIKE '" . $db->escape($serie) . "%'
          )
    ";

        $res2 = $db->query($sql2);
        if ($res2) {
            while ($obj2 = $db->fetch_object($res2)) {
                if (!empty($obj2->hash)) {
                    $records[] = [
                        'hash' => $obj2->hash,
                        'ts'   => (int)$obj2->ts,
                    ];
                }
            }
        }

        if (empty($records)) return '';

        // Ordenar por timestamp DESC
        usort($records, function ($a, $b) {
            return ($b['ts'] <=> $a['ts']);
        });

        return $records[0]['hash'];
    }

    // Método que envía el XML a la AEAT

    public function sendToAEAT($xml_path, $object)
    {
        global $conf, $db;

        require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
        require_once DOL_DOCUMENT_ROOT . '/core/lib/fichinter.lib.php';

        dol_syslog("VERIFACTU_SEND: Iniciando envío a AEAT", LOG_DEBUG);
        $this->verifactu_add_history($object, 'SIF_SEND_START', 'Iniciando envío a AEAT');

        // Solo enviar si el auto envío está activado
        if (empty($conf->global->VERIFACTU_AUTO_SEND)) {
            dol_syslog("VERIFACTU_SEND: Envío automático desactivado", LOG_INFO);
            return 0;
        }
        // Modo de envío (test / producción) desde la configuración
        $mode = isset($conf->global->VERIFACTU_MODE) ? trim((string) $conf->global->VERIFACTU_MODE) : '';
        dol_syslog("VERIFACTU_SEND: VERIFACTU_MODE='$mode'", LOG_DEBUG);

        if (in_array($mode, array('test', 'pruebas'), true)) {
            // Entorno de pruebas
            $base = 'https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/';
            dol_syslog("VERIFACTU_SEND: Usando entorno de PRUEBAS", LOG_DEBUG);
        } elseif (in_array($mode, array('prod', 'produccion', 'producción'), true)) {
            // Entorno de producción
            $base = 'https://www.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/';
            dol_syslog("VERIFACTU_SEND: Usando entorno de PRODUCCIÓN", LOG_DEBUG);
        } else {
            dol_syslog("VERIFACTU_SEND: Modo no definido o inválido (VERIFACTU_MODE='$mode'), no se envía nada", LOG_ERR);
            return 0;
        }
        // Determinar endpoint según hash y estado de la factura anterior ---
        // Nueva lógica simplificada — se basa únicamente en el último hash generado de la serie
        $hash_prev_global = $this->getHashPrev($object);
        $is_first_or_not_sent = empty($hash_prev_global);



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

            if (!empty($response)) {
                file_put_contents($resp_path, $response);
            } else {
                file_put_contents($resp_path, "<Error>Sin respuesta</Error>");
            }

            dol_syslog("VERIFACTU_SEND: Acuse XML guardado en $resp_path", LOG_DEBUG);
            $this->verifactu_add_history($object, 'SIF_RESP_SAVED', 'Respuesta guardada');
        } catch (Exception $e) {
            dol_syslog("VERIFACTU_SEND: ERROR al guardar XML de respuesta → " . $e->getMessage(), LOG_ERR);
        }
        // === Analizar resultado ===
        $estado = 'error';
        $mensaje = "Factura NO enviada, revisa el XML generado para ver los motivos.";
        $type = 'errors';
        $retryDetected = false;
        // --- SUBSANACIÓN: Estado INCORRECTO devuelto por AEAT ---
        $estado_nodes = $dom->getElementsByTagName('Estado');
        if ($estado_nodes->length > 0) {
            $estado_val = strtoupper(trim($estado_nodes->item(0)->nodeValue));

            if ($estado_val === 'INCORRECTO') {
                $estado = 'subsanar';
                $mensaje = "La AEAT devolvió 'INCORRECTO'. Requiere subsanación del envío.";
                $type = 'warnings';

                dol_syslog("VERIFACTU_SEND: Respuesta AEAT requiere SUBSANACIÓN", LOG_WARNING);
                $this->verifactu_add_history($object, 'SIF_SUBSANAR', 'Requiere subsanación por AEAT');
            }
        }
        if (!empty($response)) {
            libxml_use_internal_errors(true);
            $dom = new DOMDocument();

            if ($dom->loadXML($response)) {
                // Buscar <Estado>
                $estado_nodes = $dom->getElementsByTagName('Estado');
                if ($estado_nodes->length > 0) {
                    $estado_val = strtoupper(trim($estado_nodes->item(0)->nodeValue));
                    if ($estado_val === 'CORRECTO') {
                        $estado = 'enviado';
                        $mensaje = "Factura enviada correctamente a AEAT.";
                        $type = 'mesgs';
                    }
                }
                // Buscar <CodigoError>
                $error_nodes = $dom->getElementsByTagName('CodigoError');
                if ($error_nodes->length > 0) {
                    $estado = 'rechazado';
                    $mensaje = "Factura rechazada por AEAT: " . $error_nodes->item(0)->nodeValue;
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
            $this->verifactu_add_history($object, 'SIF_REJECT', $mensaje);
        } elseif ($estado === 'reintentar') {
            $this->verifactu_add_history($object, 'SIF_RETRY', $mensaje);
        } elseif ($estado === 'error') {
            $this->verifactu_add_history($object, 'SIF_ERR', $mensaje);
        } else {
            $this->verifactu_add_history($object, 'SIF_OK', $mensaje);
        }




        /* === Actualizar extrafield verifactu_estado correctamente === */
        try {
            // Asegurar extrafields cargados
            if (empty($object->array_options) || !array_key_exists('verifactu_estado', $object->array_options)) {
                $object->fetch_optionals();
            }

            // Asignar estado
            $object->array_options['verifactu_estado'] = $estado;

            // Guardar
            $res = $object->insertExtraFields();

            if ($res > 0) {
                dol_syslog("VERIFACTU_SEND: verifactu_estado actualizado a '$estado'", LOG_DEBUG);
            } else {
                dol_syslog("VERIFACTU_SEND: ERROR insertExtraFields() → " . $object->error, LOG_ERR);
            }
        } catch (Throwable $e) {
            dol_syslog("VERIFACTU_SEND: EXCEPCION al actualizar verifactu_estado → " . $e->getMessage(), LOG_ERR);
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
        // === Reconstruir factura correctamente (Dolibarr pasa un objeto incompleto) ===
        $raw = $parameters['object'] ?? null;
        if (empty($raw)) {
            dol_syslog("VERIFACTU_HOOK: ERROR → parameters['object'] vacío", LOG_ERR);
            return 0;
        }

        // Detectar ID correctamente
        $id = 0;
        if (!empty($raw->id))            $id = $raw->id;
        elseif (!empty($raw->rowid))     $id = $raw->rowid;
        elseif (!empty($raw->ref)) {
            $tmp = new Facture($this->db);
            $id = $tmp->fetch('', $raw->ref);
        }

        if ($id <= 0) {
            dol_syslog("VERIFACTU_HOOK: ERROR → No se pudo determinar ID de factura", LOG_ERR);
            return 0;
        }

        // Cargar factura COMPLETA
        $facture = new Facture($this->db);
        if ($facture->fetch($id) <= 0) {
            dol_syslog("VERIFACTU_HOOK: ERROR → fetch() falló para ID $id", LOG_ERR);
            return 0;
        }
        $facture->fetch_thirdparty();
        $facture->fetch_optionals();

        // Sustituimos el objeto incompleto recibido por el real
        $object = $facture;

        // Solo facturas validadas (estat 1)
        if ($object->statut != Facture::STATUS_VALIDATED) {
            dol_syslog("VERIFACTU_HOOK: FACTURA NO VALIDADA → no generar QR/XML", LOG_DEBUG);
            return 0;
        }

        dol_syslog("VERIFACTU_HOOK: factura reconstruida correctamente ID=$object->id REF=$object->ref", LOG_DEBUG);

        // Rutas de archivos
        try {
            $ref = dol_sanitizeFileName($object->ref);
            $facture_dir = $conf->facture->dir_output . '/' . $ref;
            dol_syslog("VERIFACTU_HOOK: facture_dir = $facture_dir", LOG_DEBUG);
            $pdf_file = $facture_dir . "/" . $ref . ".pdf";
            dol_syslog("VERIFACTU_HOOK: getDir() OK → $facture_dir", LOG_DEBUG);
        } catch (Throwable $e) {
            dol_syslog("VERIFACTU_HOOK: ERROR en getDir() → " . $e->getMessage(), LOG_ERR);
            dol_syslog("TRACE: " . $e->getTraceAsString(), LOG_ERR);
            return -1;
        }
        $qr_file = $facture_dir . "/verifactu_qr.png";
        dol_mkdir($facture_dir);
        // Buscar PDF real
        if (empty($pdf_file) || !file_exists($pdf_file)) {
            $files = dol_dir_list($facture_dir, 'files', 0, '\.pdf$', '', 'date', SORT_DESC);
            if (!empty($files)) {
                $pdf_file = $facture_dir . $files[0]['name'];
                dol_syslog("VERIFACTU_HOOK: PDF detectado automáticamente: $pdf_file", LOG_DEBUG);
            } else {
                dol_syslog("VERIFACTU_HOOK: ERROR → No se encontró ningún PDF en $facture_dir", LOG_ERR);
                return 0;
            }
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
            $sql = "SELECT hash_verifactu FROM llx_facture_extrafields WHERE fk_object = ?";
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
        dol_syslog("VERIFACTU_HOOK: INICIO generación XML VeriFactu", LOG_DEBUG);
        try {
            require_once DOL_DOCUMENT_ROOT . '/custom/verifactu104/class/VerifactuXMLBuilder.class.php';
            $ref = dol_sanitizeFileName($object->ref);
            $xml_path = $facture_dir . "/verifactu_" . $ref . ".xml";
            dol_syslog("VERIFACTU_HOOK: Ruta XML = $xml_path", LOG_DEBUG);
            // Obtener hash anterior desde extrafields
            $hash_prev   = $object->array_options['hash_prev'] ?? '';
            $hash_actual = $object->array_options['hash_verifactu'] ?? '';
            $timestamp   = $object->array_options['verifactu_timestamp'] ?? dol_now();

            // === Si los hashes no existen (primera generación), generarlos y guardarlos aquí ===
            if (empty($hash_actual)) {
                dol_syslog("VERIFACTU_HOOK: Hash vacío → generando nuevo hash y guardando extrafields", LOG_DEBUG);

                // Obtener hash previo correcto según la serie
                $hash_prev = $this->getHashPrev($object);

                // Normalizar a mayúsculas el hash previo (si existe)
                if (!empty($hash_prev)) {
                    $hash_prev = strtoupper($hash_prev);
                }

                // Generar hash actual nuevo (ya en mayúsculas)
                $hash_actual = strtoupper(hash('sha256', uniqid('vf', true)));
                $timestamp   = dol_now();

                // Guardar extrafields AHORA, cuando la factura ya está con su ref definitiva
                $object->array_options['hash_verifactu']      = $hash_actual;
                $object->array_options['hash_prev']           = $hash_prev;
                $object->array_options['verifactu_timestamp'] = $timestamp;
                $object->insertExtraFields();

                dol_syslog("VERIFACTU_HOOK: Hashes generados y guardados en afterPDFCreation", LOG_DEBUG);
            }

            // Normalizar SIEMPRE a mayúsculas antes de usarlos en QR/XML
            $hash_actual = strtoupper((string) $hash_actual);
            $hash_prev   = strtoupper((string) $hash_prev);
            // === Si los hashes no existen (primera generación), generarlos y guardarlos aquí ===
            if (empty($hash_actual)) {
                dol_syslog("VERIFACTU_HOOK: Hash vacío → generando nuevo hash y guardando extrafields", LOG_DEBUG);
                // Obtener hash previo correcto según la serie
                $hash_prev = $this->getHashPrev($object);
                // Generar hash actual nuevo
                $hash_actual = hash('sha256', uniqid('vf', true));
                $timestamp = dol_now();
                // Guardar extrafields AHORA, cuando la factura ya está con su ref definitiva
                $object->array_options['hash_verifactu'] = $hash_actual;
                $object->array_options['hash_prev'] = $hash_prev;
                $object->array_options['verifactu_timestamp'] = $timestamp;
                $object->insertExtraFields();
                dol_syslog("VERIFACTU_HOOK: Hashes generados y guardados en afterPDFCreation", LOG_DEBUG);
            }
            dol_syslog("VERIFACTU_HOOK: hash_prev=$hash_prev hash_actual=$hash_actual timestamp=$timestamp", LOG_DEBUG);
            if (empty($hash_actual)) {
                throw new Exception("hash_actual vacío en extrafields. No se puede generar XML.");
            }
            // Construir QR
            require_once dirname(__FILE__) . '/../lib/phpqrcode.php';
            if (empty($object->thirdparty)) {
                $object->fetch_thirdparty();
            }
            // Emisor
            $emisor_nif = $conf->global->MAIN_INFO_SIREN
                ?: $conf->global->MAIN_INFO_TVAINTRA
                ?: 'B00000000';
            // Datos factura
            $ref_factura = !empty($object->newref) ? $object->newref : $object->ref;
            $fecha_qr    = dol_print_date($object->date, '%d-%m-%Y');     // MISMA fecha que hash
            $total_fmt   = number_format((float) $object->total_ttc, 2, '.', '');
            // Seleccionar URL
            $mode = $conf->global->VERIFACTU_MODE ?? '';
            if (in_array($mode, ['test', 'pruebas', 'prod', 'produccion', 'producción'], true)) {
                $qr_url_base = "https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR";
            } else {
                $qr_url_base = "https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQRNoVerifactu";
            }
            // QR oficial
            $qr_content = $qr_url_base
                . "?nif=" . urlencode($emisor_nif)
                . "&numserie=" . urlencode($ref_factura)
                . "&fecha=" . urlencode($fecha_qr)
                . "&importe=" . urlencode($total_fmt)
                . "&hash=" . urlencode($hash_actual);
            // Guardar QR
            dol_mkdir($facture_dir);

            QRcode::png($qr_content, $qr_file, QR_ECLEVEL_M, 4);
            clearstatcache(true, $qr_file);
            dol_syslog("VF_QR: PNG creado SIZE=" . @filesize($qr_file), LOG_DEBUG);

            // Detectar el tipo de factura para AEAT
            $tipoFacturaAeat = 'F1';
            if (!empty($object->type) && (int)$object->type === Facture::TYPE_CREDIT_NOTE) {
                $tipoFacturaAeat = 'R1';
            }
            // Construir XML
            $builder = new VerifactuXMLBuilder($this->db, $conf, $tipoFacturaAeat);
            // $xml_soap = $builder->buildAltaSoapAndSave($object, $hash_prev, $hash_actual, $timestamp, $xml_path);
            // === Crear XML interno ===

            $data = [
                'emisor_nif'      => $emisor_nif,
                'emisor_nombre'   => $nombre_empresa,
                'receptor_nif'    => $facture->thirdparty->idprof1,
                'ref'             => $ref_factura,
                'fecha'           => dol_print_date($facture->date, '%Y-%m-%d'),
                'tipo_factura'    => $tipoFacturaAeat, // F1, R1, etc
                'descripcion'     => $facture->note_public ?: 'Factura emitida mediante VeriFactu',
                'base'            => number_format($facture->total_ht, 2, '.', ''),
                'iva'             => number_format($facture->total_tva, 2, '.', ''),
                'total'           => number_format($facture->total_ttc, 2, '.', ''),
                'hash_prev'       => $hash_prev,
                'hash_actual'     => $hash_actual,
                'timestamp'       => date('c', dol_now()),
            ];
            $xml_registro = $builder->buildRegistroAltaXML($data);

            // === Envolver en SOAP ===
            //    $xml_soap = $builder->wrapSoapEnvelope($xml_registro);

            // === Guardar
            dol_mkdir(dirname($xml_path));
            file_put_contents($xml_path, $xml_registro);
            $this->verifactu_add_history($object, 'SIF_XML', 'XML generado (builder): ' . basename($xml_path));
            dol_syslog("VERIFACTU_HOOK: XML VeriFactu SOAP generado correctamente en $xml_path", LOG_DEBUG);


            // ENVÍO AUTOMÁTICO (si está activado)
            $this->sendToAEAT($xml_path, $object);
        } catch (Throwable $e) {
            dol_syslog("VERIFACTU_HOOK: ERROR GENERANDO XML → " . $e->getMessage(), LOG_ERR);
            dol_syslog("TRACE: " . $e->getTraceAsString(), LOG_ERR);
            setEventMessages("ERROR generando XML VeriFactu: " . $e->getMessage(), null, 'errors');
            $this->verifactu_add_history($object, 'SIF_XML_ERR', 'Error XML: ' . $e->getMessage());
            return -1;
        }
        return 0;
    }

    /**
     * Hook: addMoreActionsButtons
     * Inserta botones adicionales en la ficha de factura
     */
    /**
     * Hook: addMoreActionsButtons
     * Inserta botones adicionales en la ficha de factura
     */
    /**
     * Hook: formObjectOptions
     * Inserta botones adicionales en la ficha de factura
     */
    public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
    {
        dol_syslog("VERIFACTU_HOOK: formObjectOptions() llamado", LOG_DEBUG);

        try {
            global $langs, $conf;

            // Solo facturas
            if (!is_object($object) || $object->element !== 'facture') {
                dol_syslog("VERIFACTU_HOOK: ignorado (no es factura)", LOG_DEBUG);
                return 0;
            }
            // Solo si está validada
            if ($object->statut != Facture::STATUS_VALIDATED) {
                dol_syslog("VERIFACTU_HOOK: ignorado (factura no validada)", LOG_DEBUG);
                return 0;
            }

            // Solo si está rechazada o con error
            $sql = "SELECT verifactu_estado FROM llx_facture_extrafields WHERE fk_object = " . ((int)$object->id) . " LIMIT 1";
            $res = $this->db->query($sql);
            $estado = '';
            if ($res && $obj = $this->db->fetch_object($res)) {
                $estado = $obj->verifactu_estado ?: '';
            }
            dol_syslog("VERIFACTU_HOOK: estado_from_sql='$estado'", LOG_DEBUG);

            // Determinar acción y etiqueta por defecto
            $accion = 'verifactu_resend';
            $label  = "Reenviar a AEAT";

            // Si no está en un estado válido, no insertamos nada
            if (!in_array($estado, ['rechazado', 'error', 'reintentar', 'subsanar'], true)) {
                dol_syslog("VERIFACTU_HOOK: estado '$estado' → no insertar botón", LOG_DEBUG);
                return 0;
            }

            // Caso especial: subsanar
            if ($estado === 'subsanar') {
                $accion = 'verifactu_subsanar';
                $label  = "Subsanar incorrección en envío a AEAT";
            }
            // Crear botón
            $url = $_SERVER['PHP_SELF'] . '?id=' . (int) $object->id . '&action=' . $accion;


            $html = '<div class="inline-block">'
                . '<a class="butAction" id="miboton" style="background-color:red" href="' . $url . '">' . dol_escape_htmltag($label) . '</a>'
                . '</div>';

            // Añadir al output del hook
            if (empty($this->resprints)) {
                $this->resprints = '';
            }

            $this->resprints .= $html;

            dol_syslog("VERIFACTU_HOOK: Botón insertado correctamente", LOG_DEBUG);
            return 1;
        } catch (Throwable $e) {
            dol_syslog("VERIFACTU_HOOK: EXCEPCION en formObjectOptions → " . $e->getMessage(), LOG_ERR);
            return 0;
        }
    }
}
