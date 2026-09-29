Además de los documentos de venta, puedes firmar documentos propios: contratos de mantenimiento, autorizaciones, mandatos SEPA, condiciones de servicio… Los preparas una vez como plantilla y los envías a uno o a muchos clientes.

## Crear una plantilla

1. Entra en **Ventas > Firmas**, abre la pestaña «Plantillas» y pulsa «Nuevo».
2. Ponle un nombre, por ejemplo «Contrato de mantenimiento».
3. Si quieres, elige un logotipo para la cabecera y una imagen con la firma de tu empresa.
4. Escribe el texto en «Texto del contrato». El editor tiene negrita, listas, tablas y alineación, igual que un procesador de textos.
5. Para que el texto se rellene solo con los datos de cada cliente, usa «Insertar variable». Por ejemplo, `{{cliente.nombre}}` se sustituye por el nombre del cliente.
6. Escribe `{{firmas}}` en el lugar donde deban ir las firmas, normalmente al final.
7. Guarda.

Las variables más usadas son estas. La lista completa está en la pestaña «Variables» de la plantilla.

| Variable | Se sustituye por |
|---|---|
| `{{cliente.nombre}}` | Nombre del cliente |
| `{{cliente.cifnif}}` | DNI o CIF del cliente |
| `{{cliente.direccion}}` | Dirección del cliente |
| `{{empresa.nombre}}` | Nombre de tu empresa |
| `{{empresa.cifnif}}` | CIF de tu empresa |
| `{{factura.total}}` | Total de la factura relacionada |
| `{{hoy}}` | Fecha de hoy |
| `{{firmas}}` | Las firmas de todos los firmantes |
| `{{firma_empresa}}` | La imagen con la firma de tu empresa |

## Enviar un contrato a un cliente

1. En la plantilla, pulsa «Nuevo contrato».
2. Elige el cliente y, si hace falta, la factura o el presupuesto relacionado. Guarda.
3. Revisa el resultado en la pestaña «Vista previa».
4. En la pestaña **Firmas**, pide la firma a distancia o fírmalo en el momento, igual que un documento de venta.

## Enviar un contrato a muchos clientes a la vez

1. En la plantilla, pulsa «Envío masivo».
2. Pega la lista de emails, separados por comas o uno por línea.
3. Si algún email no es de ningún cliente, marca «Crear contactos que no existan» para darlo de alta.
4. Confirma el envío.

Cada persona recibe su propio contrato con su enlace para firmar. En la pestaña «Contratos» de la plantilla ves cómo va cada uno: pendiente de firma, firmado en parte, firmado o rechazado.

**Importante:** cuando alguien firma un contrato, su texto queda bloqueado y ya no se puede modificar. Si necesitas cambiarlo, crea un contrato nuevo.

Siguiente: **Preguntas frecuentes**.
