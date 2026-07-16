(function (root, factory) {
    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.PdfBuilderCoords = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    return {
        /**
         * Converts canvas selection box coordinates to PDF points and dimensions in mm.
         *
         * @param {number} x1Px - Selection top-left X in pixels
         * @param {number} y1Px - Selection top-left Y in pixels
         * @param {number} x2Px - Selection bottom-right X in pixels
         * @param {number} y2Px - Selection bottom-right Y in pixels
         * @param {number} scale - pdf.js render scale
         * @param {number} pageHeightPt - PDF page height in points
         * @returns {{x: number, y: number, w: number, h: number}}
         */
        toPdfCoords: function (x1Px, y1Px, x2Px, y2Px, scale, pageHeightPt) {
            var wPx = x2Px - x1Px;
            var hPx = y2Px - y1Px;

            var wPt = wPx / scale;
            var hPt = hPx / scale;

            var xPdf = x1Px / scale;
            var yPdf = pageHeightPt - (y2Px / scale);

            var wMm = wPt * 25.4 / 72;
            var hMm = hPt * 25.4 / 72;

            return {
                x: parseFloat(xPdf.toFixed(2)),
                y: parseFloat(yPdf.toFixed(2)),
                w: parseFloat(wMm.toFixed(2)),
                h: parseFloat(hMm.toFixed(2))
            };
        }
    };
}));
