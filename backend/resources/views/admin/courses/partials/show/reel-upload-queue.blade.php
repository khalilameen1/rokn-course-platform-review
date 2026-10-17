{{-- Multi-reel upload queue. Rows are rendered by course-studio-reel-queue.js. --}}
<section class="studio-reel-queue" id="studioReelQueue" hidden aria-labelledby="studioReelQueueTitle">
    <header class="studio-reel-queue__header">
        <div>
            <span>رفع عدة مقاطع</span>
            <h3 id="studioReelQueueTitle">قائمة الرفع</h3>
            <p id="studioReelQueueSummary" aria-live="polite"></p>
        </div>
        <div class="studio-reel-queue__actions">
            <button type="button" class="studio-inline-secondary" data-reel-queue-cancel-all data-upload-escape>إيقاف الكل</button>
            <button type="button" class="studio-inline-secondary" data-reel-queue-clear data-upload-escape>إخفاء المكتمل</button>
        </div>
    </header>
    <p class="studio-reel-queue__hint">كل ملف يصبح مقطعًا في آخر الوحدة بعنوان اسم الملف. نرفع ملفًا واحدًا في كل مرة، وتبقى باقي أدوات الكورس متوقفة حتى تنتهي القائمة. يمكنك تعديل العنوان قبل بدء رفعه، وتعديل باقي التفاصيل من المقطع بعد حفظه.</p>
    <ol class="studio-reel-queue__list" id="studioReelQueueList"></ol>
    <input type="file" id="studioReelQueueInput" accept="video/mp4,video/quicktime,video/x-msvideo,video/webm" multiple hidden>
</section>
