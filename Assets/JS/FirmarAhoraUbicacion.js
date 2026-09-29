/*
 * FirmarAhora - ubicación del firmante.
 *
 * Al enviar el formulario de firma pide la ubicación al navegador y la deja en los campos
 * ocultos fa_geo_*. Si el firmante no la da o el navegador no puede, se firma igual y
 * queda anotado. Se registra después del panel de firma, así que solo actúa cuando la
 * firma ya es válida.
 */
(function () {
    'use strict';

    var ESPERA_MAX = 10000;

    function campo(form, nombre) {
        return form.querySelector('input[name="' + nombre + '"]');
    }

    function reenviar(form) {
        form.dataset.faUbicado = '1';
        // fuera del evento submit en curso: dentro de él, el navegador ignora un envío nuevo
        setTimeout(function () {
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        }, 0);
    }

    function preparar(form) {
        form.addEventListener('submit', function (e) {
            if (form.dataset.faUbicado === '1' || e.defaultPrevented) {
                return;
            }
            e.preventDefault();

            var boton = form.querySelector('button[type="submit"]');
            if (boton) {
                boton.disabled = true;
            }

            var hecho = false;
            var terminar = function (estado, posicion) {
                if (hecho) {
                    return;
                }
                hecho = true;
                campo(form, 'fa_geo_estado').value = estado;
                if (posicion) {
                    campo(form, 'fa_geo_lat').value = posicion.coords.latitude;
                    campo(form, 'fa_geo_lon').value = posicion.coords.longitude;
                    campo(form, 'fa_geo_precision').value = Math.round(posicion.coords.accuracy || 0);
                }
                if (boton) {
                    boton.disabled = false;
                }
                reenviar(form);
            };

            // la geolocalización solo funciona en páginas seguras (https)
            if (!navigator.geolocation || window.isSecureContext === false) {
                terminar('no-disponible');
                return;
            }

            // si el firmante cierra el aviso de permiso sin contestar, no llega ninguna respuesta
            setTimeout(function () {
                terminar('no-disponible');
            }, ESPERA_MAX + 5000);

            navigator.geolocation.getCurrentPosition(
                function (posicion) {
                    terminar('concedida', posicion);
                },
                function (error) {
                    terminar(error && error.code === 1 ? 'denegada' : 'no-disponible');
                },
                {enableHighAccuracy: true, timeout: ESPERA_MAX, maximumAge: 60000}
            );
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-fa-ubicacion]').forEach(function (aviso) {
            var form = aviso.closest('form');
            if (form) {
                preparar(form);
            }
        });
    });
})();
