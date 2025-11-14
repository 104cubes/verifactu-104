# Módulo Dolibarr — Verifactu 104 (RSIF para S.L.)
### Cumplimiento del Reglamento RSIF — Compatible con VeriFactu

## ⚠️ Aviso importante
 
**VERIFACTU NO ES OBLIGATORIO. LO OBLIGATORIO ES EL RSIF**
**Este módulo no realiza el envío automático a la AEAT. Que no es parte obligatoria del nuevo reglamento**  
**Por lo tanto NO es un SIF-VeriFactu.** 
Su propósito es cumplir con el RSIF (Reglamento de los Sistemas Informáticos de Facturación), **que es la parte obligatoria** de la nueva normativa antifraude. Para el envío inmediato a la AEAT, sin embargo el módulo está preparado para crear el método que lo haga. Te ayudamos.

---

# 📌 Descripción general

**Verifactu 104** es un módulo desarrollado por **104 CUBES S.L.** para **Dolibarr ERP/CRM**, que implementa íntegramente los requisitos técnicos del **RSIF** derivados del Real Decreto 1007/2023.

Este módulo garantiza la **integridad, trazabilidad e inalterabilidad** de cada factura, mediante:

- Hash encadenado  
- Código QR regulado  
- XML conforme al esquema RSIF (AEAT)  
- Registro de acciones  
- Bloqueo y control de modificaciones  
- Página certificada adicional en el PDF  
- Conservación de toda la evidencia digital en el directorio de cada factura  

Con esto, cualquier instalación de Dolibarr equipada con este módulo **cumple la normativa obligatoria RSIF**, independientemente de que el usuario desee activar o no la modalidad **VeriFactu (envío inmediato a la AEAT)**.

---

# 📋 Funcionalidades incluidas

### ✔ 1. Hash encadenado automático
Cada factura validada genera un hash SHA256 encadenado con la factura anterior, asegurando la trazabilidad cronológica requerida por RSIF.

### ✔ 2. Generación de Código QR oficial
El módulo genera el QR con la estructura establecida por la AEAT y lo guarda junto a la factura.

### ✔ 3. Página certificada en el PDF
Sin modificar ninguna plantilla PDF de Dolibarr, añade automáticamente una página final con:

- QR de la factura  
- Hash actual  
- Identificación del emisor  
- Resumen esencial de la factura  
- Evidencia de integridad criptográfica  

Compatible con cualquier modelo PDF, incluso personalizados.

### ✔ 4. Generación del XML RSIF completo
Cada factura genera un XML conforme al esquema oficial (`SuministroLR.xsd`).  
Se guarda en el directorio de documentos de la factura junto al PDF y el QR.

Este XML es **válido para sistemas VeriFactu** si el usuario desea implementar posteriormente una comunicación con la AEAT.

### ✔ 5. Registro de acciones RSIF
El módulo documenta eventos internos asociados a:

- Validación  
- Cálculo de hash  
- Generación de QR  
- Generación de XML  
- Cualquier operación crítica RSIF  

### ✔ 6. Control de integridad y bloqueo
Una vez generada la evidencia RSIF:

- No se permite volver la factura a borrador si ya ha sido enviada (cuando se conecte con AEAT opcionalmente).  
- No se permite modificar una factura fuera del orden cronológico. Es decir, sólo la última factura se puede modificar (Dolibarr nativo permite modificaciones).

Esto evita romper la cadena de trazabilidad.

---

# 🔌 ¿Envío a la AEAT? (VeriFactu)

NO está en este código.
Este módulo incluye un panel de configuración donde el usuario puede activar o desactivar la funcionalidad relacionada con el modo VeriFactu. Sin embargo, la parte correspondiente al envío automático a la AEAT no se publica en este repositorio, ese método tendrías que crearlo tú y hacerte rsponsable de ese desarrollo. Esto se debe a que el envío inmediato a la Agencia Tributaria convierte al software en un “SIF-VeriFactu”, sometido a un régimen sancionador específico y de esta manera garantizamos un módulo seguro y plenamente legal para cualquier instalación de Dolibarr.
Su objetivo principal es cumplir el **RSIF**, que es la parte obligatoria de la normativa.

Sin embargo:

- El XML generado **es válido** para ser enviado a la AEAT.  
- La cadena de hashes cumple con la especificación RSIF y, por tanto, es **compatible con VeriFactu**.  
- El usuario puede activar o añadir en cualquier momento un método de envío conforme a VeriFactu.  
- El módulo incorpora punto de integración pensado para esa ampliación en el archivo class/actions...php.
- Implementar el módulo te obliga a comprobar que cunmple todos los requiesitos antes de usarlo en producción.

Si deseas añadir el **envío automático** conforme al sistema VeriFactu,  
**podemos ayudarte a completar este módulo con dicha funcionalidad (sin cuotas mensuales o anuales)**.  
La base RSIF ya está implementada y preparada para conectarse con los servicios de la AEAT cuando se necesite.

---

# 📘 Cumplimiento legal

Este módulo permite al usuario cumplir:

### ✔ La obligación RSIF (obligatoria para todas las empresas)
- Registro encadenado  
- XML RSIF  
- Hash y QR  
- Inalterabilidad  
- Evidencia y trazabilidad  

### ❗ Sin convertirse en un SIF-VeriFactu
El sistema VeriFactu (envío inmediato a AEAT) es **voluntario**, no obligatorio.  
Este módulo deja esa opción en manos del usuario, pero no la activa.

---

# 🔧 Requisitos del sistema

| Componente | Versión | Comentario |
|-----------|---------|------------|
| Dolibarr ERP/CRM | 16.0 – 22.x | Probado en 20 y 22 |
| PHP | 7.4+ | Recomendado 7.4 o superior |
| Extensiones PHP | `openssl`, `gd` | Para hash y QR |
| Servidor | Linux recomendado | Compatible con Apache/PHP/SQL |

---

# 🚀 Instalación

1. Descargar el ZIP del módulo desde GitHub.  
2. Descomprimir y subir la carpeta a `/custom/`.  
3. Renombrar la carpeta a: `verifactu104`  
4. Activar el módulo desde:  
   Inicio → Configuración → Módulos/Aplicaciones  

---

# ✔ Validación manual de XML desde el portal oficial de la AEAT

Si deseas comprobar por tu cuenta que los XML generados por el módulo cumplen con el estándar RSIF, la AEAT dispone de un portal web de pruebas donde puedes **subir el XML manualmente** y obtener una validación inmediata.

Acceso al portal de pruebas (PRE–Producción):

https://preportal.aeat.es/PRE-Exteriores/Inicio/_menu_/VERI_FACTU___Sistemas_Informaticos_de_Facturacion/VERI_FACTU___Sistemas_Informaticos_de_Facturacion.html

Para acceder, necesitarás:

- Un **certificado cualificado de sello electrónico de entidad jurídica**  
  (no sirve el certificado personal, ni el de administrador único, ni el certificado FNMT de representante).
- Tener el certificado instalado en tu navegador o en tu gestor de certificados habitual.

Entra en "Cliente de servicio web".

Una vez dentro, podrás:

1. Seleccionar el XML generado por el módulo para cualquier factura.
2. Elegir el endpoint: /wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP
3. Subirlo directamente al validador de la AEAT.  
4. Ver la respuesta XML y ahí veras si supera la validación, si hay errores de formato o contenido, o si la estructura se ajusta a RSIF/VeriFactu.


Si encuentras alguna discrepancia o necesitas ayuda interpretando el resultado de la validación, puedes abrir un comentario en la sección **Issues** del repositorio o comentarlo en el post de LinkedIn que se enlaza a continuación.
---

# 📣 Comentarios y soporte

Puedes dejar tus dudas o comentarios en este post:  
https://www.linkedin.com/posts/104-cubes_m%C3%B3dulo-dolibarr-verifactu-para-sl-gratuito-activity-7393888340925812736-9Kjr
