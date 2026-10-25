/**
 * Menu lateral: colapso manual en escritorio (con memoria) y panel deslizable
 * en pantallas chicas. Sin dependencias y sin script en linea (la CSP solo
 * permite 'self').
 */
(function () {
    'use strict';

    var cuerpo = document.body;
    var boton = document.querySelector('[data-nav-toggle]');
    if (!boton) {
        return;
    }

    var CLAVE = 'blindnote-sidebar-colapsado';
    var chico = window.matchMedia('(max-width: 900px)');

    function esChico() {
        return chico.matches;
    }

    // Recuerda la eleccion hecha en escritorio.
    try {
        if (window.localStorage.getItem(CLAVE) === '1') {
            cuerpo.classList.add('nav-colapsado');
        }
    } catch (error) {
        // Modo privado o almacenamiento bloqueado: se ignora la preferencia.
    }

    function sincronizarAria() {
        var abierto = esChico()
            ? cuerpo.classList.contains('nav-abierto')
            : !cuerpo.classList.contains('nav-colapsado');

        boton.setAttribute('aria-expanded', abierto ? 'true' : 'false');
    }

    boton.addEventListener('click', function () {
        if (esChico()) {
            cuerpo.classList.toggle('nav-abierto');
        } else {
            var colapsado = cuerpo.classList.toggle('nav-colapsado');
            try {
                window.localStorage.setItem(CLAVE, colapsado ? '1' : '0');
            } catch (error) {
                // Sin persistencia: el colapso sigue funcionando en esta sesion.
            }
        }
        sincronizarAria();
    });

    var velo = document.querySelector('[data-nav-cerrar]');
    if (velo) {
        velo.addEventListener('click', function () {
            cuerpo.classList.remove('nav-abierto');
            sincronizarAria();
        });
    }

    // Al elegir una seccion en pantallas chicas, el panel se cierra.
    var enlaces = document.querySelectorAll('.sidebar a');
    Array.prototype.forEach.call(enlaces, function (enlace) {
        enlace.addEventListener('click', function () {
            if (esChico()) {
                cuerpo.classList.remove('nav-abierto');
                sincronizarAria();
            }
        });
    });

    // Cambio de tamano: el estado del panel movil no aplica en escritorio.
    chico.addEventListener('change', function () {
        cuerpo.classList.remove('nav-abierto');
        sincronizarAria();
    });

    sincronizarAria();
})();
