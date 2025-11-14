<?php

require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';

class InterfaceVerifactu104Triggers extends DolibarrTriggers
{
    public function __construct($db)
    {
        parent::__construct($db);
        $this->family = "billing";
        $this->description = "Triggers para Verifactu: hash encadenado y QR";
        $this->version = self::VERSIONS['dev'];
        $this->picto = 'verifactu104@verifactu104';
    }

    public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
    {
        if (!isModEnabled('verifactu104')) return 0;

        switch ($action) {
            case 'BILL_VALIDATE':
                dol_syslog("VERIFACTU: Generando hash para factura " . $object->ref . " (id=" . $object->id . ")");
                $serie = preg_replace('/[^A-Za-z]/', '', (string) $object->ref);
                // 1) Recuperar hash previo (última factura validada con hash)
                $sqlprev = "SELECT t.hash_verifactu
                FROM " . MAIN_DB_PREFIX . "facture_extrafields as t
                INNER JOIN " . MAIN_DB_PREFIX . "facture as f ON f.rowid = t.fk_object
                WHERE t.hash_verifactu IS NOT NULL
                AND f.fk_statut = 1
                AND f.ref LIKE '" . $this->db->escape($serie) . "%'
                ORDER BY f.date_validation DESC, f.rowid DESC
                LIMIT 1";
                $resprev = $this->db->query($sqlprev);
                $hash_prev = "";
                if ($resprev && $objprev = $this->db->fetch_object($resprev)) {
                    $hash_prev = $objprev->hash_verifactu;
                }

                // 2) Formato Verifactu (AEAT)
                // Cadena: NIF + fecha ISO + total con 2 decimales + hash anterior
                $emisor_nif = $conf->global->MAIN_INFO_SIREN ?: $conf->global->MAIN_INFO_TVAINTRA ?: 'B00000000';
                $fecha_iso = dol_print_date($object->date_validation, "%Y-%m-%dT%H:%M:%S");
                $total_fmt = number_format((float)$object->total_ttc, 2, '.', '');

                $cadena = $emisor_nif . "|" . $fecha_iso . "|" . $total_fmt . "|" . $hash_prev;

                // 3) Hash SHA256 binario + Base64 (formato correcto AEAT)
                $hash_new = base64_encode(hash("sha256", $cadena, true));

                // 4) Guardar hash en el extrafield
                $facture = new Facture($this->db);
                $facture->fetch($object->id);         // carga completa
                $facture->fetch_optionals();          // carga extrafields

                $facture->array_options['options_hash_verifactu'] = $hash_new;

                $res = $facture->updateExtraField('hash_verifactu', $hash_new);

                if ($res <= 0) {
                    dol_syslog("VERIFACTU ERROR: No se pudo guardar hash_verifactu para factura id=" . $object->id, LOG_ERR);
                } else {
                    dol_syslog("VERIFACTU HASH GUARDADO OK (extrafields)", LOG_INFO);
                }

                dol_syslog("VERIFACTU HASH OK: " . $hash_new, LOG_INFO);
                $object->add_action('SIF_HASH', 'Hash generado: ' . $hash_new, $user->id);
                // 5) Generar QR y guardarlo en el directorio de documentos de la factura
                require_once dirname(__FILE__) . '/../../lib/phpqrcode.php';

                // Contenido QR — ajustaremos al formato AEAT final, por ahora: trazabilidad básica
                // --- QR Formato Verifactu para S.L. ---
                // 5) NIF del receptor si existe
                $receptor_nif = "";
                if (!empty($object->thirdparty->idprof1)) {
                    $receptor_nif = $object->thirdparty->idprof1;
                } elseif (!empty($object->thirdparty->tva_intra)) {
                    $receptor_nif = $object->thirdparty->tva_intra;
                }
                $ref_factura = !empty($object->newref) ? $object->newref : $object->ref;
                // 6) Contenido QR conforme AEAT
                $qr_content = "VERIFACTU|1|"
                    . $emisor_nif . "|"
                    . $ref_factura . "|"
                    . $receptor_nif . "|"
                    . number_format((float)$object->total_ttc, 2, '.', '') . "|"
                    . $hash_new;
                // Directorio donde Dolibarr guarda los PDFs y adjuntos de la factura
                $facture_dir = $conf->facture->dir_output . "/" . $object->ref;
                dol_mkdir($facture_dir);

                // Ruta del archivo
                $qr_file = $facture_dir . "/verifactu_qr.png";

                // Generar QR
                QRcode::png($qr_content, $qr_file, QR_ECLEVEL_L, 4);
                dol_syslog("VERIFACTU QR generado en " . $qr_file);
                return 1;
            default:
                return 0;
        }
    }
}
