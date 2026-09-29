/*
 * FirmarAhora - editor visual de contratos y plantillas.
 *
 * Convierte cada textarea[data-fa-editor] en un editor con barra de formato. El textarea
 * se mantiene oculto como campo del formulario y se actualiza en cada cambio y al enviar.
 */
(function () {
    'use strict';

    // lo que no debe ejecutarse al pintar el html en el editor; el servidor vuelve a
    // limpiarlo antes de mostrarlo a nadie
    function sanear(html) {
        var doc = new DOMParser().parseFromString('<body>' + html + '</body>', 'text/html');
        doc.body.querySelectorAll('script,style,iframe,object,embed,form,input,button,link,meta,svg').forEach(function (n) {
            n.remove();
        });
        doc.body.querySelectorAll('*').forEach(function (n) {
            Array.prototype.slice.call(n.attributes).forEach(function (a) {
                var valor = a.value.replace(/\s+/g, '').toLowerCase();
                if (/^on/i.test(a.name) || valor.indexOf('javascript:') === 0) {
                    n.removeAttribute(a.name);
                }
            });
        });
        return doc.body.innerHTML;
    }

    function boton(html, titulo, accion) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-light btn-sm border';
        b.title = titulo;
        b.innerHTML = html;
        // mantiene la selección del texto al pulsar
        b.addEventListener('mousedown', function (e) {
            e.preventDefault();
        });
        b.addEventListener('click', accion);
        return b;
    }

    function crear(area) {
        var cfg = {variables: [], textos: {}};
        try {
            cfg = JSON.parse(area.getAttribute('data-fa-editor')) || cfg;
        } catch (e) {
        }
        var t = cfg.textos || {};
        var soloLectura = area.hasAttribute('readonly');

        var caja = document.createElement('div');
        caja.className = 'fa-editor';
        var barra = document.createElement('div');
        barra.className = 'fa-editor-barra';
        var zona = document.createElement('div');
        zona.className = 'fa-editor-zona form-control';
        zona.contentEditable = soloLectura ? 'false' : 'true';
        zona.innerHTML = sanear(area.value);

        var modoCodigo = false;

        function volcar() {
            if (!modoCodigo) {
                area.value = zona.innerHTML;
            }
        }

        function orden(cmd, valor) {
            zona.focus();
            document.execCommand(cmd, false, valor === undefined ? null : valor);
            volcar();
        }

        var grupos = [
            [['<b>N</b>', 'Negrita', function () { orden('bold'); }],
                ['<i>C</i>', 'Cursiva', function () { orden('italic'); }],
                ['<u>S</u>', 'Subrayado', function () { orden('underline'); }]],
            [['T1', 'Título', function () { orden('formatBlock', '<h2>'); }],
                ['T2', 'Subtítulo', function () { orden('formatBlock', '<h3>'); }],
                ['P', 'Párrafo', function () { orden('formatBlock', '<p>'); }]],
            [['<i class="fa-solid fa-list-ul"></i>', 'Viñetas', function () { orden('insertUnorderedList'); }],
                ['<i class="fa-solid fa-list-ol"></i>', 'Numeración', function () { orden('insertOrderedList'); }]],
            [['<i class="fa-solid fa-align-left"></i>', 'Izquierda', function () { orden('justifyLeft'); }],
                ['<i class="fa-solid fa-align-center"></i>', 'Centrado', function () { orden('justifyCenter'); }],
                ['<i class="fa-solid fa-align-right"></i>', 'Derecha', function () { orden('justifyRight'); }],
                ['<i class="fa-solid fa-align-justify"></i>', 'Justificado', function () { orden('justifyFull'); }]],
            [['<i class="fa-solid fa-table"></i>', t.tabla || 'Tabla', function () {
                orden('insertHTML', '<table border="1" style="width:100%;border-collapse:collapse;"><tr><td>&nbsp;</td><td>&nbsp;</td></tr><tr><td>&nbsp;</td><td>&nbsp;</td></tr></table><p></p>');
            }],
                ['<i class="fa-solid fa-link"></i>', t.enlace || 'Enlace', function () {
                    var url = window.prompt(t.enlace || 'URL', 'https://');
                    if (url && /^(https?:|mailto:|tel:)/i.test(url)) {
                        orden('createLink', url);
                    }
                }],
                ['<i class="fa-solid fa-minus"></i>', 'Línea', function () { orden('insertHorizontalRule'); }],
                ['<i class="fa-solid fa-text-slash"></i>', 'Quitar formato', function () { orden('removeFormat'); }]]
        ];

        grupos.forEach(function (grupo) {
            var g = document.createElement('div');
            g.className = 'btn-group me-1 mb-1';
            grupo.forEach(function (d) {
                g.appendChild(boton(d[0], d[1], d[2]));
            });
            barra.appendChild(g);
        });

        if (cfg.variables && cfg.variables.length) {
            var sel = document.createElement('select');
            sel.className = 'form-select form-select-sm d-inline-block w-auto me-1 mb-1';
            sel.innerHTML = '<option value="">' + (t.variable || 'Variable') + '</option>';
            cfg.variables.forEach(function (v) {
                var o = document.createElement('option');
                o.value = v;
                o.textContent = v;
                sel.appendChild(o);
            });
            sel.addEventListener('change', function () {
                if (sel.value) {
                    if (modoCodigo) {
                        var p = area.selectionStart || area.value.length;
                        area.value = area.value.slice(0, p) + sel.value + area.value.slice(p);
                    } else {
                        orden('insertText', sel.value);
                    }
                }
                sel.value = '';
            });
            barra.appendChild(sel);
        }

        var bCodigo = boton('<i class="fa-solid fa-code"></i> ' + (t.codigo || 'HTML'), t.codigo || 'HTML', function () {
            modoCodigo = !modoCodigo;
            if (modoCodigo) {
                area.value = zona.innerHTML;
            } else {
                zona.innerHTML = sanear(area.value);
            }
            area.classList.toggle('d-none', !modoCodigo);
            zona.classList.toggle('d-none', modoCodigo);
            bCodigo.classList.toggle('active', modoCodigo);
        });
        bCodigo.classList.add('mb-1', 'float-end');
        barra.appendChild(bCodigo);

        area.parentNode.insertBefore(caja, area);
        if (!soloLectura) {
            caja.appendChild(barra);
        }
        caja.appendChild(zona);
        caja.appendChild(area);
        area.classList.add('d-none');

        zona.addEventListener('input', volcar);
        zona.addEventListener('blur', volcar);
        if (area.form) {
            area.form.addEventListener('submit', volcar);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('textarea[data-fa-editor]').forEach(crear);
    });
})();
