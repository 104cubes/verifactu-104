<?php

require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/verifactu104/lib/verifactu104.lib.php';

class InterfaceVerifactu104Triggers extends DolibarrTriggers
{
    public function __construct($db)
    {
        parent::__construct($db);
        $this->family      = "billing";
        $this->description = "Triggers Verifactu104: hash encadenado y QR";
        $this->version     = self::VERSIONS['dev'];
        $this->picto       = 'verifactu104@verifactu104';
    }

    /**
     * Construye la cadena OFICIAL AEAT para la huella encadenada del Registro de Alta.
     *
     * Formato:
     *  IDEmisorFactura=...&
     *  NumSerieFactura=...&
     *  FechaExpedicionFactura=DD-MM-AAAA&
     *  TipoFactura=F1&
     *  CuotaTotal=...&
     *  ImporteTotal=...&
     *  Huella=...&
     *  FechaHoraHusoGenRegistro=YYYY-MM-DDThh:mm:ss+zz:zz
     */
    private function buildHashStringAEAT($object, $conf, $hash_prev)
    {
        if (empty($object->thirdparty)) {
            $object->fetch_thirdparty();
        }

        // Emisor
        $emisor_nif = $conf->global->MAIN_INFO_SIREN
            ?: $conf->global->MAIN_INFO_TVAINTRA
            ?: 'B00000000';

        // Nº factura (NumSerieFactura en la doc de AEAT)
        $ref_factura = !empty($object->newref) ? $object->newref : $object->ref;

        // Fecha de expedición (DD-MM-AAAA)
        // Puedes cambiar a date_validation si quieres ser más estricto:
        $fecha_exp = dol_print_date($object->date, '%d-%m-%Y');

        // Tipo de factura: simplificamos a F1 (factura completa normal)
        // En el futuro se puede mapear según $object->type
        $tipo_factura = 'F1';

        // Cuota total (IVA total)
        $cuota_total = number_format((float) $object->total_tva, 2, '.', '');

        // Importe total factura
        $importe_total = number_format((float) $object->total_ttc, 2, '.', '');

        // Huella anterior (puede ser vacía en la primera factura)
        $hash_prev = $hash_prev ?: "";

        // Fecha-hora de generación del registro en formato ISO con huso (YYYY-MM-DDThh:mm:ss+zz:zz)
        $fecha_hora_registro = date('c'); // p.ej. 2025-11-16T10:23:45+01:00

        $parts = [];
        $parts[] = 'IDEmisorFactura=' . $emisor_nif;
        $parts[] = 'NumSerieFactura=' . $ref_factura;
        $parts[] = 'FechaExpedicionFactura=' . $fecha_exp;
        $parts[] = 'TipoFactura=' . $tipo_factura;
        $parts[] = 'CuotaTotal=' . $cuota_total;
        $parts[] = 'ImporteTotal=' . $importe_total;
        $parts[] = 'Huella=' . $hash_prev;
        $parts[] = 'FechaHoraHusoGenRegistro=' . $fecha_hora_registro;

        return implode('&', $parts);
    }
    /**
     * Obtiene el último hash generado para la misma serie de la factura
     * (puede ser de un alta o de un evento registrado en actioncomm),
     * para encadenar correctamente la huella.
     */
    private function getLastHashForSerie($object)
    {
        // Serie = parte alfabética de la referencia
        $serie = preg_replace('/[^A-Za-z]/', '', (string) $object->ref);

        $records = array();

        // 1) Hash de ALTA de facturas de la misma serie
        $sql = "
            SELECT ef.options_hash_verifactu AS hash, ef.options_verifactu_timestamp AS ts
            FROM " . MAIN_DB_PREFIX . "facture_extrafields ef
            JOIN " . MAIN_DB_PREFIX . "facture f ON f.rowid = ef.fk_object
            WHERE ef.options_hash_verifactu IS NOT NULL
              AND ef.options_hash_verifactu <> ''
              AND f.ref LIKE '" . $this->db->escape($serie) . "%'
        ";

        $res = $this->db->query($sql);
        if ($res) {
            while ($obj = $this->db->fetch_object($res)) {
                $records[] = array(
                    'hash' => $obj->hash,
                    'ts'   => (int) $obj->ts,
                );
            }
        }

        // 2) Eventos VeriFactu (actioncomm_extrafields) asociados a facturas de la misma serie
        $sql2 = "
            SELECT ace.verifactu_event_hash AS hash, ace.verifactu_event_timestamp AS ts
            FROM " . MAIN_DB_PREFIX . "actioncomm ac
            JOIN " . MAIN_DB_PREFIX . "actioncomm_extrafields ace ON ac.id = ace.fk_object
            WHERE ac.elementtype = 'facture'
              AND ac.fk_element IN (
                    SELECT f2.rowid
                    FROM " . MAIN_DB_PREFIX . "facture f2
                    WHERE f2.ref LIKE '" . $this->db->escape($serie) . "%'
              )
        ";

        $res2 = $this->db->query($sql2);
        if ($res2) {
            while ($obj2 = $this->db->fetch_object($res2)) {
                if (!empty($obj2->hash)) {
                    $records[] = array(
                        'hash' => $obj2->hash,
                        'ts'   => (int) $obj2->ts,
                    );
                }
            }
        }

        if (empty($records)) {
            // No hay registros previos en la serie → primera factura / primer registro
            return '';
        }

        // Ordenar por timestamp descendente y devolver el más reciente
        usort($records, function ($a, $b) {
            if ($a['ts'] == $b['ts']) return 0;
            return ($a['ts'] < $b['ts']) ? 1 : -1;
        });

        return $records[0]['hash'];
    }
    public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
    {
        if (!isModEnabled('verifactu104')) return 0;

        switch ($action) {

            case 'BILL_VALIDATE':
                dol_syslog("VERIFACTU: Generando hash para factura " . $object->ref);

                // -----------------------------
                // 1) Recuperar hash previo (última factura con hash_verifactu)
                // -----------------------------
                                // -----------------------------
                // 1) Recuperar hash previo (último registro de la serie con huella)
                // -----------------------------
                $hash_prev = $this->getLastHashForSerie($object);

                // -----------------------------
                // 2) Cadena OFICIAL AEAT para la huella
                // -----------------------------
                $cadena = $this->buildHashStringAEAT($object, $conf, $hash_prev);

                // -----------------------------
                // 3) Hash SHA256 → HEX MAYÚSCULAS (formato oficial)
                // -----------------------------
                $hash_new = strtoupper(hash("sha256", $cadena));

                // Guardar extrafield
                $facture = new Facture($this->db);
                $facture->fetch($object->id);
                $facture->fetch_optionals();
                $facture->updateExtraField('hash_verifactu', $hash_new);
                $facture->updateExtraField('verifactu_timestamp', dol_now());
                verifactu_add_history($object, 'SIF_HASH', 'Hash generado: ' . $hash_new);
                // 4) QR — FORMATO OFICIAL AEAT (URL)
                require_once dirname(__FILE__) . '/../../lib/phpqrcode.php';

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
                if (in_array($mode, ['test', 'prod'], true)) {
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
                    . "&hash=" . urlencode($hash_new);

                // Guardar QR
                $facture_dir = $conf->facture->dir_output . "/" . $object->ref;
                dol_mkdir($facture_dir);
                $qr_file = $facture_dir . "/verifactu_qr.png";

                QRcode::png($qr_content, $qr_file, QR_ECLEVEL_M, 4);

                verifactu_add_history($object, 'SIF_QR', 'QR generado');

                return 1;

            case 'BILL_UNVALIDATE':
                // Aquí podrías reimplementar la lógica de bloqueo (última factura, estado enviado, etc.)
                // De momento, mantenemos el bloqueo duro:
                setEventMessages("No se puede pasar a borrador una factura enviada a la AEAT.", null, 'errors');
                return -1;
            case 'BILL_CANCEL':
                dol_syslog("VERIFACTU: Generando anulación para factura " . $object->ref);

                require_once DOL_DOCUMENT_ROOT . '/custom/verifactu104/class/VerifactuXMLBuilder.class.php';
                require_once DOL_DOCUMENT_ROOT . '/custom/verifactu104/class/actions_verifactu104.class.php';

                $facture = new Facture($this->db);
                $facture->fetch($object->id);
                $facture->fetch_thirdparty();
                $facture->fetch_optionals();

                // Hash previo: último registro de la serie (alta / subsanación / anulación previa)
                $hash_prev = $this->getLastHashForSerie($object);

                $builder = new VerifactuXMLBuilder($this->db, $conf);

                $timestamp = dol_now();

                // Generar XML de anulación (RegistroAnulacion)
                $xml = $builder->buildRegistroAnulacion(
                    $facture,
                    $hash_prev,
                    $timestamp
                );

                $dir = $conf->facture->dir_output . "/" . $object->ref;
                dol_mkdir($dir);
                $xml_path = $dir . "/verifactu_anulacion.xml";

                file_put_contents($xml_path, $xml);

                verifactu_add_history($object, 'SIF_XML_ANU', "XML de anulación generado: " . basename($xml_path));

                // Enviar automáticamente a la AEAT
                $actions = new ActionsVerifactu104($this->db);
                $resSend = $actions->sendToAEAT($xml_path, $facture);

                if ($resSend) {
                    $facture->updateExtraField('verifactu_estado', 'anulado_enviado');
                    verifactu_add_history($object, 'SIF_ANU_ENV', 'Anulación enviada a AEAT correctamente.');
                } else {
                    // Si falla el envío, dejamos estado solo como anulado
                    $facture->updateExtraField('verifactu_estado', 'anulado_error_envio');
                    verifactu_add_history($object, 'SIF_ANU_ERR', 'Error al enviar anulación a AEAT.');
                }

                return 1;
          

            default:
                return 0;
        }
    }
}
