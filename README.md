# Módulo Dolibarr VERIFACTU PARA S.L (Gratuito)
# 🇪🇸 Verifactu 104 - Módulo Dolibarr ERP/CRM (AEAT - Plan Antifraude modalidad Sociedad Limitada)

## 🌟 Descripción General

**Verifactu 104** es un módulo diseñado por 104 CUBES S.L para **Dolibarr ERP/CRM** que implementa el **Sistema de Registro Encadenado y Firma de Facturas** conforme al Real Decreto de Veri\*factu (Plan Antifraude de la AEAT - Agencia Tributaria Española).

**Este módulo garantiza que tus facturas cumplan con los requisitos de trazabilidad y seguridad exigidos, a través de las siguientes** 
## 📋funcionalidades:

* **Cálculo de Hash Encadenado:** Generación del *hash* encadenado en cada factura validada, asegurando la trazabilidad.
* **Generación de QR Verifactu:** Creación del Código QR según el formato estandarizado por la AEAT.
* **Página Adicional en el PDF:** Añade una página de información al PDF de la factura con:
    * Código QR verificable.
    * Hash de trazabilidad (`hash_verifactu` y `hash_prev`).
    * Identificación de emisor y receptor.
    * Total de la factura.
 
* **Genera el XML según la normativa** y lo guarda en la carpeta de documentos de la factura junto al qr y el pdf

## 📋 Sin dependencias externas  
* **Compatibilidad Total dolibarr:** **No modifica las plantillas PDF** de Dolibarr, siendo compatible con cualquier modelo (estándar o personalizado).
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

**⚠️ Advertencia Importante:** Esta NO es una instalación estándar de Dolibarr. Requiere ciertos conocimientos, acceso al servidor (sistema de archivos).


1.  Descarga el archivo ZIP del módulo desde GitHub.
2.  Descomprime el ZIP.
3.  Sube la carpeta descomprimida (el módulo) a la carpeta `/custom` de tu instalación de Dolibarr en el servidor.
    * *Alternativa:* Sube el archivo ZIP directamente a la carpeta `/custom` y descomprímelo allí.
  (Probablemente se al descomprimir se llame verifactu-104-main)
4.  **Renombra** la carpeta a **verifactu104** 
  
5.  Ve a Inicio -> Configuración -> Módulos y aparecerá el módulo listopara activar
6.  Actívalo



Dime si te ha funcionado o si tienes cualquier duda puedes hacer tus comentarios en este post de Linkedin.
https://www.linkedin.com/posts/104-cubes_m%C3%B3dulo-dolibarr-verifactu-para-sl-gratuito-activity-7393888340925812736-9Kjr?utm_source=share&utm_medium=member_desktop&rcm=ACoAADOwCK8B0BKkRIDkcAvAVmsn7Ctv1Du2r5c
