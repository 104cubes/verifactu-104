<?php

/**
 * Generador XML VeriFactu (RegistroAlta) + envoltura SOAP
 * Plantilla inicial para altas "normales" (sin subsanación).
 */

if (!class_exists('VerifactuXMLBuilder')) {

    class VerifactuXMLBuilder
    {
        /** @var DoliDB */
        protected $db;

        /** @var Conf */
        protected $conf;

        /**
         * @param DoliDB $db
         * @param Conf   $conf
         */
        public function __construct($db, $conf)
        {
            $this->db   = $db;
            $this->conf = $conf;
        }

        /**
         * Construye el XML interno de RegistroAlta (sin SOAP).
         *
         * Esto sigue la estructura de ejemplo de RegistroAlta de la AEAT:
         * - Namespace SuministroInformacion.xsd (sum1)
         * - IDVersion, IDFactura, NombreRazonEmisor, Subsanacion, RechazoPrevio, TipoFactura, etc.
         *
         * @param Facture $facture        Factura Dolibarr
         * @param string  $hashPrev       Huella anterior encadenada (cadena vacía si no hay)
         * @param string  $hashActual     Huella actual ya calculada (SHA256 en hex mayúsculas)
         * @param int     $timestamp      Timestamp UNIX de generación del registro (dol_now())
         * @param bool    $esSubsanacion  Si es subsanación (Subsanacion=S)
         *
         * @return string XML de <sum1:RegistroAlta>...</sum1:RegistroAlta>
         */
        public function buildRegistroAlta($facture, $hashAnterior, $hashActual, $timestamp, $isSubsanacion = false)
        {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->formatOutput = true;

            // Namespaces
            $soapenv = "http://schemas.xmlsoap.org/soap/envelope/";
            $sum1    = "https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd";
            $lr      = "https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroLR.xsd";

            // Root (RegistroAlto Interno)
            $root = $dom->createElementNS($sum1, "sum1:RegistroAlta");
            $dom->appendChild($root);

            // Subsanación opcional
            if ($isSubsanacion === true) {
                $root->appendChild($dom->createElement("sum1:Subsanacion", "S"));
                $root->appendChild($dom->createElement("sum1:RechazoPrevio", "S"));
            }

            // Identificación de factura
            $idf = $dom->createElement("sum1:IDFactura");
            $root->appendChild($idf);

            $idf->appendChild($dom->createElement("sum1:NumSerieFacturaEmisor", $facture->ref));
            $fecha = dol_print_date($facture->date, '%Y-%m-%d');
            $idf->appendChild($dom->createElement("sum1:FechaExpedicionFacturaEmisor", $fecha));

            // Datos de facturación
            $df = $dom->createElement("sum1:DatosFactura");
            $root->appendChild($df);

            $df->appendChild($dom->createElement("sum1:TipoFactura", "F1"));   // De momento F1
            $df->appendChild($dom->createElement("sum1:DescripcionOperacion", $facture->note_public ?: 'Factura emitida mediante VeriFactu'));

            // Bases imponibles (simplificado)
            $bi = $dom->createElement("sum1:Importes");
            $df->appendChild($bi);

            $bi->appendChild($dom->createElement("sum1:BaseImponible", number_format($facture->total_ht, 2, '.', '')));
            $bi->appendChild($dom->createElement("sum1:CuotaIVA", number_format($facture->total_tva, 2, '.', '')));
            $bi->appendChild($dom->createElement("sum1:ImporteTotal", number_format($facture->total_ttc, 2, '.', '')));

            // Encadenamiento
            $enc = $dom->createElement("sum1:Encadenamiento");
            $root->appendChild($enc);

            $enc->appendChild($dom->createElement("sum1:TipoHuella", "01"));
            if (!empty($hashAnterior)) {
                $enc->appendChild($dom->createElement("sum1:HuellaAnterior", $hashAnterior));
            }

            // Hash actual de esta factura
            $root->appendChild($dom->createElement("sum1:TipoHuella", "01"));
            $root->appendChild($dom->createElement("sum1:Huella", $hashActual));

            // Fecha/hora
            $root->appendChild($dom->createElement("sum1:FechaHoraHuella", date('c', $timestamp)));

            return $dom->saveXML();
        }

        /**
         * Envuelve un fragmento XML (RegistroAlta / RegistroAnulacion) en el SOAP completo
         * según el WSDL de AEAT (SuministroLR + SuministroInformacion).
         *
         * @param string $registroXml  XML interno del RegistroAlta o RegistroAnulacion
         *
         * @return string XML completo SOAP listo para enviar por cURL
         */
        public function buildSoapEnvelope($registroXml)
        {
            $soapenv_ns = 'http://schemas.xmlsoap.org/soap/envelope/';
            $sum_ns     = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroLR.xsd';
            $sum1_ns    = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd';

            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->formatOutput = true;

            // Envelope
            $envelope = $dom->createElementNS($soapenv_ns, 'soapenv:Envelope');
            $dom->appendChild($envelope);

            // Declarar namespaces sum y sum1
            $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sum', $sum_ns);
            $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sum1', $sum1_ns);

            // Header vacío
            $header = $dom->createElement('soapenv:Header');
            $envelope->appendChild($header);

            // Body
            $body = $dom->createElement('soapenv:Body');
            $envelope->appendChild($body);

            // RegFactuSistemaFacturacion
            $reg = $dom->createElement('sum:RegFactuSistemaFacturacion');
            $body->appendChild($reg);

            // Cabecera
            $cabecera = $dom->createElement('sum:Cabecera');
            $reg->appendChild($cabecera);

            $cabecera->appendChild($dom->createElement('sum:IDVersion', '1.0'));

            $nif_empresa    = $this->conf->global->MAIN_INFO_SIREN
                ?: $this->conf->global->MAIN_INFO_TVAINTRA
                ?: 'B00000000';
            $nombre_empresa = $this->conf->global->MAIN_INFO_SOCIETE_NOM ?: 'Empresa Dolibarr';

            $titular = $dom->createElement('sum:Titular');
            $cabecera->appendChild($titular);
            $titular->appendChild($dom->createElement('sum:NIF', $nif_empresa));
            $titular->appendChild($dom->createElement('sum:NombreRazon', dol_escape_htmltag($nombre_empresa)));

            // TipoComunicacion A0 = Alta
            $cabecera->appendChild($dom->createElement('sum:TipoComunicacion', 'A0'));

            // RegistroFactura conteniendo el RegistroAlta / RegistroAnulacion
            $regFactura = $dom->createElement('sum:RegistroFactura');
            $reg->appendChild($regFactura);

            // Importar el fragmento interno (RegistroAlta)
            if (!empty($registroXml)) {
                $tmpDom = new DOMDocument('1.0', 'UTF-8');
                $tmpDom->loadXML($registroXml);
                $imported = $dom->importNode($tmpDom->documentElement, true);
                $regFactura->appendChild($imported);
            }

            return $dom->saveXML();
        }

        /**
         * Método de ayuda: construye el RegistroAlta normal, lo envuelve en SOAP
         * y lo guarda en disco.
         *
         * @param Facture $facture
         * @param string  $hashPrev
         * @param string  $hashActual
         * @param int     $timestamp
         * @param string  $xmlPath   Ruta completa del fichero a guardar
         *
         * @return string XML SOAP final
         */
        public function buildAltaSoapAndSave($facture, $hashPrev, $hashActual, $timestamp, $xmlPath)
        {
            $xmlRegistro = $this->buildRegistroAltaXml($facture, $hashPrev, $hashActual, $timestamp, false);
            $xmlSoap     = $this->buildSoapEnvelope($xmlRegistro);

            // Guardar en disco
            dol_mkdir(dirname($xmlPath));
            file_put_contents($xmlPath, $xmlSoap);

            return $xmlSoap;
        }
        public function buildRegistroAnulacion($facture, $hashAnterior, $timestamp)
        {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->formatOutput = true;

            $sum1 = "https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd";

            $root = $dom->createElementNS($sum1, "sum1:RegistroAnulacion");
            $dom->appendChild($root);

            $idf = $dom->createElement("sum1:IDFactura");
            $root->appendChild($idf);

            $idf->appendChild($dom->createElement("sum1:NumSerieFacturaEmisor", $facture->ref));
            $idf->appendChild($dom->createElement("sum1:FechaExpedicionFacturaEmisor", dol_print_date($facture->date, '%Y-%m-%d')));

            $root->appendChild($dom->createElement("sum1:MotivoAnulacion", "01")); // Error material

            // Encadenamiento de anulaciones
            $enc = $dom->createElement("sum1:Encadenamiento");
            $root->appendChild($enc);

            $enc->appendChild($dom->createElement("sum1:TipoHuella", "01"));
            if (!empty($hashAnterior)) {
                $enc->appendChild($dom->createElement("sum1:HuellaAnterior", $hashAnterior));
            }

            $root->appendChild($dom->createElement("sum1:TipoHuella", "01"));
            $root->appendChild($dom->createElement("sum1:Huella", sha1($facture->ref . $timestamp)));

            $root->appendChild($dom->createElement("sum1:FechaHoraHuella", date('c', $timestamp)));

            return $dom->saveXML();
        }
    }
}
