// Fit check: the rotated info panel is 1in deep. If the text is longer, shrink it a little
// (5pt down to 4.25pt); if it still doesn't fit, say so instead of printing cut-off text.
function fitInfoPanels() {
    var inners = document.querySelectorAll('.l-info-inner');
    if (!inners.length) return;
    var limit = inners[0].parentNode.clientWidth;          // 1in in CSS px (the panel is rotated)
    var sizes = [5, 4.75, 4.5, 4.25], used = 5;
    for (var i = 0; i < sizes.length; i++) {
        used = sizes[i];
        inners.forEach(function (el) { el.style.fontSize = used + 'pt'; });
        if (inners[0].offsetHeight <= limit) break;
    }
    var w = document.getElementById('fitWarning');
    if (inners[0].offsetHeight > limit) {
        w.textContent = '⚠ The ingredient text is too long for this label and will be cut off. Shorten sub-ingredients or use a larger label.';
        w.style.display = 'block';
        var sp = document.getElementById('barSpacer'); sp.style.height = (sp.offsetHeight + 34) + 'px';
    } else if (used < 5) {
        w.textContent = 'Text shrunk to ' + used + 'pt to fit the label.';
        w.style.background = '#fbefd2'; w.style.color = '#7a5200'; w.style.display = 'block';
        var sp2 = document.getElementById('barSpacer'); sp2.style.height = (sp2.offsetHeight + 34) + 'px';
    }
}

// On load: fit the text, then render barcodes so they're visible in the preview
window.addEventListener('load', function() {
    fitInfoPanels();
    document.querySelectorAll('.barcode-svg[data-sku]').forEach(function(svg) {
        var sku = svg.getAttribute('data-sku');
        if (!sku) return;
        try {
            JsBarcode(svg, sku, {
                format:       'CODE128',
                lineColor:    '#000',
                background:   '#ffffff',
                width:        1.1,
                height:       26,
                displayValue: true,
                fontSize:     7,
                margin:       1,
                textMargin:   1
            });
        } catch(e) { console.warn('Barcode render failed:', sku, e); }
    });
});

document.getElementById('printLabelsButton')?.addEventListener('click', function () { window.print(); });
