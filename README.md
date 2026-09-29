# FirmarAhora

Firma electrónica para FacturaScripts, sin servicios externos: presupuestos, pedidos, albaranes, facturas y
contratos propios se firman en el momento (en la tablet o el ordenador de la oficina) o a distancia, con un
enlace que el cliente abre en cualquier dispositivo sin registrarse.

## Qué hace

- **Pestaña «Firmas»** en presupuestos, pedidos, albaranes y facturas de cliente: lista de firmantes con su
  estado, pedir una firma a distancia y firmar aquí mismo.
- **Varios firmantes por documento** (cliente, técnico, testigo…), cada uno con su enlace y su código.
- **Tres formas de firmar**: dibujando con el dedo, el ratón o un lápiz; escribiendo el nombre (en letra
  manuscrita); o subiendo una imagen de la firma.
- **Invitación por email** con un botón para firmar, **recordatorios automáticos** y **caducidad** del enlace.
- **Enviar por WhatsApp**: un botón abre WhatsApp con el mensaje y el enlace de firma ya escritos.
- **Código por email (OTP)** opcional: antes de firmar, el firmante demuestra que tiene acceso al buzón.
- **Rechazar con motivo**: el firmante puede negarse a firmar, y quien envió la solicitud recibe el aviso.
- **Copia sellada**: al firmar se guarda el PDF definitivo con una página de **certificado de evidencias**
  (firmantes, NIF, IP, navegador, método, OTP, huellas SHA-256 y registro de eventos). El firmante la recibe
  por email y la puede descargar desde el enlace.
- **Verificación pública**: con el código que figura en el PDF (FA-XXXX-XXXX), cualquiera comprueba en
  `/VerificarFirma` quién firmó y cuándo, y puede **subir el PDF** para saber si es idéntico a la copia sellada.
- **Firmas en los PDF** de los documentos, al pie y a la derecha, después de los totales.
- **Contratos desde plantillas**: editor visual, variables (`{{cliente.nombre}}`, `{{factura.total}}`…),
  logo, firma de la empresa, **envío masivo** a una lista de emails. El texto queda bloqueado en cuanto
  alguien firma.
- **Registro de auditoría** de cada solicitud: creada, enviada, abierta, código enviado o verificado,
  firmada, sellada, rechazada, anulada, caducada.
- Al **convertir** un documento (de presupuesto a pedido, albarán o factura) el nuevo recibe una copia de las firmas.
- **Presupuesto aceptado al firmar** (opcional): cuando firman todos, el presupuesto pasa solo a pedido o a factura.
- **Ubicación del firmante** (opcional): con su permiso, se añade al certificado de evidencias.
- **Idioma del firmante**: las páginas de firma y verificación se muestran en español o en inglés según su navegador.
  Los PDF firmados se generan siempre en el idioma de la empresa.

## Uso

### Firmar un documento de venta

1. Abre el presupuesto, pedido, albarán o factura y ve a la pestaña **Firmas**.
2. **Firmar aquí ahora**: escribe nombre y NIF, firma en el recuadro y pulsa *Firmar*.
3. **Pedir firma a distancia**: indica papel, nombre y email, marca si quieres pedir código por email y pulsa
   *Crear solicitud*. Se envía la invitación y el enlace queda disponible para copiarlo (WhatsApp, SMS…).

### Contratos

1. En **Ventas > Firmas > Plantillas** crea una plantilla con el texto del contrato. Pon `{{firmas}}` donde
   deban ir las firmas; la pestaña *Variables* muestra todas las variables.
2. Desde la plantilla: **Nuevo contrato** (luego eliges cliente y pides la firma en su pestaña *Firmas*) o
   **Envío masivo** (un contrato y una invitación por cada email de la lista).

### Variables de las plantillas

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

### Ajustes

*Administrador > Panel de control > FirmarAhora*: días de validez del enlace, recordatorios (cada cuántos días
y cuántos como máximo), código por email por defecto, copia sellada para el firmante, aviso al emisor, firmas en
los PDF, texto legal que acepta el firmante, qué hacer con un presupuesto firmado, pedir la ubicación e idioma del
firmante.

Los recordatorios y la caducidad necesitan el **cron** de FacturaScripts.

## Requisitos

- FacturaScripts 2026.1 o superior, PHP 8.1 o superior.
- Email configurado en FacturaScripts para enviar invitaciones, códigos y copias.
- No necesita otros plugins.

## Notas técnicas

- Tablas: `fa_solicitudes`, `fa_eventos`, `fa_plantillas`, `fa_contratos`.
- La ubicación solo se puede pedir si la web se sirve por https (lo exigen los navegadores).
- Archivos: imágenes de firma en `MyFiles/FirmarAhora/firmas/`, copias selladas en `MyFiles/FirmarAhora/sellados/`.
- Enlace de firma: `/FirmarAhora?t=<token de 48 caracteres>`. Verificación: `/VerificarFirma?c=<código>`.
- El PDF de los documentos lo amplía `Lib/Export/PDFExport`. Si otro plugin sustituye también el PDF de
  documentos (por ejemplo PlantillasPDF), las firmas no aparecerán en sus impresiones; la copia sellada sí las
  lleva siempre, porque se genera con el motor propio del plugin.
- Las firmas son **firmas electrónicas simples con evidencias** (reglamento eIDAS), no firmas cualificadas.

## Licencia

Gratuito, con licencia propietaria: se puede usar sin coste en cualquier número de instalaciones, pero no redistribuir ni vender. Consulta el archivo `LICENSE.txt`.

Autor: Antonio Jesús González Domingo · antonio.gonzalez.domingo@proton.me
