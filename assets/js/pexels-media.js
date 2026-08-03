(function ($, window) {
    'use strict';

    var config = window.ContentRankPexelsMedia || {};
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
        className: 'content-rank-pexels-browser',

        events: {
            'click .content-rank-pexels-search-button': 'search',
            'keydown .content-rank-pexels-search-input': 'handleKeydown',
            'click .content-rank-pexels-load-more': 'loadMore',
            'click .content-rank-pexels-card': 'useImage'
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
                '<div class="content-rank-pexels-toolbar">' +
                    '<input type="search" class="content-rank-pexels-search-input" placeholder="Buscar no Pexels" />' +
                    '<button type="button" class="button button-primary content-rank-pexels-search-button">Pesquisar</button>' +
                '</div>' +
                '<div class="content-rank-pexels-status" aria-live="polite"></div>' +
                '<div class="content-rank-pexels-grid"></div>' +
                '<div class="content-rank-pexels-more-wrap"><button type="button" class="button content-rank-pexels-load-more">Carregar mais</button></div>'
            );
            this.$more = this.$('.content-rank-pexels-load-more').hide();
            return this;
        },

        handleKeydown: function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                this.search();
            }
        },

        search: function () {
            var query = $.trim(this.$('.content-rank-pexels-search-input').val() || '');
            if (query === '') {
                this.setStatus('Digite uma busca para pesquisar no Pexels.', true);
                return;
            }

            this.page = 1;
            this.query = query;
            this.images = {};
            this.$('.content-rank-pexels-grid').empty();
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
                action: 'content_rank_pexels_search',
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
            var $grid = this.$('.content-rank-pexels-grid');
            if (!append) {
                $grid.empty();
            }

            images.forEach(function (image) {
                if (!image || !image.url || self.images[image.url]) {
                    return;
                }
                self.images[image.url] = image;

                var $card = $('<button type="button" class="content-rank-pexels-card"></button>');
                $('<img />', {
                    src: image.preview || image.url,
                    alt: image.alt || self.query,
                    loading: 'lazy'
                }).appendTo($card);
                $('<span class="content-rank-pexels-credit"></span>').text(image.photographer ? 'Foto: ' + image.photographer : 'Pexels').appendTo($card);
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
                action: 'content_rank_pexels_set_featured',
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
            this.$('.content-rank-pexels-status').text(message || '').toggleClass('is-error', !!isError);
        }
    });

        function addPexelsRoute(router) {
            if (!router) {
                return;
            }

            router.set({
                'content-rank-pexels': {
                    text: 'Pexels',
                    priority: 60,
                    content: 'content-rank-pexels'
                }
            });
        }

        function attachFrame(frame) {
            if (!frame || frame.contentRankPexelsAttached) {
                return;
            }

            frame.contentRankPexelsAttached = true;
            frame.on('router:render:browse', addPexelsRoute);
            frame.on('content:render:content-rank-pexels', function () {
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
