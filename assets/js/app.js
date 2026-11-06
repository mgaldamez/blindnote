/**
 * BlindNote - JavaScript Interactivo (Vanilla JS, CSP 'self' compliant)
 *
 * Incluye:
 * 1. Menu lateral colapsable y panel responsive con persistencia.
 * 2. Modo Oscuro / Modo Claro Toggle con persistencia en localStorage.
 * 3. Tablero Kanban interactivo con Arrastrar y Soltar (HTML5 Drag & Drop API).
 * 4. Fijado de notas prioritarias al inicio (Pin to Top) con AJAX reactivo.
 * 5. Medidor interactivo de fuerza de contrasena en tiempo real.
 * 6. Copiado al portapapeles con un clic y sistema de notificaciones Toast.
 * 7. Interacciones para graficas y sugerencias de avatar.
 */
(function () {
    'use strict';

    /* =========================================================================
     *  1. MENU LATERAL Y RESPONSIVE (Existente mejorado)
     * ========================================================================= */
    var cuerpo = document.body;
    var botonNav = document.querySelector('[data-nav-toggle]');
    var CLAVE_NAV = 'blindnote-sidebar-colapsado';
    var chico = window.matchMedia('(max-width: 900px)');

    function esChico() {
        return chico.matches;
    }

    // Recuerda la eleccion hecha en escritorio.
    try {
        if (window.localStorage.getItem(CLAVE_NAV) === '1') {
            cuerpo.classList.add('nav-colapsado');
        }
    } catch (e) {
        // Modo privado o almacenamiento bloqueado: se ignora la preferencia.
    }

    function sincronizarAria() {
        if (!botonNav) return;
        var abierto = esChico()
            ? cuerpo.classList.contains('nav-abierto')
            : !cuerpo.classList.contains('nav-colapsado');
        botonNav.setAttribute('aria-expanded', abierto ? 'true' : 'false');
    }

    if (botonNav) {
        botonNav.addEventListener('click', function () {
            if (esChico()) {
                cuerpo.classList.toggle('nav-abierto');
            } else {
                var colapsado = cuerpo.classList.toggle('nav-colapsado');
                try {
                    window.localStorage.setItem(CLAVE_NAV, colapsado ? '1' : '0');
                } catch (e) {}
            }
            sincronizarAria();
        });
    }

    var velo = document.querySelector('[data-nav-cerrar]');
    if (velo) {
        velo.addEventListener('click', function () {
            cuerpo.classList.remove('nav-abierto');
            sincronizarAria();
        });
    }

    var enlacesSidebar = document.querySelectorAll('.sidebar a');
    Array.prototype.forEach.call(enlacesSidebar, function (enlace) {
        enlace.addEventListener('click', function () {
            if (esChico()) {
                cuerpo.classList.remove('nav-abierto');
                sincronizarAria();
            }
        });
    });

    chico.addEventListener('change', function () {
        cuerpo.classList.remove('nav-abierto');
        sincronizarAria();
    });

    var topbar = document.querySelector('.topbar');
    function ajustarAltoTopbar() {
        if (!topbar) return;
        document.documentElement.style.setProperty('--alto-topbar', topbar.offsetHeight + 'px');
    }
    ajustarAltoTopbar();
    window.addEventListener('resize', ajustarAltoTopbar);
    window.addEventListener('load', ajustarAltoTopbar);
    sincronizarAria();

    /* =========================================================================
     *  2. MODO OSCURO / MODO CLARO (Dark / Light Theme Toggle)
     * ========================================================================= */
    var CLAVE_TEMA = 'blindnote-tema';
    var botonTema = document.querySelector('[data-tema-toggle]');

    function obtenerTemaActual() {
        try {
            var guardado = window.localStorage.getItem(CLAVE_TEMA);
            if (guardado === 'dark' || guardado === 'light') {
                return guardado;
            }
        } catch (e) {}
        return (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
    }

    function aplicarTema(tema) {
        if (tema === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
        } else {
            document.documentElement.removeAttribute('data-theme');
        }
        try {
            window.localStorage.setItem(CLAVE_TEMA, tema);
        } catch (e) {}
    }

    // Aplicar inmediatamente al cargar
    aplicarTema(obtenerTemaActual());

    if (botonTema) {
        botonTema.addEventListener('click', function () {
            var actual = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
            var nuevo = (actual === 'dark') ? 'light' : 'dark';
            aplicarTema(nuevo);
            mostrarToast('Tema cambiado a ' + (nuevo === 'dark' ? 'Modo Oscuro' : 'Modo Claro'), 'info');
        });
    }

    /* =========================================================================
     *  3. SISTEMA DE NOTIFICACIONES TOAST (Visual Feedbacks)
     * ========================================================================= */
    var contenedorToast = null;

    function asegurarContenedorToast() {
        if (!contenedorToast) {
            contenedorToast = document.querySelector('.toast-contenedor');
            if (!contenedorToast) {
                contenedorToast = document.createElement('div');
                contenedorToast.className = 'toast-contenedor';
                document.body.appendChild(contenedorToast);
            }
        }
        return contenedorToast;
    }

    function mostrarToast(mensaje, tipo) {
        tipo = tipo || 'ok';
        var cont = asegurarContenedorToast();
        var toast = document.createElement('div');
        toast.className = 'toast toast--' + tipo;

        var iconos = {
            'ok': '&#10003;',
            'error': '&#10007;',
            'info': '&#128161;'
        };

        toast.innerHTML = '<span class="toast__icono">' + (iconos[tipo] || '&#8505;') + '</span>'
            + '<span class="toast__texto">' + mensaje + '</span>';

        cont.appendChild(toast);

        // Forzar reflow para transicion suave
        toast.offsetHeight;
        toast.classList.add('toast--visible');

        setTimeout(function () {
            toast.classList.remove('toast--visible');
            setTimeout(function () {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }, 2800);
    }

    window.blindnoteToast = mostrarToast;

    /* =========================================================================
     *  4. COPIAR AL PORTAPAPELES CON UN CLIC
     * ========================================================================= */
    document.addEventListener('click', function (evento) {
        var btn = evento.target.closest('[data-copiar-nota]');
        if (!btn) return;

        evento.preventDefault();
        var texto = btn.getAttribute('data-copiar-nota') || '';
        if (!texto) return;

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(texto).then(function () {
                animarBotonCopiado(btn);
                mostrarToast('Contenido copiado al portapapeles', 'ok');
            }).catch(function () {
                copiarFallback(texto, btn);
            });
        } else {
            copiarFallback(texto, btn);
        }
    });

    function copiarFallback(texto, btn) {
        try {
            var inputTemp = document.createElement('textarea');
            inputTemp.value = texto;
            inputTemp.style.position = 'fixed';
            inputTemp.style.opacity = '0';
            document.body.appendChild(inputTemp);
            inputTemp.focus();
            inputTemp.select();
            var exito = document.execCommand('copy');
            document.body.removeChild(inputTemp);
            if (exito) {
                animarBotonCopiado(btn);
                mostrarToast('Contenido copiado al portapapeles', 'ok');
            } else {
                mostrarToast('No se pudo copiar automaticamente', 'error');
            }
        } catch (err) {
            mostrarToast('Error al copiar al portapapeles', 'error');
        }
    }

    function animarBotonCopiado(btn) {
        var textoOriginal = btn.innerHTML;
        btn.classList.add('btn-copiado-exito');
        btn.innerHTML = '&#10003; Copiado!';
        setTimeout(function () {
            btn.innerHTML = textoOriginal;
            btn.classList.remove('btn-copiado-exito');
        }, 1500);
    }

    /* =========================================================================
     *  5. MEDIDOR INTERACTIVO DE FUERZA DE CONTRASENA
     * ========================================================================= */
    var medidores = document.querySelectorAll('[data-medidor-fuerza]');
    Array.prototype.forEach.call(medidores, function (medidor) {
        var contenedor = medidor.closest('.campo') || medidor.parentElement;
        var inputClave = contenedor.querySelector('input[type="password"]');
        if (!inputClave) return;

        var barra = medidor.querySelector('[data-barra-progreso]');
        var textoEtiqueta = medidor.querySelector('[data-texto-fuerza]');
        var textoPct = medidor.querySelector('[data-pct-fuerza]');

        var reqLongitud = medidor.querySelector('[data-req="longitud"]');
        var reqMayus    = medidor.querySelector('[data-req="mayus"]');
        var reqMinus    = medidor.querySelector('[data-req="minus"]');
        var reqNumero   = medidor.querySelector('[data-req="numero"]');
        var reqEspecial = medidor.querySelector('[data-req="especial"]');

        function actualizarReq(elem, cumplido) {
            if (!elem) return;
            if (cumplido) {
                elem.classList.add('req--ok');
                var icono = elem.querySelector('.req-icono');
                if (icono) icono.innerHTML = '&#10003;';
            } else {
                elem.classList.remove('req--ok');
                var icono = elem.querySelector('.req-icono');
                if (icono) icono.innerHTML = '&#10005;';
            }
        }

        function evaluarContrasena(clave) {
            if (!clave || clave.length === 0) {
                return { puntaje: 0, nivel: 'vacio', texto: 'Sin ingresar', color: '#94a3b8' };
            }

            var tieneLongitud = clave.length >= 8;
            var tieneMayus    = /[A-Z]/.test(clave);
            var tieneMinus    = /[a-z]/.test(clave);
            var tieneNumero   = /[0-9]/.test(clave);
            var tieneEspecial = /[^A-Za-z0-9]/.test(clave);

            actualizarReq(reqLongitud, tieneLongitud);
            actualizarReq(reqMayus, tieneMayus);
            actualizarReq(reqMinus, tieneMinus);
            actualizarReq(reqNumero, tieneNumero);
            actualizarReq(reqEspecial, tieneEspecial);

            var puntos = 0;
            if (tieneLongitud) puntos += 20;
            if (clave.length >= 12) puntos += 10;
            if (clave.length >= 16) puntos += 5;
            if (tieneMayus)    puntos += 20;
            if (tieneMinus)    puntos += 15;
            if (tieneNumero)   puntos += 15;
            if (tieneEspecial) puntos += 15;

            puntos = Math.min(100, puntos);

            if (puntos < 40) {
                return { puntaje: puntos, nivel: 'debil', texto: 'Debil', color: '#ef4444' };
            } else if (puntos < 70) {
                return { puntaje: puntos, nivel: 'regular', texto: 'Regular', color: '#f59e0b' };
            } else if (puntos < 90) {
                return { puntaje: puntos, nivel: 'buena', texto: 'Buena', color: '#2563eb' };
            } else {
                return { puntaje: puntos, nivel: 'fuerte', texto: 'Muy Segura', color: '#16a34a' };
            }
        }

        inputClave.addEventListener('input', function () {
            var valor = inputClave.value;
            var res = evaluarContrasena(valor);

            if (barra) {
                barra.style.width = res.puntaje + '%';
                barra.style.backgroundColor = res.color;
            }
            if (textoEtiqueta) {
                textoEtiqueta.textContent = res.texto;
                textoEtiqueta.style.color = res.color;
            }
            if (textoPct) {
                textoPct.textContent = res.puntaje + '%';
            }
        });
    });

    /* =========================================================================
     *  6. FIJAR / DESFIJAR NOTAS EN TIEMPO REAL (Pin to Top)
     * ========================================================================= */
    document.addEventListener('submit', function (evento) {
        var form = evento.target.closest('[data-form-fijar]');
        if (!form) return;

        evento.preventDefault();
        var formData = new FormData(form);
        formData.append('formato', 'json');

        var boton = form.querySelector('[data-btn-fijar]');
        var tarjeta = form.closest('.nota, .kanban-tarjeta');

        fetch('notas.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data && data.ok) {
                var estaFijada = data.fijada;

                if (boton) {
                    if (estaFijada) {
                        boton.classList.add('btn-fijar--activa');
                        boton.title = 'Desfijar de la parte superior';
                    } else {
                        boton.classList.remove('btn-fijar--activa');
                        boton.title = 'Fijar en la parte superior';
                    }
                }

                if (tarjeta) {
                    if (estaFijada) {
                        tarjeta.classList.add('nota--fijada', 'kanban-tarjeta--fijada');
                        // Mover al inicio de su contenedor para reflejar el orden
                        if (tarjeta.parentElement && tarjeta.parentElement.firstElementChild !== tarjeta) {
                            tarjeta.parentElement.insertBefore(tarjeta, tarjeta.parentElement.firstElementChild);
                        }
                    } else {
                        tarjeta.classList.remove('nota--fijada', 'kanban-tarjeta--fijada');
                    }
                }

                mostrarToast(estaFijada ? 'Nota fijada en la parte superior' : 'Nota desfijada', 'ok');
            } else {
                mostrarToast('Error al actualizar fijado de nota', 'error');
            }
        })
        .catch(function () {
            // Fallback: si falla fetch, envia el form normalmente
            form.submit();
        });
    });

    /* =========================================================================
     *  7. TABLERO KANBAN INTERACTIVO (HTML5 Drag & Drop)
     * ========================================================================= */
    var tableroKanban = document.querySelector('.kanban-tablero');
    if (tableroKanban) {
        var tokenCsrf = tableroKanban.getAttribute('data-csrf-token') || '';
        var tarjetaArrastrada = null;

        document.addEventListener('dragstart', function (e) {
            var tarjeta = e.target.closest('[data-kanban-tarjeta]');
            if (!tarjeta) return;
            tarjetaArrastrada = tarjeta;
            tarjeta.classList.add('kanban-tarjeta--arrastrando');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', tarjeta.getAttribute('data-nota-id'));
        });

        document.addEventListener('dragend', function (e) {
            var tarjeta = e.target.closest('[data-kanban-tarjeta]');
            if (tarjeta) {
                tarjeta.classList.remove('kanban-tarjeta--arrastrando');
            }
            tarjetaArrastrada = null;
            document.querySelectorAll('.kanban-columna').forEach(function (col) {
                col.classList.remove('kanban-columna--hover');
            });
        });

        var zonasSoltar = document.querySelectorAll('[data-zona-soltar]');
        Array.prototype.forEach.call(zonasSoltar, function (zona) {
            zona.addEventListener('dragover', function (e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                zona.closest('.kanban-columna').classList.add('kanban-columna--hover');
            });

            zona.addEventListener('dragleave', function (e) {
                zona.closest('.kanban-columna').classList.remove('kanban-columna--hover');
            });

            zona.addEventListener('drop', function (e) {
                e.preventDefault();
                zona.closest('.kanban-columna').classList.remove('kanban-columna--hover');

                if (!tarjetaArrastrada) return;

                var idNota = tarjetaArrastrada.getAttribute('data-nota-id');
                var columnaDestino = zona.getAttribute('data-zona-soltar');
                var estadoOrigen = tarjetaArrastrada.getAttribute('data-estado');

                if (columnaDestino === estadoOrigen) {
                    return; // Ya esta en esa columna
                }

                var accion = (columnaDestino === 'completadas') ? 'completar' : 'reabrir';

                // Mover visualmente en el DOM de forma inmediata (optimistic UI)
                zona.appendChild(tarjetaArrastrada);
                tarjetaArrastrada.setAttribute('data-estado', columnaDestino);

                if (columnaDestino === 'completadas') {
                    tarjetaArrastrada.classList.add('kanban-tarjeta--completada');
                } else {
                    tarjetaArrastrada.classList.remove('kanban-tarjeta--completada');
                }

                actualizarContadoresKanban();

                // Peticion AJAX asincrona para actualizar BD
                var fd = new FormData();
                fd.append('accion', accion);
                fd.append('id', idNota);
                fd.append('csrf_token', tokenCsrf);
                fd.append('formato', 'json');

                fetch('notas.php', {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data && data.ok) {
                        mostrarToast(
                            accion === 'completar'
                                ? 'Nota marcada como completada'
                                : 'Nota reabierta a pendientes',
                            'ok'
                        );
                    } else {
                        mostrarToast('Error al actualizar el estado de la nota', 'error');
                    }
                })
                .catch(function () {
                    mostrarToast('Error de conexion al actualizar estado', 'error');
                });
            });
        });

        function actualizarContadoresKanban() {
            var countPend = document.querySelectorAll('[data-zona-soltar="pendientes"] [data-kanban-tarjeta]').length;
            var countComp = document.querySelectorAll('[data-zona-soltar="completadas"] [data-kanban-tarjeta]').length;

            var badgePend = document.querySelector('[data-contador-pendientes]');
            var badgeComp = document.querySelector('[data-contador-completadas]');

            if (badgePend) badgePend.textContent = countPend;
            if (badgeComp) badgeComp.textContent = countComp;
        }
    }

    /* =========================================================================
     *  8. CIERRE DE SUGERENCIA DE AVATAR
     * ========================================================================= */
    var btnCerrarAvatar = document.querySelector('[data-cerrar-sugerencia]');
    if (btnCerrarAvatar) {
        btnCerrarAvatar.addEventListener('click', function () {
            var banner = document.getElementById('avatar-sugerencia');
            if (banner) {
                banner.style.transition = 'opacity .25s ease, transform .25s ease';
                banner.style.opacity = '0';
                banner.style.transform = 'translateY(-10px)';
                setTimeout(function () {
                    banner.remove();
                }, 250);
            }
        });
    }

    /* =========================================================================
     *  9. CONFIRMACIONES DECLARATIVAS
     * ========================================================================= */
    var conConfirmacion = document.querySelectorAll('[data-confirmar]');
    Array.prototype.forEach.call(conConfirmacion, function (formulario) {
        formulario.addEventListener('submit', function (evento) {
            var mensaje = formulario.getAttribute('data-confirmar');
            if (mensaje && !window.confirm(mensaje)) {
                evento.preventDefault();
            }
        });
    });

})();
