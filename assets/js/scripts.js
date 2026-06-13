(function () {
    var arcConfig = window.AlphaRssAiGenerator || {};
    var generators = Array.isArray(arcConfig.generators) ? arcConfig.generators : [];
    var defaults = arcConfig.defaults || {
        generator_id: '',
        name: '',
        feed_url: '',
        source_type: 'keyword_list',
        list_id: '0',
        keyword_list_mode: 'keywords',
        status: 'active',
        post_type: 'post',
        post_status: 'draft',
        author_id: '0',
        model: '',
        temperature: '0.7',
        max_tokens: '3000',
        posts_per_run: '1',
        schedule_type: 'interval',
        interval_minutes: '180',
        jitter_minutes: '30',
        daily_start: '08:00',
        daily_end: '22:00',
        image_source_mode: '',
        pexels_query: '',
        source_video_enabled: '0',
        source_content_media_enabled: '0',
        video_selector_class: '',
        image_selector_class: '',
        link_selector_class: '',
        content_image_size: 'medium',
        source_link_phrases: '',
        source_link_style: 'button',
        source_context_exclude_phrases: '',
        source_context_rating_label: 'IMDb',
        source_context_min_rating: '0',
        source_context_keep_unrated: '0',
        seo_enabled: '1',
        generation_language: 'Português do Brasil',
        category_ids: [],
        tags_default: [],
        custom_taxonomies: '',
        custom_meta: '',
        prompt_template: '',
        content_prompt_template: '',
        keyword_prompt_template: ''
    };
    var editId = parseInt(arcConfig.editId || 0, 10) || 0;
    var settingsModal = document.getElementById('arc-settings-modal');
    var settingsBackdrop = document.getElementById('arc-settings-backdrop');
    var runsModal = document.getElementById('arc-runs-modal');
    var runsBackdrop = document.getElementById('arc-runs-backdrop');
    var manualRunModal = document.getElementById('arc-manual-run-modal');
    var manualRunBackdrop = document.getElementById('arc-manual-run-backdrop');
    var manualRunTitle = document.getElementById('arc-manual-run-title');
    var manualRunSubtitle = document.getElementById('arc-manual-run-subtitle');
    var manualRunCount = document.getElementById('arc-manual-run-count');
    var manualRunRefresh = document.getElementById('arc-manual-run-refresh');
    var manualRunStatus = document.getElementById('arc-manual-run-status');
    var manualRunLoading = document.getElementById('arc-manual-run-loading');
    var manualRunEmpty = document.getElementById('arc-manual-run-empty');
    var manualRunList = document.getElementById('arc-manual-run-list');
    var manualRunForm = document.getElementById('arc-manual-run-form');
    var modal = document.getElementById('arc-generator-modal');
    var backdrop = document.getElementById('arc-generator-backdrop');
    var form = document.getElementById('arc-generator-form');
    if (!form) {
        return;
    }
    var titleEl = document.getElementById('arc-generator-modal-title');
    var submitEl = document.getElementById('arc-generator-submit');
    var feedUrlField = form.querySelector('[data-feed-url-field]');
    var listIdField = form.querySelector('[data-list-id-field]');
    var keywordListModeField = form.querySelector('[data-keyword-list-mode-field]');
    var videoSelectorField = form.querySelector('[data-rss-video-selector-field]');
    var sourceContentMediaField = form.querySelector('[data-rss-source-content-media-field]');
    var imageSelectorField = form.querySelector('[data-rss-image-selector-field]');
    var linkSelectorField = form.querySelector('[data-rss-link-selector-field]');
    var sourceFiltersField = form.querySelector('[data-rss-source-filters-field]');
    var apiBase = arcConfig.apiBase || '';
    var restNonce = arcConfig.restNonce || '';
    var openModalCount = 0;
    var manualRunCurrentGeneratorId = '';
    var manualRunCurrentGeneratorName = '';
    var manualRunLoadingRequest = null;

    function byName(name) {
        return form.querySelector('[name="' + name + '"]');
    }

    function setValue(name, value) {
        var el = byName(name);
        if (el) {
            el.value = value !== undefined && value !== null ? value : '';
            if (typeof Event === 'function') {
                el.dispatchEvent(new Event('change', {
                    bubbles: true
                }));
            } else if (document.createEvent) {
                var changeEvent = document.createEvent('Event');
                changeEvent.initEvent('change', true, false);
                el.dispatchEvent(changeEvent);
            }
        }
    }

    function promptLooksLikeRss(text) {
        var value = String(text || '');
        return value.indexOf('Você é um editor jornalístico especializado em reescrever conteúdo de RSS.') !== -1 ||
            value.indexOf('Você é um jornalista de portal focado em SEO e no estilo GEO') !== -1 ||
            value.indexOf('[DIRETRIZES DE ESCRITA E ESTILO (GEO)]') !== -1;
    }

    function promptLooksLikeKeyword(text) {
        return String(text || '').indexOf('Você é um editor de conteúdo especializado em criar artigos originais a partir de planilhas e palavras-chave.') !== -1;
    }

    function getDefaultImageSourceModeForType(sourceType) {
        return sourceType === 'keyword_list' ? 'pexels' : 'rss_or_pexels';
    }

    function normalizeImageSourceModeForType(sourceType, value) {
        var mode = String(value || '').trim();
        var allowed = ['rss', 'rss_or_pexels', 'rss_or_dalle', 'pexels', 'dalle'];
        if (allowed.indexOf(mode) === -1) {
            return getDefaultImageSourceModeForType(sourceType);
        }
        if (sourceType === 'keyword_list') {
            if (mode === 'rss' || mode === 'rss_or_pexels') {
                return 'pexels';
            }
            if (mode === 'rss_or_dalle') {
                return 'dalle';
            }
        }
        return mode;
    }

    function normalizePromptForSourceType(sourceType, keywordListMode, value) {
        var current = String(value || '').trim();
        if (!current) {
            if (sourceType === 'keyword_list') {
                return String(keywordListMode || 'keywords') === 'url_reference' ? defaults.prompt_template : defaults.keyword_prompt_template;
            }
            return defaults.prompt_template;
        }
        if (sourceType === 'keyword_list') {
            if (String(keywordListMode || 'keywords') === 'url_reference') {
                if (current === defaults.keyword_prompt_template) {
                    return defaults.prompt_template;
                }
                return current;
            }
            if (current === defaults.prompt_template) {
                return defaults.keyword_prompt_template;
            }
            return current;
        }
        if (current === defaults.keyword_prompt_template) {
            return defaults.prompt_template;
        }
        return current;
    }

    function setMultiSelect(name, values) {
        var el = byName(name);
        if (!el) {
            return;
        }
        var lookup = {};
        (values || []).forEach(function (value) {
            lookup[String(value)] = true;
        });
        Array.prototype.forEach.call(el.options, function (option) {
            option.selected = !!lookup[String(option.value)];
        });
        if (typeof Event === 'function') {
            el.dispatchEvent(new Event('change', {
                bubbles: true
            }));
        } else if (document.createEvent) {
            var changeEvent = document.createEvent('Event');
            changeEvent.initEvent('change', true, false);
            el.dispatchEvent(changeEvent);
        }
    }

    function initSelect2Fields() {
        if (!window.jQuery || !jQuery.fn || !jQuery.fn.select2) {
            return;
        }
        var $ = window.jQuery;
        var $modal = $('#arc-generator-modal');
        var selectors = [
            '#arc-generator-modal select[name="category_ids[]"]',
            '#arc-generator-modal select[name="tags_default[]"]'
        ];
        selectors.forEach(function (selector) {
            var $fields = $(selector);
            $fields.each(function () {
                var $field = $(this);
                if ($field.data('select2')) {
                    return;
                }
                $field.select2({
                    width: '100%',
                    dropdownParent: $modal,
                    closeOnSelect: false
                });
            });
        });
    }

    function syncSourceFields() {
        var sourceTypeEl = byName('source_type');
        var sourceType = sourceTypeEl ? sourceTypeEl.value : 'keyword_list';
        var keywordListModeEl = byName('keyword_list_mode');
        var keywordListMode = keywordListModeEl ? keywordListModeEl.value : 'keywords';
        var imageSourceModeEl = byName('image_source_mode');
        var sourceContentMediaEnabledEl = byName('source_content_media_enabled');
        var sourceContentMediaEnabled = sourceContentMediaEnabledEl ? sourceContentMediaEnabledEl.value === '1' : false;
        var showSourceContentMedia = sourceContentMediaEnabled && (sourceType === 'rss' || (sourceType === 'keyword_list' && keywordListMode === 'url_reference'));

        if (feedUrlField) {
            feedUrlField.classList.toggle('hidden', sourceType === 'keyword_list');
        }
        if (listIdField) {
            listIdField.classList.toggle('hidden', sourceType !== 'keyword_list');
        }
        if (keywordListModeField) {
            keywordListModeField.classList.toggle('hidden', sourceType !== 'keyword_list');
        }
        if (videoSelectorField) {
            var showVideoSelector = sourceType === 'rss' || (sourceType === 'keyword_list' && keywordListMode === 'url_reference');
            videoSelectorField.classList.toggle('hidden', !showVideoSelector);
        }
        if (sourceContentMediaField) {
            sourceContentMediaField.classList.toggle('hidden', !showSourceContentMedia);
        }
        if (sourceFiltersField) {
            var showSourceFilters = sourceType === 'rss' || (sourceType === 'keyword_list' && keywordListMode === 'url_reference');
            sourceFiltersField.classList.toggle('hidden', !showSourceFilters);
        }
        if (imageSourceModeEl) {
            imageSourceModeEl.value = normalizeImageSourceModeForType(sourceType, keywordListMode, imageSourceModeEl.value);
        }

        var promptEl = byName('prompt_template');
        if (promptEl) {
            promptEl.value = normalizePromptForSourceType(sourceType, keywordListMode, promptEl.value);
        }
    }

    function parseListValue(value) {
        if (Array.isArray(value)) {
            return value;
        }
        if (typeof value === 'string' && value !== '') {
            try {
                var parsed = JSON.parse(value);
                if (Array.isArray(parsed)) {
                    return parsed;
                }
            } catch (e) { }
            return value.split(',').map(function (part) {
                return part.trim();
            }).filter(Boolean);
        }
        return [];
    }

    function parseObjectValue(value) {
        if (value && typeof value === 'object' && !Array.isArray(value)) {
            return value;
        }
        if (typeof value === 'string' && value !== '') {
            try {
                var parsed = JSON.parse(value);
                if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                    return parsed;
                }
            } catch (e) { }
        }
        return {};
    }

    function objectToLines(objectValue) {
        var lines = [];
        Object.keys(objectValue || {}).forEach(function (key) {
            var value = objectValue[key];
            if (Array.isArray(value)) {
                value = value.join(',');
            }
            lines.push(key + '=' + value);
        });
        return lines.join('\n');
    }

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

    window.AlphaRssAiParseMaybeJsonResponseText = parseMaybeJsonResponseText;

    function setManualRunStatus(message, type) {
        if (!manualRunStatus) {
            return;
        }
        if (!message) {
            manualRunStatus.className = 'hidden mb-4 rounded-xl border px-4 py-3 text-sm';
            manualRunStatus.textContent = '';
            return;
        }
        var classes = 'mb-4 rounded-xl border px-4 py-3 text-sm';
        if (type === 'error') {
            classes += ' border-rose-200 bg-rose-50 text-rose-700';
        } else if (type === 'success') {
            classes += ' border-emerald-200 bg-emerald-50 text-emerald-700';
        } else {
            classes += ' border-slate-200 bg-slate-50 text-slate-600';
        }
        manualRunStatus.className = classes;
        manualRunStatus.textContent = message;
    }

    function setManualRunStatusHtml(message, link, linkLabel, type) {
        if (!manualRunStatus) {
            return;
        }
        if (!message) {
            manualRunStatus.className = 'hidden mb-4 rounded-xl border px-4 py-3 text-sm';
            manualRunStatus.innerHTML = '';
            return;
        }
        var classes = 'mb-4 rounded-xl border px-4 py-3 text-sm';
        if (type === 'error') {
            classes += ' border-rose-200 bg-rose-50 text-rose-700';
        } else if (type === 'success') {
            classes += ' border-emerald-200 bg-emerald-50 text-emerald-700';
        } else {
            classes += ' border-slate-200 bg-slate-50 text-slate-600';
        }
        var html = escapeHtml(message);
        if (link) {
            html += '<a href="' + escapeHtml(link) + '" target="_blank" rel="noopener noreferrer" class="ml-2 inline-flex items-center rounded-md border border-current/20 px-2 py-0.5 text-xs font-semibold text-inherit no-underline">' + escapeHtml(linkLabel || 'Ver conteúdo') + '</a>';
        }
        manualRunStatus.className = classes;
        manualRunStatus.innerHTML = html;
    }

    function showManualRunSwal(title, html) {
        if (window.Swal && typeof window.Swal.fire === 'function') {
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
        return false;
    }

    function updateManualRunSwal(title, html, icon) {
        var isFinalState = icon === 'success' || icon === 'error';
        if (window.Swal && typeof window.Swal.update === 'function' && window.Swal.isVisible && window.Swal.isVisible()) {
            if (isFinalState) {
                var finalOptions = {
                    title: title || '',
                    html: html || '',
                    icon: icon || 'info',
                    backdrop: false,
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showCloseButton: false,
                    showConfirmButton: true,
                    confirmButtonText: 'Fechar',
                    customClass: {
                        htmlContainer: 'text-center'
                    }
                };
                window.Swal.close();
                window.setTimeout(function () {
                    if (window.Swal && typeof window.Swal.fire === 'function') {
                        window.Swal.fire(finalOptions);
                    }
                }, 0);
                return true;
            }
            window.Swal.update({
                title: title || '',
                html: html || '',
                icon: icon || 'info',
                showCloseButton: false,
                showConfirmButton: false
            });
            if (typeof window.Swal.showLoading === 'function') {
                window.Swal.showLoading();
            }
            if (isFinalState && typeof window.Swal.hideLoading === 'function') {
                window.Swal.hideLoading();
            }
            return true;
        }
        return false;
    }

    function setManualRunLoading(isLoading) {
        if (manualRunLoading) {
            manualRunLoading.classList.toggle('hidden', !isLoading);
        }
        if (manualRunList) {
            manualRunList.classList.toggle('hidden', isLoading);
        }
        if (manualRunEmpty && !isLoading) {
            manualRunEmpty.classList.add('hidden');
        }
    }

    function setManualRunItems(items) {
        if (!manualRunList) {
            return;
        }

        manualRunList.innerHTML = '';
        if (manualRunEmpty) {
            manualRunEmpty.classList.add('hidden');
        }
        if (manualRunCount) {
            manualRunCount.textContent = String(items.length);
        }

        if (!items.length) {
            if (manualRunEmpty) {
                manualRunEmpty.classList.remove('hidden');
            }
            return;
        }

        items.forEach(function (item) {
            var excerpt = item.excerpt ? escapeHtml(item.excerpt) : '';
            var permalink = item.permalink ? escapeHtml(item.permalink) : '';
            var date = item.date ? escapeHtml(item.date) : '';
            var card = document.createElement('article');
            card.className = 'rounded-2xl border border-slate-200 bg-slate-50 p-5 shadow-sm';
            card.innerHTML = [
                '<div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">',
                '  <div class="min-w-0 flex-1">',
                '    <div class="flex flex-wrap items-center gap-2">',
                '      <h3 class="text-base font-semibold text-slate-950">' + escapeHtml(item.title || '(Sem título)') + '</h3>',
                '      ' + (date ? '<span class="rounded-full bg-white px-2.5 py-1 text-xs font-medium text-slate-600 ring-1 ring-slate-200">' + date + '</span>' : ''),
                '    </div>',
                excerpt ? '    <p class="mt-2 text-sm leading-6 text-slate-600">' + excerpt + '</p>' : '',
                permalink ? '    <a href="' + permalink + '" target="_blank" rel="noopener noreferrer" class="mt-2 inline-flex break-all text-sm text-indigo-600 hover:text-indigo-500">' + permalink + '</a>' : '',
                '  </div>',
                '  <div class="flex-shrink-0">',
                '    <button type="button" data-run-item-guid="' + escapeHtml(item.guid || '') + '" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-500">Gerar este item</button>',
                '  </div>',
                '</div>'
            ].join('');
            manualRunList.appendChild(card);
        });
    }

    async function runManualRunItem(itemGuid) {
        if (!manualRunCurrentGeneratorId || !itemGuid) {
            return;
        }
        if (window.AlphaRssAiGeneratorManualRunBusy) {
            return;
        }

        window.AlphaRssAiGeneratorManualRunBusy = true;
        var swalOpened = showManualRunSwal('Gerando item', '<div class="text-left text-sm text-slate-600">Processando o item escolhido sem recarregar a página.</div>');
        setManualRunStatus('Gerando item selecionado...', 'warning');

        try {
            var endpoint = apiBase.replace(/\/$/, '') + '/generators/' + encodeURIComponent(manualRunCurrentGeneratorId) + '/run';
            var stageToken = '';

            async function runStage(stage, token) {
                var response = await fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': restNonce
                    },
                    body: JSON.stringify({
                        stage: stage,
                        item_guid: itemGuid,
                        token: token || ''
                    })
                });

                var responseText = await response.text();
                var payload = window.AlphaRssAiParseMaybeJsonResponseText(responseText) || {};

                if (!response.ok || !payload || !payload.success) {
                    throw new Error((payload && payload.message) ? payload.message : 'Falha ao gerar o item');
                }

                return payload;
            }

            var seoBody = [
                '<div class="text-left text-sm text-slate-600">',
                '<div class="font-semibold text-slate-950">Etapa 1 de 4: gerando SEO.</div>',
                '<div class="mt-2">Aguarde enquanto o titulo, slug e contexto sao preparados.</div>',
                '</div>'
            ].join('');
            if (swalOpened) {
                updateManualRunSwal('Gerando SEO', seoBody, 'info');
            }
            setManualRunStatus('Gerando SEO do item selecionado...', 'warning');

            var seoResult = await runStage('seo', '');
            stageToken = seoResult.token || '';
            if (!stageToken) {
                throw new Error('Nao foi possivel iniciar a etapa de conteudo');
            }

            var contentBody = [
                '<div class="text-left text-sm text-slate-600">',
                '<div class="font-semibold text-slate-950">Etapa 2 de 4: gerando conteudo.</div>',
                '<div class="mt-2">O texto final esta sendo criado com base no item escolhido.</div>',
                '</div>'
            ].join('');
            if (swalOpened) {
                updateManualRunSwal('Gerando conteudo', contentBody, 'info');
            }
            setManualRunStatus('Gerando conteudo do item selecionado...', 'warning');

            var contentResult = await runStage('content', stageToken);
            stageToken = contentResult.token || stageToken;

            var mediaBody = [
                '<div class="text-left text-sm text-slate-600">',
                '<div class="font-semibold text-slate-950">Etapa 3 de 4: preparando o post.</div>',
                '<div class="mt-2">O conteudo base esta salvo e a midia sera aplicada em seguida.</div>',
                '</div>'
            ].join('');
            if (swalOpened) {
                updateManualRunSwal('Salvando post', mediaBody, 'info');
            }
            setManualRunStatus('Salvando o post gerado...', 'warning');

            var mediaResult = await runStage('media', stageToken);
            var mediaAttachBody = [
                '<div class="text-left text-sm text-slate-600">',
                '<div class="font-semibold text-slate-950">Etapa 4 de 4: baixando midias e finalizando.</div>',
                '<div class="mt-2">Agora a imagem e os demais recursos da fonte estao sendo aplicados.</div>',
                '</div>'
            ].join('');
            if (swalOpened) {
                updateManualRunSwal('Finalizando midias', mediaAttachBody, 'info');
            }
            setManualRunStatus('Baixando midias e finalizando o post...', 'warning');

            var mediaAttachResult = await runStage('media_attach', stageToken);
            var generatedResult = mediaAttachResult.result || mediaResult.result || {};
            var link = generatedResult.view_link || generatedResult.permalink || generatedResult.edit_link || '';
            if (link) {
                setManualRunStatusHtml('Item gerado com sucesso.', link, 'Ver conteúdo', 'success');
            } else {
                setManualRunStatus('Item gerado com sucesso.', 'success');
            }

            if (swalOpened) {
                updateManualRunSwal('Item gerado', '<div class="text-center text-sm text-slate-600">O conteúdo foi criado com sucesso.</div>' + (link ? '<div class="mt-4 flex justify-center"><a href="' + escapeHtml(link) + '" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white no-underline transition hover:bg-indigo-500">Ver conteúdo</a></div>' : ''), 'success');
            }

            window.setTimeout(function () {
                loadManualRunItems(manualRunCurrentGeneratorId, true);
            }, 350);
        } catch (error) {
            if (swalOpened) {
                updateManualRunSwal('Erro ao gerar', '<div class="text-left text-sm text-slate-600">' + escapeHtml(error && error.message ? error.message : 'Erro ao gerar o item.') + '</div>', 'error');
            }
            setManualRunStatus(error && error.message ? error.message : 'Erro ao gerar o item.', 'error');
        } finally {
            window.AlphaRssAiGeneratorManualRunBusy = false;
        }
    }

    function loadManualRunItems(generatorId, preserveResultStatus) {
        if (!generatorId) {
            return;
        }
        manualRunCurrentGeneratorId = String(generatorId);
        if (!preserveResultStatus) {
            setManualRunStatus('', '');
        }
        if (manualRunTitle) {
            manualRunTitle.textContent = 'Escolher item';
        }
        if (manualRunSubtitle) {
            manualRunSubtitle.textContent = 'Escolha um item disponível para gerar um post único.';
        }
        setManualRunLoading(true);

        if (manualRunLoadingRequest && manualRunLoadingRequest.abort) {
            manualRunLoadingRequest.abort();
        }
        manualRunLoadingRequest = typeof AbortController !== 'undefined' ? new AbortController() : null;

        var url = apiBase.replace(/\/$/, '') + '/generators/' + encodeURIComponent(generatorId) + '/items?limit=30';
        fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'X-WP-Nonce': restNonce
            },
            signal: manualRunLoadingRequest ? manualRunLoadingRequest.signal : undefined
        }).then(function (response) {
            return response.text().then(function (text) {
                var payload = window.AlphaRssAiParseMaybeJsonResponseText(text);
                return {
                    ok: response.ok,
                    status: response.status,
                    payload: payload
                };
            });
        }).then(function (result) {
            if (!result.ok || !result.payload || !result.payload.success) {
                throw new Error((result.payload && result.payload.message) ? result.payload.message : 'Não foi possível carregar os itens do feed.');
            }
            var payload = result.payload;
            manualRunCurrentGeneratorName = payload.generator && payload.generator.name ? String(payload.generator.name) : '';
            if (manualRunTitle) {
                manualRunTitle.textContent = manualRunCurrentGeneratorName ? ('Escolher item: ' + manualRunCurrentGeneratorName) : 'Escolher item';
            }
            if (manualRunSubtitle) {
                manualRunSubtitle.textContent = 'Escolha um item disponível para gerar um post único.';
            }
            setManualRunItems(payload.items || []);
            if (!payload.items || !payload.items.length) {
                if (manualRunEmpty) {
                    manualRunEmpty.classList.remove('hidden');
                }
            }
            if (manualRunCount) {
                manualRunCount.textContent = String((payload.items || []).length);
            }
            setManualRunStatus('', '');
        }).catch(function (error) {
            if (error && error.name === 'AbortError') {
                return;
            }
            if (manualRunList) {
                manualRunList.innerHTML = '';
            }
            if (manualRunEmpty) {
                manualRunEmpty.classList.add('hidden');
            }
            setManualRunStatus(error.message || 'Falha ao carregar os itens do feed.', 'error');
        }).finally(function () {
            setManualRunLoading(false);
        });
    }

    function applyDefaults() {
        setValue('generator_id', defaults.generator_id);
        setValue('name', defaults.name);
        setValue('feed_url', defaults.feed_url);
        setValue('source_type', defaults.source_type);
        setValue('list_id', defaults.list_id);
        setValue('status', defaults.status);
        setValue('post_type', defaults.post_type);
        setValue('post_status', defaults.post_status);
        setValue('author_id', defaults.author_id);
        setValue('model', defaults.model);
        setValue('temperature', defaults.temperature);
        setValue('max_tokens', defaults.max_tokens);
        setValue('posts_per_run', defaults.posts_per_run);
        setValue('schedule_type', defaults.schedule_type);
        setValue('interval_minutes', defaults.interval_minutes);
        setValue('jitter_minutes', defaults.jitter_minutes);
        setValue('daily_start', defaults.daily_start);
        setValue('daily_end', defaults.daily_end);
        setValue('image_source_mode', normalizeImageSourceModeForType(defaults.source_type, defaults.image_source_mode || getDefaultImageSourceModeForType(defaults.source_type)));
        setValue('pexels_query', defaults.pexels_query);
        setValue('source_video_enabled', defaults.source_video_enabled);
        setValue('source_content_media_enabled', defaults.source_content_media_enabled);
        setValue('video_selector_class', defaults.video_selector_class);
        setValue('image_selector_class', defaults.image_selector_class);
        setValue('link_selector_class', defaults.link_selector_class);
        setValue('content_image_size', defaults.content_image_size);
        setValue('source_link_phrases', defaults.source_link_phrases);
        setValue('source_link_style', defaults.source_link_style);
        setValue('source_context_exclude_phrases', defaults.source_context_exclude_phrases);
        setValue('source_context_rating_label', defaults.source_context_rating_label);
        setValue('source_context_min_rating', defaults.source_context_min_rating);
        setValue('source_context_keep_unrated', defaults.source_context_keep_unrated);
        setValue('seo_enabled', defaults.seo_enabled);
        setValue('generation_language', defaults.generation_language);
        setMultiSelect('category_ids[]', []);
        setMultiSelect('tags_default[]', []);
        setValue('custom_taxonomies', defaults.custom_taxonomies);
        setValue('custom_meta', defaults.custom_meta);
        setValue('prompt_template', defaults.prompt_template);
        setValue('content_prompt_template', defaults.content_prompt_template);
        syncSourceFields();
        if (titleEl) {
            titleEl.textContent = 'Adicionar gerador';
        }
        if (submitEl) {
            submitEl.textContent = 'Salvar gerador';
        }
    }

    function fillForm(generator) {
        applyDefaults();
        if (!generator) {
            return;
        }

        setValue('generator_id', generator.id);
        setValue('name', generator.name);
        setValue('feed_url', generator.feed_url);
        setValue('source_type', generator.source_type || defaults.source_type);
        setValue('list_id', typeof generator.list_id !== 'undefined' ? String(generator.list_id) : defaults.list_id);
        setValue('status', generator.status);
        setValue('post_type', generator.post_type);
        setValue('post_status', generator.post_status);
        setValue('author_id', generator.author_id);
        setValue('model', generator.model);
        setValue('temperature', generator.temperature);
        setValue('max_tokens', generator.max_tokens);
        setValue('posts_per_run', generator.posts_per_run);
        setValue('schedule_type', generator.schedule_type);
        setValue('interval_minutes', generator.interval_minutes);
        setValue('jitter_minutes', generator.jitter_minutes);
        setValue('daily_start', generator.daily_start);
        setValue('daily_end', generator.daily_end);
        setValue('image_source_mode', normalizeImageSourceModeForType(generator.source_type || defaults.source_type, generator.image_source_mode || (typeof generator.pexels_enabled !== 'undefined' ? (String(generator.pexels_enabled) === '1' ? 'rss_or_pexels' : 'rss') : defaults.image_source_mode)));
        setValue('pexels_query', generator.pexels_query || defaults.pexels_query);
        setValue('source_video_enabled', String(typeof generator.source_video_enabled !== 'undefined' ? generator.source_video_enabled : defaults.source_video_enabled));
        setValue('source_content_media_enabled', String(typeof generator.source_content_media_enabled !== 'undefined' ? generator.source_content_media_enabled : defaults.source_content_media_enabled));
        setValue('video_selector_class', generator.video_selector_class || defaults.video_selector_class);
        setValue('image_selector_class', generator.image_selector_class || defaults.image_selector_class);
        setValue('link_selector_class', generator.link_selector_class || defaults.link_selector_class);
        setValue('content_image_size', generator.content_image_size || defaults.content_image_size);
        setValue('source_link_phrases', generator.source_link_phrases || defaults.source_link_phrases);
        setValue('source_link_style', generator.source_link_style || defaults.source_link_style);
        setValue('source_context_exclude_phrases', generator.source_context_exclude_phrases || defaults.source_context_exclude_phrases);
        setValue('source_context_rating_label', generator.source_context_rating_label || defaults.source_context_rating_label);
        setValue('source_context_min_rating', typeof generator.source_context_min_rating !== 'undefined' ? generator.source_context_min_rating : defaults.source_context_min_rating);
        setValue('source_context_keep_unrated', String(typeof generator.source_context_keep_unrated !== 'undefined' ? generator.source_context_keep_unrated : defaults.source_context_keep_unrated));
        setValue('seo_enabled', String(typeof generator.seo_enabled !== 'undefined' ? generator.seo_enabled : defaults.seo_enabled));
        setValue('generation_language', generator.generation_language || defaults.generation_language);
        setMultiSelect('category_ids[]', parseListValue(generator.category_ids));
        setMultiSelect('tags_default[]', parseListValue(generator.tags_default));
        setValue('custom_taxonomies', objectToLines(parseObjectValue(generator.custom_taxonomies)));
        setValue('custom_meta', objectToLines(parseObjectValue(generator.custom_meta)));
        setValue('prompt_template', normalizePromptForSourceType(generator.source_type || defaults.source_type, generator.keyword_list_mode || defaults.keyword_list_mode, generator.prompt_template || (generator.source_type === 'keyword_list' ? defaults.keyword_prompt_template : defaults.prompt_template)));
        setValue('content_prompt_template', generator.content_prompt_template || defaults.content_prompt_template);
        syncSourceFields();

        if (titleEl) {
            titleEl.textContent = 'Editar gerador';
        }
        if (submitEl) {
            submitEl.textContent = 'Atualizar gerador';
        }
    }

    var sourceTypeEl = byName('source_type');
    if (sourceTypeEl) {
        sourceTypeEl.addEventListener('change', syncSourceFields);
    }
    var sourceContentMediaEnabledEl = byName('source_content_media_enabled');
    if (sourceContentMediaEnabledEl) {
        sourceContentMediaEnabledEl.addEventListener('change', syncSourceFields);
    }

    initSelect2Fields();

    function syncBodyLock() {
        document.body.classList.toggle('overflow-hidden', openModalCount > 0);
    }

    function openModal(targetModal) {
        if (!targetModal || !targetModal.classList.contains('hidden')) {
            return;
        }
        targetModal.classList.remove('hidden');
        openModalCount++;
        syncBodyLock();
    }

    function closeModal(targetModal) {
        if (!targetModal || targetModal.classList.contains('hidden')) {
            return;
        }
        targetModal.classList.add('hidden');
        openModalCount = Math.max(0, openModalCount - 1);
        syncBodyLock();
    }

    function resetGeneratorForm() {
        form.reset();
        applyDefaults();
    }

    document.querySelectorAll('[data-open-settings-modal]').forEach(function (button) {
        button.addEventListener('click', function () {
            openModal(settingsModal);
        });
    });

    document.querySelectorAll('[data-open-runs-modal]').forEach(function (button) {
        button.addEventListener('click', function () {
            openModal(runsModal);
        });
    });

    document.querySelectorAll('[data-open-manual-run-modal]').forEach(function (button) {
        button.addEventListener('click', function () {
            var generatorId = String(button.getAttribute('data-generator-id') || '');
            var generatorName = String(button.getAttribute('data-generator-name') || '');
            manualRunCurrentGeneratorId = generatorId;
            manualRunCurrentGeneratorName = generatorName;
            if (manualRunSubtitle) {
                manualRunSubtitle.textContent = generatorName ? ('Carregando itens do gerador "' + generatorName + '"...') : 'Carregando itens disponíveis...';
            }
            if (manualRunTitle) {
                manualRunTitle.textContent = 'Escolher item';
            }
            setManualRunStatus('', '');
            setManualRunLoading(true);
            if (manualRunList) {
                manualRunList.innerHTML = '';
            }
            if (manualRunEmpty) {
                manualRunEmpty.classList.add('hidden');
            }
            openModal(manualRunModal);
            loadManualRunItems(generatorId);
        });
    });

    document.querySelectorAll('[data-open-generator-modal]').forEach(function (button) {
        button.addEventListener('click', function () {
            fillForm(null);
            openModal(modal);
        });
    });

    document.querySelectorAll('[data-edit-generator-id]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            if (event && typeof event.preventDefault === 'function') {
                event.preventDefault();
            }
            var id = String(button.getAttribute('data-edit-generator-id') || '');
            var generator = generators.find(function (item) {
                return String(item.id) === id;
            });
            fillForm(generator || null);
            openModal(modal);
        });
    });

    document.querySelectorAll('[data-close-generator-modal]').forEach(function (button) {
        button.addEventListener('click', function () {
            closeModal(modal);
            resetGeneratorForm();
        });
    });

    document.querySelectorAll('[data-close-settings-modal]').forEach(function (button) {
        button.addEventListener('click', function () {
            closeModal(settingsModal);
        });
    });

    document.querySelectorAll('[data-close-runs-modal]').forEach(function (button) {
        button.addEventListener('click', function () {
            closeModal(runsModal);
        });
    });

    document.querySelectorAll('[data-close-manual-run-modal]').forEach(function (button) {
        button.addEventListener('click', function () {
            closeModal(manualRunModal);
            setManualRunStatus('', '');
            if (manualRunList) {
                manualRunList.innerHTML = '';
            }
            if (manualRunEmpty) {
                manualRunEmpty.classList.add('hidden');
            }
            if (manualRunLoadingRequest && manualRunLoadingRequest.abort) {
                manualRunLoadingRequest.abort();
            }
        });
    });

    if (backdrop) {
        backdrop.addEventListener('click', function () {
            closeModal(modal);
            resetGeneratorForm();
        });
    }

    if (settingsBackdrop) {
        settingsBackdrop.addEventListener('click', function () {
            closeModal(settingsModal);
        });
    }

    if (runsBackdrop) {
        runsBackdrop.addEventListener('click', function () {
            closeModal(runsModal);
        });
    }

    if (manualRunBackdrop) {
        manualRunBackdrop.addEventListener('click', function () {
            closeModal(manualRunModal);
            setManualRunStatus('', '');
            if (manualRunList) {
                manualRunList.innerHTML = '';
            }
            if (manualRunEmpty) {
                manualRunEmpty.classList.add('hidden');
            }
            if (manualRunLoadingRequest && manualRunLoadingRequest.abort) {
                manualRunLoadingRequest.abort();
            }
        });
    }

    if (manualRunRefresh) {
        manualRunRefresh.addEventListener('click', function () {
            if (manualRunCurrentGeneratorId) {
                loadManualRunItems(manualRunCurrentGeneratorId);
            }
        });
    }

    if (manualRunList) {
        manualRunList.addEventListener('click', function (event) {
            var button = event.target && event.target.closest ? event.target.closest('[data-run-item-guid]') : null;
            if (!button) {
                return;
            }
            var itemGuid = String(button.getAttribute('data-run-item-guid') || '');
            if (itemGuid !== '') {
                runManualRunItem(itemGuid);
            }
        });
    }

    window.AlphaRssAiGeneratorRunItem = runManualRunItem;

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            if (modal && !modal.classList.contains('hidden')) {
                closeModal(modal);
                resetGeneratorForm();
            }
            if (settingsModal && !settingsModal.classList.contains('hidden')) {
                closeModal(settingsModal);
            }
            if (runsModal && !runsModal.classList.contains('hidden')) {
                closeModal(runsModal);
            }
            if (manualRunModal && !manualRunModal.classList.contains('hidden')) {
                closeModal(manualRunModal);
                setManualRunStatus('', '');
                if (manualRunList) {
                    manualRunList.innerHTML = '';
                }
                if (manualRunEmpty) {
                    manualRunEmpty.classList.add('hidden');
                }
                if (manualRunLoadingRequest && manualRunLoadingRequest.abort) {
                    manualRunLoadingRequest.abort();
                }
            }
        }
    });

    if (editId > 0) {
        var initialGenerator = generators.find(function (item) {
            return String(item.id) === String(editId);
        });
        if (initialGenerator) {
            fillForm(initialGenerator);
            openModal(modal);
        }
    } else {
        applyDefaults();
    }
})();
