<?php

class VerifactuXMLBuilder
{

    public function buildRegistroAltaXML($factura, $tipoFacturaAeat)
    {
        global $conf;

        // Datos emisor
        $emisor_nif    = getDolGlobalString('MAIN_INFO_SIREN');
        $emisor_nombre = getDolGlobalString('MAIN_INFO_SOCIETE');

        // Datos receptor
        $receptor_nif = $factura->thirdparty->idprof1 ?? '';

        // Fechas
        $fecha_exp = dol_print_date($factura->date, '%Y-%m-%d');
        $fecha_operacion = !empty($factura->date_pointoftax)
            ? dol_print_date($factura->date_pointoftax, '%Y-%m-%d')
            : $fecha_exp;

        // Totales
        $base  = price2num($factura->total_ht);
        $iva   = price2num($factura->total_tva);
        $total = price2num($factura->total_ttc);

        // Descripción
        $descripcion = !empty($factura->note_public)
            ? $factura->note_public
            : 'Factura emitida mediante VeriFactu';

        // Encadenamiento
        $hash_prev   = $factura->array_options['options_hash_prev'] ?? '';
        $hash_actual = $factura->array_options['options_hash_verifactu'] ?? '';
        $timestamp   = date('c');

        // Construcción base del array d
        $d = [
            'emisor_nif'      => $emisor_nif,
            'emisor_nombre'   => $emisor_nombre,
            'receptor_nif'    => $receptor_nif,
            'ref'             => $factura->ref,
            'fecha'           => $fecha_exp,
            'fecha_operacion' => $fecha_operacion,
            'tipo_factura'    => $tipoFacturaAeat,
            'descripcion'     => $descripcion,
            'base'            => $base,
            'iva'             => $iva,
            'total'           => $total,
            'hash_prev'       => $hash_prev,
            'hash_actual'     => $hash_actual,
            'timestamp'       => $timestamp,
            'subsanacion'     => 'N'
        ];

        // Rectificativa
        if (!empty($factura->fk_facture_source)) {
            $fo = new Facture($factura->db);
            $fo->fetch($factura->fk_facture_source);
            $fo->fetch_thirdparty();

            $d['rectificativa'] = [
                'tipo'     => 'I',
                'emisor'   => $fo->thirdparty->idprof1,
                'numserie' => $fo->ref,
                'fecha'    => dol_print_date($fo->date, '%d-%m-%Y'),
            ];
        }

        // DESGLOSE usando tab_tva
        $desglose = [];
        if (!empty($factura->tab_tva) && is_array($factura->tab_tva)) {
            foreach ($factura->tab_tva as $iva_tipo => $vals) {
                $desglose[] = [
                    'clave_regimen'   => '01',
                    'calificacion'    => 'S1',
                    'tipo_impositivo' => (float)$iva_tipo,
                    'base'            => price2num($vals['total_ht']),
                    'cuota'           => price2num($vals['total_tva']),
                ];
            }
        }
        $d['desglose'] = $desglose;

        // Plantilla XML
        $xml = <<<XML
<sum1:RegistroAlta xmlns:sum1="https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd">
    <sum1:IDVersion>1.0</sum1:IDVersion>
    <sum1:IDFactura>
        <sum1:IDEmisorFactura>{$d['emisor_nif']}</sum1:IDEmisorFactura>
        <sum1:NumSerieFactura>{$d['ref']}</sum1:NumSerieFactura>
        <sum1:FechaExpedicionFactura>{$d['fecha']}</sum1:FechaExpedicionFactura>
    </sum1:IDFactura>
    <sum1:NombreRazonEmisor>{$d['emisor_nombre']}</sum1:NombreRazonEmisor>
    <sum1:Subsanacion>{$d['subsanacion']}</sum1:Subsanacion>
    <sum1:RechazoPrevio>N</sum1:RechazoPrevio>
    <sum1:TipoFactura>{$d['tipo_factura']}</sum1:TipoFactura>

    {{RECTIFICATIVA_XML}}

    <sum1:FechaOperacion>{$d['fecha_operacion']}</sum1:FechaOperacion>
    <sum1:DescripcionOperacion>{$d['descripcion']}</sum1:DescripcionOperacion>

    <sum1:Destinatarios>
        <sum1:IDDestinatario>
            <sum1:NombreRazon></sum1:NombreRazon>
            <sum1:NIF>{$d['receptor_nif']}</sum1:NIF>
        </sum1:IDDestinatario>
    </sum1:Destinatarios>

    <sum1:Desglose>
        {{DESGLOSE_XML}}
    </sum1:Desglose>

    <sum1:CuotaTotal>{$d['iva']}</sum1:CuotaTotal>
    <sum1:ImporteTotal>{$d['total']}</sum1:ImporteTotal>

    <sum1:Encadenamiento>
        <sum1:RegistroAnterior>
            <sum1:IDEmisorFactura>{$d['emisor_nif']}</sum1:IDEmisorFactura>
            <sum1:NumSerieFactura>{$d['ref']}</sum1:NumSerieFactura>
            <sum1:FechaExpedicionFactura>{$d['fecha']}</sum1:FechaExpedicionFactura>
            <sum1:Huella>{$d['hash_prev']}</sum1:Huella>
        </sum1:RegistroAnterior>
    </sum1:Encadenamiento>

    <sum1:SistemaInformatico>
        <sum1:NombreRazon>{$d['emisor_nombre']}</sum1:NombreRazon>
        <sum1:NIF>{$d['emisor_nif']}</sum1:NIF>
        <sum1:NombreSistemaInformatico>NombreSistemaInformatico</sum1:NombreSistemaInformatico>
        <sum1:IdSistemaInformatico>77</sum1:IdSistemaInformatico>
        <sum1:Version>1.0.03</sum1:Version>
        <sum1:NumeroInstalacion>383</sum1:NumeroInstalacion>
        <sum1:TipoUsoPosibleSoloVerifactu>S</sum1:TipoUsoPosibleSoloVerifactu>
        <sum1:TipoUsoPosibleMultiOT>N</sum1:TipoUsoPosibleMultiOT>
        <sum1:IndicadorMultiplesOT>N</sum1:IndicadorMultiplesOT>
    </sum1:SistemaInformatico>

    <sum1:FechaHoraHusoGenRegistro>{$d['timestamp']}</sum1:FechaHoraHusoGenRegistro>
    <sum1:TipoHuella>01</sum1:TipoHuella>
    <sum1:Huella>{$d['hash_actual']}</sum1:Huella>
</sum1:RegistroAlta>
XML;

        // Reemplazar bloque de rectificativa
        if (!empty($d['rectificativa'])) {
            $r = $d['rectificativa'];
            $rect = "
    <sum1:TipoRectificativa>{$r['tipo']}</sum1:TipoRectificativa>
    <sum1:FacturasRectificadas>
        <sum1:IDFacturaRectificada>
            <sum1:IDEmisorFactura>{$r['emisor']}</sum1:IDEmisorFactura>
            <sum1:NumSerieFactura>{$r['numserie']}</sum1:NumSerieFactura>
            <sum1:FechaExpedicionFactura>{$r['fecha']}</sum1:FechaExpedicionFactura>
        </sum1:IDFacturaRectificada>
    </sum1:FacturasRectificadas>";
        } else {
            $rect = '';
        }
        $xml = str_replace('{{RECTIFICATIVA_XML}}', $rect, $xml);

        // Reemplazar bloque de desglose
        $detalle_xml = '';
        foreach ($d['desglose'] as $item) {
            $detalle_xml .= "
        <sum1:DetalleDesglose>
            <sum1:ClaveRegimen>{$item['clave_regimen']}</sum1:ClaveRegimen>
            <sum1:CalificacionOperacion>{$item['calificacion']}</sum1:CalificacionOperacion>
            <sum1:TipoImpositivo>{$item['tipo_impositivo']}</sum1:TipoImpositivo>
            <sum1:BaseImponibleOimporteNoSujeto>{$item['base']}</sum1:BaseImponibleOimporteNoSujeto>
            <sum1:CuotaRepercutida>{$item['cuota']}</sum1:CuotaRepercutida>
        </sum1:DetalleDesglose>";
        }
        $xml = str_replace('{{DESGLOSE_XML}}', $detalle_xml, $xml);

        return $xml;
    }
}
