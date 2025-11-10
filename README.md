# Módulo Dolibarr VERIFACTU PARA S.L (Gratuito)
# 🇪🇸 Verifactu 104 - Módulo Dolibarr ERP/CRM (AEAT - Plan Antifraude modalidad Sociedad Limitada)

## 🌟 Descripción General

**Verifactu 104** es un módulo diseñado por 104 CUBES S.L para **Dolibarr ERP/CRM** que implementa el **Sistema de Registro Encadenado y Firma de Facturas** conforme al Real Decreto de Veri\*factu (Plan Antifraude de la AEAT - Agencia Tributaria Española).

Este módulo garantiza que tus facturas cumplan con los requisitos de trazabilidad y seguridad exigidos, añadiendo las siguientes funcionalidades clave:

* **Cálculo de Hash Encadenado:** Generación del *hash* encadenado en cada factura validada, asegurando la trazabilidad.
* **Generación de QR Verifactu:** Creación del Código QR según el formato estandarizado por la AEAT.
* **Página Adicional en el PDF:** Añade una página de información al PDF de la factura con:
    * Código QR verificable.
    * Hash de trazabilidad (`hash_verifactu` y `hash_prev`).
    * Identificación de emisor y receptor.
    * Total de la factura.
* **Compatibilidad Total:** **No modifica las plantillas PDF** de Dolibarr, siendo compatible con cualquier modelo (estándar o personalizado).
* **Ejecución Local:** No depende de servicios externos. Todo el proceso de cálculo y generación se realiza localmente en tu servidor.

## 📋 Requisitos del Sistema

Para la correcta ejecución del módulo Verifactu 104, se requieren las siguientes versiones y componentes:

| Componente | Versión | Notas |
| :--- | :--- | :--- |
| **Dolibarr ERP/CRM** | 16.0 a 22.x | Probado en versiones 20 y 22. |
| **PHP** | 7.4+ | Versión mínima recomendada. |
| **Extensiones PHP** | `openssl`, `gd` | Obligatorias para el cálculo de hash y la generación del QR. |
| **Sistema Operativo** | Linux (Recomendado) | Funciona también en entornos Windows. En cualquier caso debe ser un entorno Apache php sql|

## 🚀 Instalación y Configuración

**⚠️ Advertencia Importante:** Esta NO es una instalación estándar de Dolibarr. Requiere ciertos conocimientos, acceso al servidor (sistema de archivos) y a la base de datos (BDD).

### Paso 1: Descarga y Carga del Módulo

1.  Descarga el archivo ZIP del módulo desde GitHub.
2.  Descomprime el ZIP.
3.  Sube la carpeta descomprimida (el módulo) a la carpeta `/custom` de tu instalación de Dolibarr en el servidor.
    * *Alternativa:* Sube el archivo ZIP directamente a la carpeta `/custom` y descomprímelo allí.

### Paso 2: Modificación de la Base de Datos

Es necesario añadir dos nuevas columnas a la tabla de facturas (`TU_PREFIJO_facture`) para almacenar los *hashes* de Verifactu.

> **Localiza tu prefijo de tabla:** Reemplaza `TU_PREFIJO` por el prefijo real de tus tablas de Dolibarr (por ejemplo, `lix_`).

**Sentencia SQL a ejecutar:**

```sql
ALTER TABLE TU_PREFIJO_facture
ADD COLUMN hash_verifactu VARCHAR(255) NULL,
ADD COLUMN hash_prev VARCHAR(255) NULL;
