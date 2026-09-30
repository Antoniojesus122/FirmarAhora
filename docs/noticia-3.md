La versión 3 de FirmarAhora está dedicada a la seguridad y a la solidez de las pruebas. Recoge las mejoras que propuso el equipo de FacturaScripts en su revisión del plugin.

## Más protección para el documento

- **El código por email ahora protege el documento.** Hasta que el firmante no escribe el código, no puede ver el PDF ni firmar. El código vale para el navegador que lo verifica: si alguien más tiene el enlace, tendrá que pedir otro código.
- **Límite de códigos.** Cada invitación admite un número máximo de códigos y de intentos. Si se alcanza, basta con reenviar la invitación desde la pestaña Firmas.
- **DNI de quien debe firmar.** Al pedir una firma puedes indicar el DNI o NIF esperado: solo podrá firmar quien escriba ese mismo documento.

## Pruebas más sólidas

- **La IP no se puede falsificar.** Se guarda la dirección real de la conexión. Si tu web está detrás de un proxy o de Cloudflare, indícalo en los ajustes.
- **Si la copia sellada no se puede generar, la firma no se guarda.** Ya no puede quedar una firma sin su copia.
- **Dos firmas a la vez** ya no pueden duplicar avisos ni pedidos.
- **Las pruebas no se pierden.** Si se borra un documento firmado, la firma y su copia sellada se conservan. Si se modifica, el plugin avisa de que ya no coincide con lo que se firmó.
- **Firmas copiadas al convertir.** Cuando un presupuesto firmado pasa a pedido o factura, el nuevo documento indica «Firmó: Presupuesto…», porque eso es lo que el cliente firmó.
- **Verificación más precisa.** La página pública distingue la copia sellada del documento que se mostró antes de firmar.
- La solicitud solo pasa a «Vista» cuando el cliente abre el enlace en su navegador, no cuando lo analiza un antivirus.

## Cambio importante: «PDF con firmas»

El plugin ya no sustituye el PDF de FacturaScripts. Las firmas salen en una opción nueva del menú **Imprimir**, «PDF con firmas», que también tienes en la pestaña Firmas. El PDF normal queda como estaba, así que FirmarAhora convive con otros plugins que cambien el diseño de los documentos.

## Otros cambios

- Los enlaces de los emails usan la URL pública de los ajustes, para que los recordatorios automáticos no lleven enlaces rotos.
- Se limita el tamaño de la imagen de la firma.
- **Traducciones de verdad**: además de español e inglés, el plugin está ahora traducido al catalán, valenciano, gallego, francés, italiano, portugués y alemán, y la página de firma usa el idioma del navegador del cliente.
- Las imágenes de los contratos solo pueden ser el logotipo y la firma de la empresa elegidos en la plantilla.

Recuerda que FirmarAhora genera **firmas electrónicas simples con evidencias**: el PDF no lleva certificado digital ni sello de tiempo de un tercero. Por eso conviene dejar activado el envío de la copia al firmante.

Para actualizar, instala la nueva versión desde el panel de plugins. Tus firmas, contratos y plantillas se conservan.
