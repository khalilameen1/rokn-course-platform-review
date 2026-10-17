{{-- Click-to-enlarge on the native <dialog> (focus handling and Esc built in). Arrow keys follow reading direction: in RTL, ArrowLeft moves forward. --}}
<dialog class="lightbox" id="portfolio-lightbox" aria-label="عرض الصورة">
    <figure><img id="portfolio-lightbox-image" alt=""><figcaption id="portfolio-lightbox-caption"></figcaption></figure>
    <span class="lb-count" id="portfolio-lightbox-count" aria-live="polite"></span>
    <button type="button" class="lb-button lb-close" data-lightbox-action="close" aria-label="إغلاق">&times;</button>
    <button type="button" class="lb-button lb-prev" data-lightbox-action="prev" aria-label="الصورة السابقة">&#8250;</button>
    <button type="button" class="lb-button lb-next" data-lightbox-action="next" aria-label="الصورة التالية">&#8249;</button>
</dialog>
<script>
(function () {
    var dialog = document.getElementById('portfolio-lightbox');
    if (!dialog || typeof dialog.showModal !== 'function') return;
    var image = document.getElementById('portfolio-lightbox-image');
    var caption = document.getElementById('portfolio-lightbox-caption');
    var count = document.getElementById('portfolio-lightbox-count');
    var items = [], index = 0, opener = null;
    var rtl = (document.documentElement.getAttribute('dir') || '').toLowerCase() === 'rtl';

    function show(i) {
        index = (i + items.length) % items.length;
        var link = items[index], thumb = link.querySelector('img');
        image.src = link.getAttribute('href');
        image.alt = thumb ? thumb.alt : '';
        caption.textContent = link.getAttribute('data-caption') || '';
        count.textContent = items.length > 1 ? (index + 1) + ' من ' + items.length : '';
        dialog.querySelectorAll('.lb-prev,.lb-next').forEach(function (button) { button.hidden = items.length < 2; });
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest && event.target.closest('a[data-lightbox]');
        if (link) {
            event.preventDefault();
            items = Array.prototype.slice.call(document.querySelectorAll('a[data-lightbox="' + link.getAttribute('data-lightbox') + '"]'));
            opener = link;
            show(items.indexOf(link));
            dialog.showModal();
            return;
        }
        var play = event.target.closest && event.target.closest('.video-play');
        if (play) {
            event.preventDefault();
            var box = play.parentElement, poster = play.querySelector('img');
            if (!box.style.aspectRatio && poster && poster.naturalWidth && poster.naturalHeight) {
                box.style.aspectRatio = poster.naturalWidth + ' / ' + poster.naturalHeight;
            }
            var frame = document.createElement('iframe');
            frame.src = box.getAttribute('data-video-src');
            frame.title = box.getAttribute('data-video-title') || '';
            frame.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
            frame.allowFullscreen = true;
            frame.referrerPolicy = 'no-referrer';
            box.replaceChild(frame, play);
            frame.focus();
        }
    });

    dialog.addEventListener('click', function (event) {
        var action = event.target.closest && event.target.closest('[data-lightbox-action]');
        if (action) {
            var name = action.getAttribute('data-lightbox-action');
            if (name === 'close') dialog.close();
            else show(index + (name === 'next' ? 1 : -1));
        } else if (event.target === dialog) {
            dialog.close();
        }
    });
    dialog.addEventListener('keydown', function (event) {
        if (items.length < 2 || (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight')) return;
        event.preventDefault();
        var forward = event.key === (rtl ? 'ArrowLeft' : 'ArrowRight');
        show(index + (forward ? 1 : -1));
    });
    dialog.addEventListener('close', function () {
        image.removeAttribute('src');
        if (opener) opener.focus();
    });
})();
</script>
