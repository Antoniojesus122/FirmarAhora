/*
 * FirmarAhora - panel de firma.
 *
 * Tres formas de firmar en el mismo lienzo:
 *  - dibujada: con ratón, dedo o lápiz; el trazo se suaviza y su grosor varía con la velocidad
 *  - escrita: el nombre tecleado, en letra manuscrita
 *  - imagen: una foto o escaneo de la firma
 *
 * Al enviar el formulario, recorta el lienzo al contenido y lo deja como png en el campo
 * oculto fa_firma, con el modo en fa_tipo. Si el lienzo está vacío, no deja enviar.
 */
(function () {
    'use strict';

    var FUENTES = '"Segoe Script", "Brush Script MT", "Lucida Handwriting", "Snell Roundhand", cursive';
    var ANCHO_MAX = 900;

    function Panel(raiz) {
        this.raiz = raiz;
        this.lienzo = raiz.querySelector('canvas');
        this.ctx = this.lienzo.getContext('2d');
        this.modo = 'dibujada';
        this.trazos = [];      // [[{x, y, t, w}]] en coordenadas css
        this.actual = null;
        this.texto = '';
        this.imagen = null;
        this.anchoCss = 0;
        this.iniciar();
    }

    Panel.prototype.iniciar = function () {
        var self = this;
        this.lienzo.style.touchAction = 'none';

        this.lienzo.addEventListener('pointerdown', function (e) {
            if (self.modo !== 'dibujada') {
                return;
            }
            e.preventDefault();
            self.lienzo.setPointerCapture(e.pointerId);
            self.actual = [self.punto(e)];
            self.trazos.push(self.actual);
            self.pintar();
        });
        this.lienzo.addEventListener('pointermove', function (e) {
            if (!self.actual) {
                return;
            }
            e.preventDefault();
            var eventos = e.getCoalescedEvents ? e.getCoalescedEvents() : [e];
            eventos.forEach(function (ev) {
                self.actual.push(self.punto(ev));
            });
            self.pintar();
        });
        ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (tipo) {
            self.lienzo.addEventListener(tipo, function () {
                self.actual = null;
            });
        });

        this.raiz.querySelectorAll('[data-fa-modo]').forEach(function (b) {
            b.addEventListener('click', function () {
                self.cambiarModo(b.getAttribute('data-fa-modo'));
            });
        });

        var borrar = this.raiz.querySelector('[data-fa-borrar]');
        if (borrar) {
            borrar.addEventListener('click', function () {
                self.borrar();
            });
        }

        var texto = this.raiz.querySelector('[data-fa-texto]');
        if (texto) {
            texto.addEventListener('input', function () {
                self.texto = texto.value;
                self.pintar();
            });
        }

        var archivo = this.raiz.querySelector('[data-fa-archivo]');
        if (archivo) {
            archivo.addEventListener('change', function () {
                self.cargarImagen(archivo.files[0]);
            });
        }

        var form = this.raiz.closest('form');
        if (form) {
            form.addEventListener('submit', function (e) {
                if (!self.alEnviar()) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                }
            });
        }

        this.ajustar();
        if (window.ResizeObserver) {
            new ResizeObserver(function () {
                self.ajustar();
            }).observe(this.lienzo);
        } else {
            window.addEventListener('resize', function () {
                self.ajustar();
            });
        }
    };

    // ajusta el bitmap al tamaño en pantalla y la densidad de píxeles, y repinta
    Panel.prototype.ajustar = function () {
        var ancho = this.lienzo.clientWidth;
        var alto = this.lienzo.clientHeight;
        if (!ancho || ancho === this.anchoCss) {
            return;
        }
        var escala = ancho / (this.anchoCss || ancho);
        this.anchoCss = ancho;

        var ratio = Math.min(Math.max(window.devicePixelRatio || 1, 1), 2);
        this.lienzo.width = Math.round(ancho * ratio);
        this.lienzo.height = Math.round(alto * ratio);
        this.ctx.setTransform(ratio, 0, 0, ratio, 0, 0);

        // los trazos se guardan en coordenadas css: se reescalan al nuevo ancho
        if (escala !== 1) {
            this.trazos.forEach(function (trazo) {
                trazo.forEach(function (p) {
                    p.x *= escala;
                    p.y *= escala;
                });
            });
        }
        this.pintar();
    };

    Panel.prototype.alEnviar = function () {
        if (this.vacio()) {
            var aviso = this.raiz.getAttribute('data-fa-vacio') || 'Firma vacía';
            if (typeof window.setToast === 'function') {
                window.setToast(aviso, 'warning');
            } else {
                window.alert(aviso);
            }
            if (typeof window.animateSpinner === 'function') {
                window.animateSpinner('remove');
            }
            return false;
        }

        this.raiz.querySelector('input[name="fa_firma"]').value = this.exportar();
        this.raiz.querySelector('input[name="fa_tipo"]').value = this.modo;
        return true;
    };

    Panel.prototype.borrar = function () {
        this.trazos = [];
        this.texto = '';
        this.imagen = null;
        var texto = this.raiz.querySelector('[data-fa-texto]');
        if (texto) {
            texto.value = '';
        }
        var archivo = this.raiz.querySelector('[data-fa-archivo]');
        if (archivo) {
            archivo.value = '';
        }
        this.pintar();
    };

    Panel.prototype.cambiarModo = function (modo) {
        this.modo = modo;
        this.raiz.querySelectorAll('[data-fa-modo]').forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-fa-modo') === modo);
        });
        this.raiz.querySelectorAll('[data-fa-solo]').forEach(function (n) {
            n.classList.toggle('d-none', n.getAttribute('data-fa-solo') !== modo);
        });
        this.lienzo.style.cursor = modo === 'dibujada' ? 'crosshair' : 'default';
        this.pintar();
    };

    Panel.prototype.cargarImagen = function (archivo) {
        var self = this;
        if (!archivo || !/^image\/(png|jpeg)$/.test(archivo.type)) {
            return;
        }
        var lector = new FileReader();
        lector.onload = function () {
            var img = new Image();
            img.onload = function () {
                self.imagen = img;
                self.pintar();
            };
            img.src = lector.result;
        };
        lector.readAsDataURL(archivo);
    };

    // recorta al contenido y devuelve un png con fondo blanco y un pequeño margen
    Panel.prototype.exportar = function () {
        var w = this.lienzo.width;
        var h = this.lienzo.height;
        var datos = this.ctx.getImageData(0, 0, w, h).data;
        var x0 = w, y0 = h, x1 = -1, y1 = -1;
        for (var y = 0; y < h; y++) {
            for (var x = 0; x < w; x++) {
                var i = (y * w + x) * 4;
                // píxel con tinta: no transparente y no blanco
                if (datos[i + 3] > 20 && (datos[i] < 235 || datos[i + 1] < 235 || datos[i + 2] < 235)) {
                    if (x < x0) { x0 = x; }
                    if (x > x1) { x1 = x; }
                    if (y < y0) { y0 = y; }
                    if (y > y1) { y1 = y; }
                }
            }
        }
        if (x1 < 0) {
            return '';
        }

        var margen = 12;
        var cw = x1 - x0 + 1;
        var ch = y1 - y0 + 1;
        var escala = Math.min(1, (ANCHO_MAX - 2 * margen) / cw);
        var salida = document.createElement('canvas');
        salida.width = Math.round(cw * escala) + 2 * margen;
        salida.height = Math.round(ch * escala) + 2 * margen;
        var sc = salida.getContext('2d');
        sc.fillStyle = '#fff';
        sc.fillRect(0, 0, salida.width, salida.height);
        sc.imageSmoothingQuality = 'high';
        sc.drawImage(this.lienzo, x0, y0, cw, ch, margen, margen, cw * escala, ch * escala);
        return salida.toDataURL('image/png');
    };

    Panel.prototype.pintar = function () {
        var ctx = this.ctx;
        var ancho = this.lienzo.clientWidth;
        var alto = this.lienzo.clientHeight;
        ctx.clearRect(0, 0, ancho, alto);

        if (this.modo === 'dibujada') {
            this.pintarTrazos();
        } else if (this.modo === 'escrita' && this.texto.trim() !== '') {
            var tam = Math.min(alto * 0.5, 64);
            ctx.font = tam + 'px ' + FUENTES;
            while (tam > 14 && ctx.measureText(this.texto).width > ancho - 30) {
                tam -= 2;
                ctx.font = tam + 'px ' + FUENTES;
            }
            ctx.fillStyle = '#1a2a5a';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(this.texto, ancho / 2, alto / 2);
        } else if (this.modo === 'imagen' && this.imagen) {
            var esc = Math.min((ancho - 20) / this.imagen.width, (alto - 20) / this.imagen.height, 1);
            var iw = this.imagen.width * esc;
            var ih = this.imagen.height * esc;
            ctx.drawImage(this.imagen, (ancho - iw) / 2, (alto - ih) / 2, iw, ih);
        }
    };

    // curvas entre los puntos medios, con grosor según la velocidad del trazo
    Panel.prototype.pintarTrazos = function () {
        var ctx = this.ctx;
        ctx.strokeStyle = '#111a3a';
        ctx.fillStyle = '#111a3a';
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';

        this.trazos.forEach(function (trazo) {
            if (trazo.length === 1) {
                ctx.beginPath();
                ctx.arc(trazo[0].x, trazo[0].y, 1.6, 0, Math.PI * 2);
                ctx.fill();
                return;
            }
            for (var i = 1; i < trazo.length; i++) {
                var a = trazo[i - 1];
                var b = trazo[i];
                var c = trazo[i + 1] || b;
                ctx.beginPath();
                ctx.lineWidth = b.w;
                ctx.moveTo((a.x + b.x) / 2, (a.y + b.y) / 2);
                ctx.quadraticCurveTo(b.x, b.y, (b.x + c.x) / 2, (b.y + c.y) / 2);
                ctx.stroke();
            }
        });
    };

    Panel.prototype.punto = function (e) {
        var r = this.lienzo.getBoundingClientRect();
        var p = {x: e.clientX - r.left, y: e.clientY - r.top, t: e.timeStamp || Date.now(), w: 2.6};
        var previo = this.actual && this.actual.length ? this.actual[this.actual.length - 1] : null;
        if (previo) {
            var dist = Math.hypot(p.x - previo.x, p.y - previo.y);
            var vel = dist / Math.max(1, p.t - previo.t);
            var objetivo = Math.max(1.2, Math.min(3.4, 3.4 - vel * 0.9));
            // suaviza el cambio de grosor para que no haya saltos
            p.w = previo.w * 0.7 + objetivo * 0.3;
        }
        return p;
    };

    Panel.prototype.vacio = function () {
        if (this.modo === 'dibujada') {
            return this.trazos.length === 0;
        }
        if (this.modo === 'escrita') {
            return this.texto.trim() === '';
        }
        return !this.imagen;
    };

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-fa-pad]').forEach(function (raiz) {
            raiz.faPanel = new Panel(raiz);
        });
    });
})();
