<?php

class VerifactuXMLBuilder
{
    /**
     * Construye el XML SOAP + RegistroAlta con todos los datos.
     * $d debe contener:
     *   emisor_nif
     *   emisor_nombre
     *   receptor_nif
     *   ref
     *   fecha
     *   tipo_factura   (F1, R1...)
     *   descripcion
     *   base
     *   iva
     *   total
     *   hash_prev
     *   hash_actual
     *   timestamp (en formato ISO 8601)
     */
    public function buildRegistroAltaXML(array $d)
    {
        // Sanitizar pequeños fallos (evitar nulls)
        foreach ($d as $k => $v) {
            if (!isset($v)) $d[$k] = '';
        }

        $xml = <<<XML
<sum1:RegistroAlta xmlns:sum1="https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd">
	<sum1:IDVersion>1.0</sum1:IDVersion>
	<sum1:IDFactura>
		<sum1:IDEmisorFactura>{$d['emisor_nif']}</sum1:IDEmisorFactura>
		<sum1:NumSerieFactura>{$d['ref']}</sum1:NumSerieFactura>
		<sum1:FechaExpedicionFactura>{$d['fecha']}</sum1:FechaExpedicionFactura>
	</sum1:IDFactura>
	<sum1:NombreRazonEmisor>{$d['emisor_nombre']}</sum1:NombreRazonEmisor>
	<sum1:Subsanacion>N</sum1:Subsanacion>
	<sum1:RechazoPrevio>N</sum1:RechazoPrevio>
	<sum1:TipoFactura>F1</sum1:TipoFactura>
	
	<sum1:FechaOperacion>03-02-2025</sum1:FechaOperacion>
	<sum1:DescripcionOperacion>{$d['descripcion']}</sum1:DescripcionOperacion>
	<sum1:Destinatarios>
		<sum1:IDDestinatario>
			<sum1:NombreRazon></sum1:NombreRazon>
			<sum1:NIF>{$d['receptor_nif']}</sum1:NIF>
		</sum1:IDDestinatario>
	</sum1:Destinatarios>
	<sum1:Desglose>
		<sum1:DetalleDesglose>
			<sum1:ClaveRegimen>01</sum1:ClaveRegimen>
			<sum1:CalificacionOperacion>S1</sum1:CalificacionOperacion>
			<sum1:TipoImpositivo>{$d['iva']}</sum1:TipoImpositivo>
			<sum1:BaseImponibleOimporteNoSujeto>{$d['base']}</sum1:BaseImponibleOimporteNoSujeto>
			<sum1:CuotaRepercutida>{$d['cuota']}</sum1:CuotaRepercutida>
		</sum1:DetalleDesglose>
		<sum1:DetalleDesglose>
			<sum1:ClaveRegimen>01</sum1:ClaveRegimen>
			<sum1:CalificacionOperacion>S1</sum1:CalificacionOperacion>
			<sum1:TipoImpositivo>{$d['iva']}</sum1:TipoImpositivo>
			<sum1:BaseImponibleOimporteNoSujeto>{$d['base']}</sum1:BaseImponibleOimporteNoSujeto>
			<sum1:CuotaRepercutida>{$d['cuota']}</sum1:CuotaRepercutida>
		</sum1:DetalleDesglose>
		<sum1:DetalleDesglose>
			<sum1:ClaveRegimen>05</sum1:ClaveRegimen>
			<sum1:CalificacionOperacion>S1</sum1:CalificacionOperacion>
			<sum1:TipoImpositivo>{$d['iva']}</sum1:TipoImpositivo>
			<sum1:BaseImponibleOimporteNoSujeto>{$d['base']}</sum1:BaseImponibleOimporteNoSujeto>
			<sum1:CuotaRepercutida>{$d['cuota']}</sum1:CuotaRepercutida>
		</sum1:DetalleDesglose>
	</sum1:Desglose>
	<sum1:CuotaTotal>{$d['cuota']}</sum1:CuotaTotal>
	<sum1:ImporteTotal>{$d['total']}</sum1:ImporteTotal>
	<sum1:Encadenamiento>
		<sum1:RegistroAnterior>
			<sum1:IDEmisorFactura>89890001K</sum1:IDEmisorFactura>
			<sum1:NumSerieFactura>12345677-G33</sum1:NumSerieFactura>
			<sum1:FechaExpedicionFactura>15-04-2024</sum1:FechaExpedicionFactura>
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

        return $xml;
    }
}
