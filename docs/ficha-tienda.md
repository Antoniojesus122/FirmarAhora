# FirmarAhora: borrador de la ficha para la tienda de FacturaScripts

> Borrador. La carpeta `docs/` no va dentro del ZIP del plugin.

## Título

FirmarAhora: firma electrónica de documentos y contratos

## Descripción corta

Firma presupuestos, pedidos, albaranes, facturas y contratos en el momento o a distancia, con varios
firmantes, código por email, copia sellada con certificado de evidencias y verificación pública.

## Descripción (más de 300 caracteres, con ejemplos de uso)

FirmarAhora añade firma electrónica a FacturaScripts sin depender de servicios externos ni de otros plugins.

Cada presupuesto, pedido, albarán y factura de cliente tiene una pestaña **Firmas** desde la que puedes:

- **Firmar en el momento**, en la tablet o el ordenador de la oficina: el cliente dibuja su firma con el dedo,
  la escribe o sube una imagen.
- **Pedir la firma a distancia**: el cliente recibe un email con un botón y firma desde el móvil sin registrarse.
  También puedes copiar el enlace y mandarlo por WhatsApp.
- **Añadir varios firmantes** al mismo documento: cliente, técnico, testigo…

Para dar más garantías:

- **Código por email (OTP)**, opcional en cada solicitud: sin verificarlo no se puede ver el documento ni firmar.
- **Copia sellada** en PDF con un **certificado de evidencias**: quién firmó, con qué NIF, cuándo, desde qué IP y
  navegador, si verificó el código, y la huella SHA-256 del documento que vio.
- **Página pública de verificación**: con el código que aparece en el PDF, cualquiera comprueba quién firmó y
  puede subir el PDF para saber si es idéntico al original.
- **Registro de auditoría** de cada solicitud: enviada, abierta, código verificado, firmada, rechazada…

Y para trabajar menos:

- **Contratos desde plantillas** con editor visual y variables (`{{cliente.nombre}}`, `{{factura.total}}`…).
- **Envío masivo**: un contrato para cada email de una lista, con los contactos que falten creados al momento.
- **Recordatorios automáticos** a quien no ha firmado y **caducidad** de los enlaces.
- El cliente puede **rechazar con motivo**, y te llega un aviso por email en cuanto firma o rechaza.
- Al convertir un presupuesto en pedido, albarán o factura, **las firmas se copian** al nuevo documento.
- **Envío por WhatsApp** con un botón: el mensaje y el enlace de firma ya escritos.
- **Presupuestos que se aceptan solos**: cuando firman todos, pasan a pedido o a factura (opcional).
- **Ubicación del firmante** en el certificado, con su permiso (opcional).
- **Página de firma en el idioma del cliente**: español, inglés, catalán, valenciano, gallego, francés, italiano, portugués y alemán.

### Ejemplos de uso

- **Taller o servicio técnico**: el cliente firma el albarán de entrega en la tablet al recoger el equipo, y el
  técnico firma también. El albarán impreso lleva las dos firmas al pie.
- **Presupuestos a distancia**: envías el presupuesto, el cliente lo abre en el móvil, verifica el código que le
  llega por email y lo acepta firmando. Recibes el aviso y conviertes el presupuesto en pedido con la firma incluida.
- **Contratos de mantenimiento o mandatos SEPA**: preparas una plantilla una vez y la envías a 50 clientes en un
  solo paso. Cada uno firma su copia, y ves en el listado quién ha firmado y a quién hay que recordárselo.

## Datos de la ficha

- Precio: gratis. Licencia propietaria gratuita (ver `LICENSE.txt`): uso libre, sin redistribución ni venta.
- Si el formulario de La Forja solo ofrece licencias abiertas, no elegir ninguna sin revisarlo antes.
- Compatible con FacturaScripts 2026.1 o superior, PHP 8.1 o superior.
- Requiere tener el email configurado en FacturaScripts (invitaciones, códigos y copias). Los recordatorios usan el cron.
- Aviso legal: las firmas son firmas electrónicas simples con evidencias (reglamento eIDAS), no firmas cualificadas.

## Imágenes que conviene preparar

1. La pestaña Firmas de una factura, con dos firmantes (uno firmado y otro pendiente).
2. La página de firma en el móvil.
3. El PDF de la factura con la firma al pie.
4. La página del certificado de evidencias.
5. La página pública de verificación con un PDF comprobado.
6. El editor de plantillas con el selector de variables.
