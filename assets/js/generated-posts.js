(function () {
    'use strict';

    var config = window.AlphaRssAiGeneratedPosts || {};
    var apiBase = String(config.apiBase || '').replace(/\/$/, '');
    var restNonce = String(config.restNonce || '');
    var busy = false;

    function escapeHtml(value) {
        return String(value === undefined || value === null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function parseMaybeJsonResponseText(text) {
        var value = String(text === undefined || text === null ? '' : text).replace(/^\uFEFF/, '').trim();
        if (!value) {
            return null;
        }

        var firstChar = value.charAt(0);
        if (firstChar !== '{' && firstChar !== '[') {
            var objectIndex = value.indexOf('{');
            var arrayIndex = value.indexOf('[');
            var startIndex = -1;
            if (objectIndex >= 0 && arrayIndex >= 0) {
                startIndex = Math.min(objectIndex, arrayIndex);
            } else if (objectIndex >= 0) {
                startIndex = objectIndex;
            } else if (arrayIndex >= 0) {
                startIndex = arrayIndex;
            }

            if (startIndex > 0) {
                value = value.slice(startIndex).trim();
            }
        }

        try {
            return JSON.parse(value);
        } catch (error) {
            return {
                success: false,
                message: value || 'Resposta invalida'
            };
        }
    }

    function showLoading(title, html) {
        if (!window.Swal || typeof window.Swal.fire !== 'function') {
            return false;
        }

        window.Swal.fire({
            title: title || 'Processando',
            html: html || '',
            icon: 'info',
            backdrop: false,
            allowOutsideClick: false,
            allowEscapeKey: false,
            showCloseButton: false,
            showConfirmButton: false,
            didOpen: function () {
                if (window.Swal && typeof window.Swal.showLoading === 'function') {
                    window.Swal.showLoading();
                }
            }
        });

        return true;
    }

    function showFinal(title, html, icon) {
        if (!window.Swal || typeof window.Swal.fire !== 'function') {
            return;
        }

        if (typeof window.Swal.close === 'function') {
            window.Swal.close();
        }

        window.setTimeout(function () {
            if (!window.Swal || typeof window.Swal.fire !== 'function') {
                return;
            }

            window.Swal.fire({
                title: title || '',
                html: html || '',
                icon: icon || 'success',
                backdrop: false,
                allowOutsideClick: false,
                allowEscapeKey: false,
                showCloseButton: false,
                showConfirmButton: true,
                confirmButtonText: 'Fechar',
                customClass: {
                    htmlContainer: 'text-center'
                }
            });
        }, 0);
    }

    async function regeneratePost(postId, button) {
        if (busy || !apiBase || !restNonce || !postId) {
            return;
        }

        busy = true;
        var postTitle = button ? String(button.getAttribute('data-regenerate-post-title') || '') : '';
        var originalText = button ? button.textContent : '';
        if (button) {
            button.disabled = true;
            button.textContent = 'Regerando...';
        }

        showLoading(postTitle ? ('Regerando: ' + postTitle) : 'Regerando post', '<div class="text-left text-sm text-slate-600">Processando a regeneração sem recarregar a página.</div>');

        try {
            var response = await fetch(apiBase + '/generated-posts/' + encodeURIComponent(postId) + '/regenerate', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': restNonce
                },
                body: JSON.stringify({})
            });

            var responseText = await response.text();
            var payload = parseMaybeJsonResponseText(responseText) || {};

            if (!response.ok || !payload || !payload.success) {
                throw new Error((payload && payload.message) ? payload.message : 'Falha ao regerar o post.');
            }

            var result = payload.result || {};
            var link = String(result.view_link || result.permalink || result.edit_link || '');
            var html = '<div class="text-center text-sm text-slate-600">O post foi regenerado com sucesso.</div>';
            if (link) {
                html += '<div class="mt-4 flex justify-center"><a href="' + escapeHtml(link) + '" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white no-underline transition hover:bg-indigo-500">Ver conteúdo</a></div>';
            }
            showFinal('Post regenerado', html, 'success');
        } catch (error) {
            showFinal('Erro ao regerar', '<div class="text-left text-sm text-slate-600">' + escapeHtml(error && error.message ? error.message : 'Erro ao regerar o post.') + '</div>', 'error');
        } finally {
            busy = false;
            if (button) {
                button.disabled = false;
                button.textContent = originalText || 'Regerar';
            }
        }
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target && event.target.closest ? event.target.closest('[data-regenerate-post-id]') : null;
        if (!trigger) {
            return;
        }

        event.preventDefault();
        regeneratePost(trigger.getAttribute('data-regenerate-post-id') || '', trigger);
    });
})();
