define([
    'jquery',
    'Magento_Ui/js/modal/modal',
    'MageOS_DigitalSignature/js/pdf-builder-coords',
    'pdfjs',
    'mage/translate'
], function ($, modal, coords, pdfjsLib, __) {
    'use strict';

    var pdfjs = pdfjsLib || window.pdfjsLib;

    if (window.digitalsignatureConfig && window.digitalsignatureConfig.workerUrl) {
        pdfjs.GlobalWorkerOptions.workerSrc = window.digitalsignatureConfig.workerUrl;
    }

    var pdfInstance = null;
    var currentPage = 1;
    var totalPages = 1;
    var scale = 1.0;
    var pageInstance = null;
    var isRendering = false;

    var startX = null, startY = null;
    var endX = null, endY = null;
    var isDragging = false;

    function renderPage(pageNumber) {
        if (isRendering || !pdfInstance) {
            return;
        }
        isRendering = true;
        currentPage = pageNumber;
        $('#pdf-page-num').text(currentPage);

        pdfInstance.getPage(pageNumber).then(function (page) {
            pageInstance = page;
            var viewport = page.getViewport({ scale: scale });
            var canvas = document.getElementById('pdf-canvas');
            var context = canvas.getContext('2d');
            canvas.height = viewport.height;
            canvas.width = viewport.width;

            // Reset selection box
            $('#selection-box').hide();
            startX = startY = endX = endY = null;

            var renderContext = {
                canvasContext: context,
                viewport: viewport
            };

            page.render(renderContext).promise.then(function () {
                isRendering = false;
            }, function () {
                isRendering = false;
            });
        });
    }

    return {
        open: function (options) {
            var file = options.file;
            var onSuccess = options.onSuccess;
            var onFailure = options.onFailure;

            // Clean up existing modal container if any
            $('#pdf-builder-modal-content').remove();

            var html = '<div id="pdf-builder-modal-content" style="display: none;">' +
                '    <div class="pdf-builder-controls" style="display: flex; gap: 15px; margin-bottom: 15px; align-items: center; justify-content: space-between; flex-wrap: wrap;">' +
                '        <div style="display: flex; align-items: center; gap: 8px;">' +
                '            <button id="pdf-prev-page" class="action-secondary" style="padding: 6px 12px; cursor: pointer;">' + __('Indietro') + '</button>' +
                '            <span style="font-weight: 600; font-size: 1.1em; color: #555;">' +
                '                ' + __('Pagina') + ' <span id="pdf-page-num">1</span> ' + __('di') + ' <span id="pdf-page-count">-</span>' +
                '            </span>' +
                '            <button id="pdf-next-page" class="action-secondary" style="padding: 6px 12px; cursor: pointer;">' + __('Avanti') + '</button>' +
                '        </div>' +
                '        <div style="display: flex; align-items: center; gap: 8px;">' +
                '            <button id="pdf-zoom-out" class="action-secondary" style="padding: 6px 12px; cursor: pointer;">' + __('Zoom -') + '</button>' +
                '            <span id="pdf-zoom-percent" style="font-weight: 600; font-size: 1.1em; color: #555;">100%</span>' +
                '            <button id="pdf-zoom-in" class="action-secondary" style="padding: 6px 12px; cursor: pointer;">' + __('Zoom +') + '</button>' +
                '        </div>' +
                '    </div>' +
                '    <div style="color: #666; font-size: 0.9em; margin-bottom: 12px; border-left: 3px solid #ffac00; padding-left: 8px;">' +
                '        ' + __('Seleziona la firma trascinando il mouse per tracciare un riquadro sulla pagina del PDF.') +
                '    </div>' +
                '    <div id="pdf-canvas-container" style="position: relative; overflow: auto; max-height: 480px; border: 1px solid #d1d5db; background-color: #f3f4f6; text-align: center; border-radius: 6px;">' +
                '        <div style="position: relative; display: inline-block; margin: 10px;">' +
                '            <canvas id="pdf-canvas" style="box-shadow: 0 4px 6px rgba(0,0,0,0.1); border-radius: 4px; display: block;"></canvas>' +
                '            <div id="pdf-overlay" style="position: absolute; top: 0; left: 0; cursor: crosshair; width: 100%; height: 100%;">' +
                '                <div id="selection-box" style="position: absolute; border: 2px dashed #ffac00; background-color: rgba(255, 172, 0, 0.15); border-radius: 3px; display: none;"></div>' +
                '            </div>' +
                '        </div>' +
                '    </div>' +
                '</div>';

            $('body').append(html);

            var modalElement = $('#pdf-builder-modal-content');

            var modalOptions = {
                type: 'slide',
                responsive: true,
                innerScroll: true,
                title: __('Posiziona la firma sul PDF'),
                buttons: [{
                    text: __('Annulla'),
                    class: 'action-secondary',
                    click: function () {
                        this.closeModal();
                        onFailure(__('Operazione annullata.'));
                    }
                }, {
                    text: __('Conferma Posizione'),
                    class: 'action-primary',
                    click: function () {
                        var self = this;
                        if (startX === null || endX === null || !pageInstance) {
                            alert(__('Traccia un riquadro sul PDF prima di confermare.'));
                            return;
                        }

                        var x1 = Math.min(startX, endX);
                        var y1 = Math.min(startY, endY);
                        var x2 = Math.max(startX, endX);
                        var y2 = Math.max(startY, endY);

                        var viewport1 = pageInstance.getViewport({ scale: 1.0 });
                        var pageHeightPt = viewport1.height;
                        var pdfCoords = coords.toPdfCoords(x1, y1, x2, y2, scale, pageHeightPt);

                        $('body').trigger('processStart');

                        $.post(window.digitalsignatureConfig.injectTagUrl, {
                            file: file,
                            page: currentPage,
                            x: pdfCoords.x,
                            y: pdfCoords.y,
                            w: pdfCoords.w,
                            h: pdfCoords.h
                        }).done(function (res) {
                            $('body').trigger('processStop');
                            if (res.error) {
                                alert(res.error);
                            } else {
                                self.closeModal();
                                onSuccess(res);
                            }
                        }).fail(function () {
                            $('body').trigger('processStop');
                            alert(__('Errore durante il salvataggio delle coordinate.'));
                        });
                    }
                }]
            };

            var popup = modal(modalOptions, modalElement);
            popup.openModal();

            // Bind Navigation Events
            $('#pdf-prev-page').on('click', function () {
                if (currentPage > 1) {
                    renderPage(currentPage - 1);
                }
            });

            $('#pdf-next-page').on('click', function () {
                if (currentPage < totalPages) {
                    renderPage(currentPage + 1);
                }
            });

            $('#pdf-zoom-in').on('click', function () {
                if (scale < 3.0) {
                    scale += 0.25;
                    $('#pdf-zoom-percent').text(Math.round(scale * 100) + '%');
                    renderPage(currentPage);
                }
            });

            $('#pdf-zoom-out').on('click', function () {
                if (scale > 0.5) {
                    scale -= 0.25;
                    $('#pdf-zoom-percent').text(Math.round(scale * 100) + '%');
                    renderPage(currentPage);
                }
            });

            // Selection overlay events
            $('#pdf-overlay').on('mousedown', function (e) {
                var offset = $(this).offset();
                startX = e.pageX - offset.left;
                startY = e.pageY - offset.top;
                isDragging = true;

                $('#selection-box').css({
                    left: startX + 'px',
                    top: startY + 'px',
                    width: 0,
                    height: 0,
                    display: 'block'
                });
            });

            $('#pdf-overlay').on('mousemove', function (e) {
                if (!isDragging) return;
                var offset = $(this).offset();
                var curX = e.pageX - offset.left;
                var curY = e.pageY - offset.top;

                var left = Math.min(startX, curX);
                var top = Math.min(startY, curY);
                var width = Math.abs(curX - startX);
                var height = Math.abs(curY - startY);

                $('#selection-box').css({
                    left: left + 'px',
                    top: top + 'px',
                    width: width + 'px',
                    height: height + 'px'
                });
            });

            $('#pdf-overlay').on('mouseup', function (e) {
                if (!isDragging) return;
                var offset = $(this).offset();
                endX = e.pageX - offset.left;
                endY = e.pageY - offset.top;
                isDragging = false;

                var width = Math.abs(endX - startX);
                var height = Math.abs(endY - startY);
                if (width < 5 || height < 5) {
                    $('#selection-box').hide();
                    startX = startY = endX = endY = null;
                }
            });

            // Load PDF bytes
            var pdfUrl = window.digitalsignatureConfig.previewTmpUrl + '?file=' + encodeURIComponent(file);

            $('body').trigger('processStart');
            var loadingTask = pdfjs.getDocument(pdfUrl);
            loadingTask.promise.then(function (pdf) {
                $('body').trigger('processStop');
                pdfInstance = pdf;
                totalPages = pdf.numPages;
                $('#pdf-page-count').text(totalPages);
                renderPage(1);
            }, function (error) {
                $('body').trigger('processStop');
                alert(__('Impossibile caricare il PDF temporaneo.'));
                onFailure(error.message || __('Errore di caricamento PDF.'));
            });
        }
    };
});
