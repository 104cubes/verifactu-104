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
| Dolibarr ERP/CRM | **16.0 a 22.x** (recomendado 21 o 22) |
| PHP | 7.4+ |
| Extensiones PHP | `openssl`, `gd` |
| Sistema | Linux recomendado (pero funciona en Windows también) |

---

## Instalación

### 1) Descargar el módulo

Descargar ZIP desde GitHub:
