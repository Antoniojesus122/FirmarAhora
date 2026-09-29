# FirmarAhora

Firma electrónica para FacturaScripts. Los presupuestos, pedidos, albaranes, facturas y contratos se firman en el momento, en la tablet o el ordenador de la oficina, o a distancia con un enlace que el cliente abre en cualquier dispositivo sin registrarse.

## Instalación

1. Descarga el ZIP desde esta página.
2. En FacturaScripts ve a *Administrador > Plugins*, pulsa *Añadir* y sube el ZIP.
3. Activa **FirmarAhora**.

Requisitos: FacturaScripts 2026.1 o superior y PHP 8.1 o superior. No necesita otros plugins.

## Antes de empezar

- **Email**: configura el email en *Administrador > Email*. Se usa para las invitaciones, los códigos de verificación y las copias firmadas.
- **Cron**: los recordatorios y la caducidad de los enlaces necesitan el cron de FacturaScripts.
- **Ajustes**: en *Administrador > Panel de control > FirmarAhora* puedes cambiar:
  - días de validez del enlace (0 = sin caducidad);
  - cada cuántos días se envía un recordatorio y cuántos como máximo;
  - si las solicitudes nuevas piden código por email;
  - si el firmante recibe la copia sellada y si te llega un aviso cuando firma o rechaza;
  - si las firmas se imprimen en los PDF de los documentos;
  - el texto legal que acepta el firmante.

## Firmar un documento de venta

Abre el presupuesto, pedido, albarán o factura y ve a la pestaña **Firmas**. Arriba verás la lista de firmantes con su estado.

### Firmar aquí ahora

Para cuando el cliente está delante:

1. Escribe el papel (Cliente, Técnico, Testigo…), el nombre y el NIF.
2. Elige cómo firmar: **Dibujar** con el dedo, el ratón o un lápiz, **Escribir** el nombre o **Subir imagen** de la firma.
3. Pulsa **Firmar**.

### Pedir firma a distancia

1. Indica el papel, el nombre, el email y los días de validez.
2. Marca **Pedir código por email antes de firmar** si quieres que el firmante demuestre que tiene acceso a ese buzón.
3. Deja marcado **Enviar ahora la invitación por email**. Puedes cambiar el asunto y el mensaje.
4. Pulsa **Crear solicitud**.

El enlace aparece en la lista de firmantes y lo puedes copiar para mandarlo por WhatsApp o SMS. Puedes añadir tantos firmantes como necesites.

### Lo que ve el firmante

El firmante abre el enlace sin registrarse. Puede ver el PDF, verificar el código si se le pide, escribir su nombre y NIF, aceptar el texto legal y firmar. También puede **rechazar** indicando el motivo.

Al firmar se genera la **copia sellada**: el PDF final con las firmas y una página de **certificado de evidencias**. El firmante la puede descargar y, si está activado, la recibe por email.

## Copia sellada y verificación

El certificado de evidencias recoge, para cada firmante: nombre, NIF, fecha y hora, método (presencial o por enlace), si verificó el código, IP, navegador, tipo de firma y la huella SHA-256 del documento que vio. Incluye también el registro de auditoría completo.

Cada firma tiene un **código de verificación** (FA-XXXX-XXXX) que aparece en el PDF. En la página pública `/VerificarFirma`, cualquiera puede escribir el código y ver quién firmó y cuándo. También puede subir el PDF para comprobar si es idéntico a la copia sellada. El archivo subido no se guarda.

## Gestionar las solicitudes

En *Ventas > Firmas* tienes todas las solicitudes, los contratos y las plantillas. Desde la ficha de una solicitud puedes:
- reenviar la invitación;
- anular una solicitud pendiente;
- descargar la copia sellada;
- ver el registro de auditoría: creada, enviada, abierta, código enviado y verificado, firmada, rechazada, anulada o caducada.

Al convertir un presupuesto en pedido, albarán o factura, el documento nuevo recibe una copia de las firmas.

## Contratos y plantillas

1. En *Ventas > Firmas > Plantillas* crea una plantilla con el texto del contrato. El editor tiene barra de formato, tablas, un selector de variables y modo HTML.
2. Pon `{{firmas}}` donde deban ir las firmas. Opcionalmente, elige un logotipo y una imagen con la firma de la empresa.
3. Desde la plantilla puedes:
   - **Nuevo contrato**: crea un contrato; después eliges el cliente y pides la firma en su pestaña *Firmas*;
   - **Envío masivo**: pega una lista de emails y se crea un contrato con su invitación para cada uno. Los emails que no sean de ningún cliente o contacto se pueden dar de alta como contactos.

Cuando alguien firma un contrato, su texto queda bloqueado.

### Variables

| Variable | Contenido |
|---|---|
| `{{firmas}}` | Bloques de firma de todos los firmantes |
| `{{firma_empresa}}` | Imagen de firma de la empresa elegida en la plantilla |
| `{{hoy}}` | Fecha de hoy |
| `{{contrato.titulo}}`, `{{contrato.numero}}`, `{{contrato.fecha}}` | Datos del contrato |
| `{{empresa.nombre}}`, `{{empresa.cifnif}}`, `{{empresa.direccion}}`, `{{empresa.email}}`, `{{empresa.telefono}}` | Empresa |
| `{{cliente.nombre}}`, `{{cliente.razonsocial}}`, `{{cliente.cifnif}}`, `{{cliente.direccion}}`, `{{cliente.email}}`, `{{cliente.telefono}}` | Cliente |
| `{{contacto.nombre}}`, `{{contacto.cifnif}}`, `{{contacto.direccion}}`, `{{contacto.email}}`, `{{contacto.telefono}}` | Contacto |
| `{{proveedor.nombre}}`, `{{proveedor.razonsocial}}`, `{{proveedor.cifnif}}`, `{{proveedor.direccion}}` | Proveedor |
| `{{presupuesto.codigo}}`, `{{presupuesto.fecha}}`, `{{presupuesto.total}}` | Presupuesto relacionado |
| `{{factura.codigo}}`, `{{factura.fecha}}`, `{{factura.total}}` | Factura relacionada |

## Preguntas frecuentes

**¿Tienen validez legal estas firmas?**
Son firmas electrónicas simples acompañadas de evidencias, según el reglamento europeo eIDAS. Sirven como prueba en la mayoría de usos comerciales (aceptar un presupuesto, confirmar una entrega, firmar un contrato de servicios). No son firmas electrónicas cualificadas. Para actos que exijan firma cualificada, consulta a un asesor.

**No llegan los emails.**
Revisa la configuración del email de FacturaScripts. En la pestaña *Firmas* puedes copiar el enlace y enviarlo por otro medio.

**Uso otro plugin para los PDF (por ejemplo PlantillasPDF) y no salen las firmas al imprimir.**
Las firmas se añaden al PDF estándar de FacturaScripts. Si otro plugin sustituye ese PDF, las firmas no aparecerán en sus impresiones. La copia sellada sí las lleva siempre, porque la genera el propio plugin.

**¿Dónde se guardan los archivos?**
Las imágenes de firma en `MyFiles/FirmarAhora/firmas/` y las copias selladas en `MyFiles/FirmarAhora/sellados/`. Si borras un documento, se borran también sus firmas y archivos.

## Licencia

Plugin gratuito con licencia propietaria: puedes usarlo sin coste en todas tus instalaciones, pero no redistribuirlo ni venderlo. Consulta el archivo `LICENSE.txt` incluido en el plugin.
