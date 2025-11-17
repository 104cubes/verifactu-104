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

    public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
    {
        if (!isModEnabled('verifactu104')) return 0;

        switch ($action) {

            case 'BILL_VALIDATE':
                dol_syslog("VERIFACTU: Generando hash para factura " . $object->ref);

                // -----------------------------
                // 1) Recuperar hash previo (última factura con hash_verifactu)
                // -----------------------------
                $serie = preg_replace('/[^A-Za-z]/', '', (string) $object->ref);

                $sqlprev = "SELECT t.hash_verifactu
                    FROM " . MAIN_DB_PREFIX . "facture_extrafields t
                    INNER JOIN " . MAIN_DB_PREFIX . "facture f ON f.rowid = t.fk_object
                    WHERE t.hash_verifactu IS NOT NULL
                    AND f.fk_statut = 1
                    AND f.ref LIKE '" . $this->db->escape($serie) . "%'
                    ORDER BY f.date_validation DESC, f.rowid DESC
                    LIMIT 1";

                $resprev   = $this->db->query($sqlprev);
                $hash_prev = "";
                if ($resprev && $objprev = $this->db->fetch_object($resprev)) {
                    $hash_prev = $objprev->hash_verifactu;
                }

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

            default:
                return 0;
        }
    }
}
