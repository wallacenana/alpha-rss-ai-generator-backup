(function ($, window) {
    'use strict';

    var config = window.AlphaRSSAIPexelsMedia || {};
    var featuredFramePatched = false;
    var selectFramePatched = false;
    var bootAttempts = 0;

    function boot() {
        if (!config.ajaxUrl) {
            return;
        }

        // The featured-image frame is initialized after this asset on some
        // WordPress versions. Wait instead of exiting before the frame exists.
        if (!window.wp || !wp.media || !wp.media.featuredImage || !wp.media.featuredImage.frame || !wp.media.View || !wp.media.view || !wp.media.view.MediaFrame || !wp.media.view.MediaFrame.Select) {
            bootAttempts += 1;
            if (bootAttempts < 120) {
                window.setTimeout(boot, 100);
            }
            return;
        }

        var PexelsView = wp.media.View.extend({
        className: 'alpha-rss-ai-pexels-browser',

        events: {
            'click .alpha-rss-ai-pexels-search-button': 'search',
            'keydown .alpha-rss-ai-pexels-search-input': 'handleKeydown',
            'click .alpha-rss-ai-pexels-load-more': 'loadMore',
            'click .alpha-rss-ai-pexels-card': 'useImage'
        },

        initialize: function (options) {
            this.controller = options.controller;
            this.page = 1;
            this.query = '';
            this.images = {};
            this.loading = false;
            this.render();
        },

        render: function () {
            this.$el.html(
                '<div class="alpha-rss-ai-pexels-toolbar">' +
                    '<input type="search" class="alpha-rss-ai-pexels-search-input" placeholder="Buscar no Pexels" />' +
                    '<button type="button" class="button button-primary alpha-rss-ai-pexels-search-button">Pesquisar</button>' +
                '</div>' +
                '<div class="alpha-rss-ai-pexels-status" aria-live="polite"></div>' +
                '<div class="alpha-rss-ai-pexels-grid"></div>' +
                '<div class="alpha-rss-ai-pexels-more-wrap"><button type="button" class="button alpha-rss-ai-pexels-load-more">Carregar mais</button></div>'
            );
            this.$more = this.$('.alpha-rss-ai-pexels-load-more').hide();
            return this;
        },

        handleKeydown: function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                this.search();
            }
        },

        search: function () {
            var query = $.trim(this.$('.alpha-rss-ai-pexels-search-input').val() || '');
            if (query === '') {
                this.setStatus('Digite uma busca para pesquisar no Pexels.', true);
                return;
            }

            this.page = 1;
            this.query = query;
            this.images = {};
            this.$('.alpha-rss-ai-pexels-grid').empty();
            this.fetchImages(false);
        },

        loadMore: function () {
            if (!this.loading) {
                this.page += 1;
                this.fetchImages(true);
            }
        },

        fetchImages: function (append) {
            var self = this;
            this.loading = true;
            this.setStatus('Carregando imagens...', false);
            this.$more.prop('disabled', true);

            $.post(config.ajaxUrl, {
                action: 'alpha_rss_ai_pexels_search',
                nonce: config.nonce,
                post_id: this.getPostId(),
                query: this.query,
                page: this.page
            }).done(function (response) {
                if (!response || !response.success) {
                    self.setStatus(self.getError(response, 'Nao foi possivel pesquisar no Pexels.'), true);
                    return;
                }

                var images = response.data && Array.isArray(response.data.images) ? response.data.images : [];
                self.renderImages(images, append);
                self.$more.toggle(!!(response.data && response.data.has_more));
                self.setStatus(images.length ? '' : 'Nenhuma imagem encontrada.', !images.length);
            }).fail(function (xhr) {
                self.setStatus(self.getError(xhr.responseJSON, 'Falha ao pesquisar no Pexels.'), true);
            }).always(function () {
                self.loading = false;
                self.$more.prop('disabled', false);
            });
        },

        renderImages: function (images, append) {
            var self = this;
            var $grid = this.$('.alpha-rss-ai-pexels-grid');
            if (!append) {
                $grid.empty();
            }

            images.forEach(function (image) {
                if (!image || !image.url || self.images[image.url]) {
                    return;
                }
                self.images[image.url] = image;

                var $card = $('<button type="button" class="alpha-rss-ai-pexels-card"></button>');
                $('<img />', {
                    src: image.preview || image.url,
                    alt: image.alt || self.query,
                    loading: 'lazy'
                }).appendTo($card);
                $('<span class="alpha-rss-ai-pexels-credit"></span>').text(image.photographer ? 'Foto: ' + image.photographer : 'Pexels').appendTo($card);
                $card.attr('data-image-url', image.url);
                $grid.append($card);
            });
        },

        useImage: function (event) {
            var self = this;
            var $card = $(event.currentTarget);
            var image = this.images[$card.attr('data-image-url')];
            var postId = this.getPostId();

            if (!image || !postId) {
                this.setStatus('Salve o post antes de escolher uma imagem.', true);
                return;
            }

            $card.prop('disabled', true).addClass('is-loading');
            this.setStatus('Baixando imagem para a biblioteca...', false);

            $.post(config.ajaxUrl, {
                action: 'alpha_rss_ai_pexels_set_featured',
                nonce: config.nonce,
                post_id: postId,
                image_url: image.url,
                query: this.query,
                photographer: image.photographer || ''
            }).done(function (response) {
                if (!response || !response.success || !response.data || !response.data.attachment_id) {
                    self.setStatus(self.getError(response, 'Nao foi possivel definir a imagem destacada.'), true);
                    return;
                }

                wp.media.view.settings.post.featuredImageId = parseInt(response.data.attachment_id, 10);
                wp.media.featuredImage.set(parseInt(response.data.attachment_id, 10));
                self.controller.close();
            }).fail(function (xhr) {
                self.setStatus(self.getError(xhr.responseJSON, 'Falha ao baixar a imagem do Pexels.'), true);
            }).always(function () {
                $card.prop('disabled', false).removeClass('is-loading');
            });
        },

        getPostId: function () {
            var field = document.getElementById('post_ID');
            return parseInt((field && field.value) || config.postId || 0, 10);
        },

        getError: function (response, fallback) {
            return response && response.data && response.data.message ? response.data.message : fallback;
        },

        setStatus: function (message, isError) {
            this.$('.alpha-rss-ai-pexels-status').text(message || '').toggleClass('is-error', !!isError);
        }
    });

        function addPexelsRoute(router) {
            if (!router) {
                return;
            }

            router.set({
                'alpha-rss-ai-pexels': {
                    text: 'Pexels',
                    priority: 60,
                    content: 'alpha-rss-ai-pexels'
                }
            });
        }

        function attachFrame(frame) {
            if (!frame || frame.alphaRssAiPexelsAttached) {
                return;
            }

            frame.alphaRssAiPexelsAttached = true;
            frame.on('router:render:browse', addPexelsRoute);
            frame.on('content:render:alpha-rss-ai-pexels', function () {
                var view = new PexelsView({ controller: frame }).render();
                if (frame.content && frame.content.set) {
                    frame.content.set(view);
                }
            });

            // A frame can already have a router when the plugin loads.
            if (frame.router && frame.router.get) {
                addPexelsRoute(frame.router.get());
            }
        }

        function patchSelectFrame() {
            var SelectFrame = wp.media.view.MediaFrame.Select;
            if (selectFramePatched || !SelectFrame || !SelectFrame.prototype.initialize) {
                return;
            }

            var originalInitialize = SelectFrame.prototype.initialize;
            SelectFrame.prototype.initialize = function () {
                originalInitialize.apply(this, arguments);
                attachFrame(this);
            };
            selectFramePatched = true;
        }

        function patchFeaturedImageFrame() {
            if (featuredFramePatched || !wp.media.featuredImage.frame) {
                return;
            }

            var originalFrame = wp.media.featuredImage.frame;
            wp.media.featuredImage.frame = function () {
                var frame = originalFrame.apply(this, arguments);
                attachFrame(frame);
                return frame;
            };
            featuredFramePatched = true;
            attachFrame(wp.media.featuredImage._frame);
        }

        patchSelectFrame();
        patchFeaturedImageFrame();
    }

    boot();
}(jQuery, window));
