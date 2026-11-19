// Admin > Storefront content: search, expand/collapse, page addresses from titles, character counts, and a drag-and-drop
// image field for banners. A dropped or chosen image is checked, drawn on a canvas as a preview, and shrunk on the canvas
// when it is over 2 MB. The result is put into the real file input, so the form still uploads it as before.
// Everything works without this script; the forms post as before.
(function () {
  'use strict';
  var root = document.querySelector('.cc-page');
  if (!root) return;

  var MAX_BYTES = 2 * 1024 * 1024;
  var TYPES = ['image/jpeg', 'image/png', 'image/webp'];

  // ---- search: show only the banners and pages that match ----------------------------------------------------------
  var filter = root.querySelector('[data-content-filter]');
  var items = Array.prototype.slice.call(root.querySelectorAll('.cc-item[data-search]'));
  var empty = root.querySelector('[data-content-empty]');
  function applyFilter() {
    var q = (filter.value || '').trim().toLowerCase();
    var shown = 0;
    items.forEach(function (item) {
      var match = q === '' || item.getAttribute('data-search').indexOf(q) !== -1;
      item.hidden = !match;
      if (match) shown++;
    });
    if (empty) empty.hidden = shown !== 0 || items.length === 0;
  }
  if (filter) {
    filter.addEventListener('input', applyFilter);
    applyFilter();
  }

  // ---- expand / collapse every item ---------------------------------------------------------------------------------
  root.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('[data-expand]');
    if (!btn) return;
    var open = btn.getAttribute('data-expand') === 'open';
    items.forEach(function (item) { item.open = open; });
  });

  // ---- page address follows the title, until someone edits the address itself ---------------------------------------
  function slugify(text) {
    return (text || '')
      .normalize('NFKD').replace(/[̀-ͯ]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 120);
  }
  Array.prototype.forEach.call(root.querySelectorAll('[data-slug-target]'), function (slug) {
    if (slug.hasAttribute('data-slug-locked')) return; // an existing page keeps its address
    var form = slug.closest('form');
    var title = form && form.querySelector('[data-slug-source]');
    if (!title) return;
    slug.addEventListener('input', function () { slug.dataset.touched = '1'; });
    title.addEventListener('input', function () {
      if (slug.dataset.touched !== '1') slug.value = slugify(title.value);
    });
  });

  // ---- character counts for fields with a maxlength -----------------------------------------------------------------
  function paintCount(field) {
    var label = field.closest('label');
    var out = label && label.querySelector('.cc-count');
    if (!out) return;
    var max = Number(field.getAttribute('maxlength') || 0);
    out.textContent = max ? field.value.length + ' / ' + max : '';
  }
  Array.prototype.forEach.call(root.querySelectorAll('[data-counter]'), function (field) {
    paintCount(field);
    field.addEventListener('input', function () { paintCount(field); });
  });

  // ---- rich-text body for pages -----------------------------------------------------------------------------------------
  // The editor offers only what the server keeps (paragraphs, headings, bold, italic, underline, strike, quotes, lists,
  // links). The original textarea stays in the form and receives the HTML, so the save path is unchanged.
  var RICH_TOOLS = [
    { cmd: 'bold', label: 'B', title: 'Bold', style: 'font-weight:700' },
    { cmd: 'italic', label: 'I', title: 'Italic', style: 'font-style:italic' },
    { cmd: 'underline', label: 'U', title: 'Underline', style: 'text-decoration:underline' },
    { cmd: 'strikeThrough', label: 'S', title: 'Strikethrough', style: 'text-decoration:line-through' },
    { cmd: 'formatBlock:h2', label: 'H2', title: 'Heading' },
    { cmd: 'formatBlock:h3', label: 'H3', title: 'Small heading' },
    { cmd: 'formatBlock:p', label: 'Text', title: 'Normal text' },
    { cmd: 'formatBlock:blockquote', label: 'Quote', title: 'Quote' },
    { cmd: 'insertUnorderedList', label: '• List', title: 'Bulleted list' },
    { cmd: 'insertOrderedList', label: '1. List', title: 'Numbered list' },
    { cmd: 'link', label: 'Link', title: 'Add link' },
    { cmd: 'unlink', label: 'Unlink', title: 'Remove link' },
    { cmd: 'removeFormat', label: 'Clear', title: 'Clear formatting' }
  ];
  function runRich(editor, cmd) {
    editor.focus();
    if (cmd.indexOf('formatBlock:') === 0) {
      document.execCommand('formatBlock', false, cmd.slice('formatBlock:'.length));
    } else if (cmd === 'link') {
      var url = (window.prompt('Web address (https://… or a page on this site starting with /)', 'https://') || '').trim();
      if (/^https:\/\/\S+$/.test(url) || /^\/(?!\/)\S*$/.test(url)) document.execCommand('createLink', false, url);
    } else {
      document.execCommand(cmd, false, null);
    }
  }
  function setupRich(textarea) {
    var label = textarea.closest('label');
    var wrap = document.createElement('div');
    wrap.className = 'cc-rich-wrap';
    var bar = document.createElement('div');
    bar.className = 'cc-rich-bar';
    bar.setAttribute('role', 'toolbar');
    bar.setAttribute('aria-label', 'Formatting');
    var editor = document.createElement('div');
    editor.className = 'cc-rich';
    editor.contentEditable = 'true';
    editor.setAttribute('role', 'textbox');
    editor.setAttribute('aria-multiline', 'true');
    editor.setAttribute('aria-label', 'Page body');
    editor.innerHTML = textarea.value;
    RICH_TOOLS.forEach(function (t) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'cc-rich-btn';
      b.textContent = t.label;
      b.title = t.title;
      b.setAttribute('aria-label', t.title);
      if (t.style) b.setAttribute('style', t.style);
      b.dataset.cmd = t.cmd;
      bar.appendChild(b);
    });
    var stateOf = { bold: 'bold', italic: 'italic', underline: 'underline', strikeThrough: 'strikeThrough' };
    function paintState() {
      Array.prototype.forEach.call(bar.querySelectorAll('[data-cmd]'), function (b) {
        var c = stateOf[b.dataset.cmd];
        if (c) b.setAttribute('aria-pressed', document.queryCommandState(c) ? 'true' : 'false');
      });
    }
    function sync() {
      textarea.value = editor.innerHTML;
      textarea.dispatchEvent(new Event('input')); // keeps the character count in step
    }
    // Keep the text selection when a button is pressed.
    bar.addEventListener('mousedown', function (e) { e.preventDefault(); });
    bar.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('[data-cmd]');
      if (!b) return;
      runRich(editor, b.dataset.cmd);
      paintState();
      sync();
    });
    editor.addEventListener('input', sync);
    editor.addEventListener('keyup', paintState);
    editor.addEventListener('mouseup', paintState);
    editor.addEventListener('paste', function (e) {
      // Paste as plain text, so pasted fonts and colours do not come with it.
      e.preventDefault();
      var text = (e.clipboardData || window.clipboardData).getData('text/plain');
      document.execCommand('insertText', false, text);
    });
    if (textarea.form) textarea.form.addEventListener('submit', sync);

    textarea.parentNode.insertBefore(wrap, textarea);
    wrap.appendChild(bar);
    wrap.appendChild(editor);
    wrap.appendChild(textarea);
    textarea.hidden = true;
    if (label) label.classList.add('cc-rich-label');
    sync();
  }
  Array.prototype.forEach.call(root.querySelectorAll('textarea[name="body"]'), function (ta) {
    if (window.getSelection && document.queryCommandSupported && document.queryCommandSupported('bold')) setupRich(ta);
  });

  // ---- drag-and-drop image field ----------------------------------------------------------------------------------------
  function fail(zone, message) {
    var msg = zone.querySelector('.cc-drop-msg');
    if (msg) { msg.textContent = message; msg.hidden = false; }
  }
  function clearFail(zone) {
    var msg = zone.querySelector('.cc-drop-msg');
    if (msg) { msg.textContent = ''; msg.hidden = true; }
  }
  // Decodes the picture without a blob: URL (the site's content policy allows only data: and same-origin images).
  // Resolves { source, w, h }, where source can be drawn onto a canvas.
  function loadPicture(file) {
    if (window.createImageBitmap) {
      return createImageBitmap(file).then(function (bmp) {
        return { source: bmp, w: bmp.width, h: bmp.height };
      }, function () { throw new Error('That file is not a picture we can read.'); });
    }
    return new Promise(function (resolve, reject) {
      var reader = new FileReader();
      reader.onerror = function () { reject(new Error('That file is not a picture we can read.')); };
      reader.onload = function () {
        var img = new Image();
        img.onload = function () { resolve({ source: img, w: img.naturalWidth, h: img.naturalHeight }); };
        img.onerror = function () { reject(new Error('That file is not a picture we can read.')); };
        img.src = reader.result;
      };
      reader.readAsDataURL(file);
    });
  }
  // Draws the picture onto a new canvas, scaled so its longest side is at most maxSide.
  function drawScaled(pic, maxSide) {
    var scale = Math.min(1, maxSide / Math.max(pic.w, pic.h));
    var canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(pic.w * scale));
    canvas.height = Math.max(1, Math.round(pic.h * scale));
    canvas.getContext('2d').drawImage(pic.source, 0, 0, canvas.width, canvas.height);
    return canvas;
  }
  function encode(canvas, type, quality) {
    return new Promise(function (resolve) { canvas.toBlob(resolve, type, quality); });
  }
  // A picture over the limit is shrunk on the canvas: smaller first, then re-encoded at a lower quality.
  function shrink(pic, baseName) {
    var steps = [[1600, 'image/webp', 0.85], [1600, 'image/jpeg', 0.82], [1200, 'image/jpeg', 0.75], [900, 'image/jpeg', 0.7]];
    return steps.reduce(function (chain, step) {
      return chain.then(function (done) {
        if (done) return done;
        return encode(drawScaled(pic, step[0]), step[1], step[2]).then(function (blob) {
          if (!blob || blob.size > MAX_BYTES) return null;
          var ext = step[1] === 'image/webp' ? 'webp' : 'jpg';
          return new File([blob], baseName + '.' + ext, { type: step[1], lastModified: Date.now() });
        });
      });
    }, Promise.resolve(null));
  }
  function showPreview(zone, pic) {
    var canvas = zone.querySelector('.cc-canvas');
    var scale = Math.min(1, 320 / pic.w);
    canvas.width = Math.round(pic.w * scale);
    canvas.height = Math.round(pic.h * scale);
    canvas.getContext('2d').drawImage(pic.source, 0, 0, canvas.width, canvas.height);
    canvas.hidden = false;
    zone.classList.add('has-image');
    zone.querySelector('.cc-drop-text').textContent = 'Image ready. Drop another to replace it, or click to choose.';
  }
  // Puts the chosen file into the real input, so the form uploads exactly what is shown.
  function setInput(input, file) {
    var dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    input._ours = true; // the change event below is ours, not the person's: the handler must not process it again
    input.dispatchEvent(new Event('change', { bubbles: true }));
    input._ours = false;
  }
  function handleFile(zone, input, file) {
    clearFail(zone);
    if (!file) return;
    if (TYPES.indexOf(file.type) === -1) {
      fail(zone, 'Choose a JPG, PNG or WebP picture.');
      return;
    }
    zone.classList.add('is-busy');
    loadPicture(file).then(function (pic) {
      showPreview(zone, pic);
      if (file.size <= MAX_BYTES) return setInput(input, file);
      return shrink(pic, file.name.replace(/\.[^.]+$/, '')).then(function (small) {
        if (!small) { fail(zone, 'That picture is still over 2 MB after shrinking. Choose a smaller one.'); return; }
        setInput(input, small);
      });
    }).catch(function (err) {
      fail(zone, err.message || 'The picture could not be used. Try another one.');
    }).then(function () {
      zone.classList.remove('is-busy');
    });
  }
  Array.prototype.forEach.call(root.querySelectorAll('input[type=file][data-preview]'), function (input) {
    var label = input.closest('label');
    if (!label || !window.DataTransfer) return; // without DataTransfer the plain file input still works
    var zone = document.createElement('div');
    zone.className = 'cc-drop';
    zone.tabIndex = 0;
    zone.setAttribute('role', 'button');
    zone.setAttribute('aria-label', 'Choose or drop a banner image');
    zone.innerHTML = '<span class="cc-drop-text">Drop a picture here, or click to choose one.<br><small class="muted">Best size 1920 × 640 px (3:1). JPG, PNG or WebP, up to 2 MB; larger pictures are shrunk for you.</small></span>'
      + '<canvas class="cc-canvas" hidden></canvas>'
      + '<span class="cc-drop-msg" role="alert" hidden></span>';
    input.classList.add('cc-file-input');
    input.parentNode.insertBefore(zone, input);
    var old = label.querySelector('.cc-preview');
    if (old) old.hidden = true;

    zone.addEventListener('click', function () { input.click(); });
    zone.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
    });
    ['dragenter', 'dragover'].forEach(function (type) {
      zone.addEventListener(type, function (e) { e.preventDefault(); zone.classList.add('is-over'); });
    });
    ['dragleave', 'dragend'].forEach(function (type) {
      zone.addEventListener(type, function () { zone.classList.remove('is-over'); });
    });
    zone.addEventListener('drop', function (e) {
      e.preventDefault();
      zone.classList.remove('is-over');
      handleFile(zone, input, e.dataTransfer && e.dataTransfer.files[0]);
    });
    input.addEventListener('change', function () {
      if (input._ours) return;
      handleFile(zone, input, input.files && input.files[0]);
    });
  });
})();
