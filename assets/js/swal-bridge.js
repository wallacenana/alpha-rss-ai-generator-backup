(function () {
    'use strict';

    var nativeAlert = typeof window.alert === 'function' ? window.alert.bind(window) : function () {};

    function hasSwal() {
        return !!(window.Swal && typeof window.Swal.fire === 'function');
    }

    function stripHtml(value) {
        return String(value || '').replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();
    }

    function fire(options) {
        if (hasSwal()) {
            return window.Swal.fire(options || {});
        }

        var message = '';
        if (options && typeof options === 'object') {
            message = stripHtml(options.text || options.html || options.title || '');
        } else {
            message = stripHtml(options);
        }

        if (message) {
            nativeAlert(message);
        }

        return Promise.resolve({
            isConfirmed: true,
        });
    }

    window.AlphaRssAiGeneratorSwal = {
        fire: fire,
        alert: function (message, title) {
            return fire({
                icon: 'info',
                title: title || 'Aviso',
                text: message || '',
                confirmButtonText: 'OK',
            });
        },
        success: function (title, html) {
            return fire({
                icon: 'success',
                title: title || 'Sucesso',
                html: html || '',
                confirmButtonText: 'Fechar',
            });
        },
        error: function (message, title) {
            return fire({
                icon: 'error',
                title: title || 'Erro',
                text: message || '',
                confirmButtonText: 'OK',
            });
        },
        info: function (message, title) {
            return fire({
                icon: 'info',
                title: title || 'Aviso',
                text: message || '',
                confirmButtonText: 'OK',
            });
        },
        loading: function (title, text) {
            if (!hasSwal()) {
                return Promise.resolve({
                    isConfirmed: true,
                });
            }

            return window.Swal.fire({
                title: title || 'Processando...',
                text: text || '',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: function () {
                    if (typeof window.Swal.showLoading === 'function') {
                        window.Swal.showLoading();
                    }
                },
            });
        },
        close: function () {
            if (hasSwal()) {
                window.Swal.close();
            }
        },
        confirm: function (message, options) {
            if (hasSwal()) {
                return window.Swal.fire({
                    icon: (options && options.icon) || 'question',
                    title: (options && options.title) || 'Confirmacao',
                    text: message || '',
                    showCancelButton: true,
                    confirmButtonText: (options && options.confirmButtonText) || 'Confirmar',
                    cancelButtonText: (options && options.cancelButtonText) || 'Cancelar',
                    reverseButtons: true,
                }).then(function (result) {
                    return !!(result && result.isConfirmed);
                });
            }

            return Promise.resolve(window.confirm(message || 'Confirmar esta acao?'));
        },
    };

    window.alert = function (message) {
        window.AlphaRssAiGeneratorSwal.alert(message);
    };

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || !form.matches || !form.matches('form[data-swal-confirm]')) {
            return;
        }

        if (form.dataset.arcSwalSkipConfirm === '1') {
            form.dataset.arcSwalSkipConfirm = '0';
            return;
        }

        event.preventDefault();

        var message = form.getAttribute('data-swal-confirm') || 'Confirmar esta acao?';
        window.AlphaRssAiGeneratorSwal.confirm(message, {
            title: 'Confirmacao',
        }).then(function (confirmed) {
            if (!confirmed) {
                return;
            }

            form.dataset.arcSwalSkipConfirm = '1';
            form.submit();
        });
    }, true);
})();
