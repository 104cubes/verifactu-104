# Verifactu 104 — Módulo Verifactu para Dolibarr

**Verifactu 104** es un módulo para **Dolibarr ERP/CRM** que implementa el sistema de **registro encadenado y firma de facturas** conforme al Real Decreto de **Veri*factu** (Plan antifraude de la AEAT), añadiendo:

- Cálculo del **hash encadenado** en cada factura validada.
- Generación de **QR Verifactu** según formato AEAT.
- Creación de **página adicional en el PDF** con:
  - Código QR verificable.
  - Hash de trazabilidad.
  - Identificación de emisor y receptor.
  - Total de la factura.
- **No modifica** las plantillas PDF de Dolibarr (compatible con cualquier modelo, estándar o personalizado).
- **No depende de servicios externos**. Todo se genera localmente en el servidor.

---

## Requisitos

| Componente | Versión |
|-----------|---------|
| Dolibarr ERP/CRM | **16.0 a 22.x** (recomendado 20 a 22) |
| PHP | 7.4+ |
| Extensiones PHP | `openssl`, `gd` |
| Sistema | Linux recomendado (pero funciona en Windows también) |

---

## Instalación

### 1) Descargar el módulo

Descargar ZIP desde GitHub:

NO es una instalación estándar de dolibarr. Requiere algo de conocimiento técnico. Necesitas acceso al servidor y a la BDD

* El ZIP descargado, lo descomprimes, y subes la carpeta a tu servidor de dolibarr a la carpeta /custom (o bien subes el zip y lo descomprimes allí)
* En la base de datos debes añadir 2 columnas a la tabla TU_PREFIJO_facture (p. ej lix_fature. 
  * Añade las columnas a esa tabla: 
    	hash_verifactu	varchar(255)	NULL	
	    hash_prev	varchar(255)	NULL
  * Sentencia SQL: 
  ALTER TABLE TU_PREFIJO_facture
  ADD COLUMN ash_verifactu VARCHAR(255) NULL,
  ADD COLUMN ash_prev VARCHAR(255) NULL;
  Ejemplo con `refijop de tablas 'lix'
  ALTER TABLE lix_facture
  ADD COLUMN ash_verifactu VARCHAR(255) NULL,
  ADD COLUMN ash_prev VARCHAR(255) NULL;

Cualquier comentario o pregunta no dudees en hacérmelo a través de la página de contacto de 104cubes.com

