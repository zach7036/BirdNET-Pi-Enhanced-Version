(function () {
  'use strict';
  var button = document.getElementById('copyVersionInfo');
  var text = document.getElementById('versionCopyText');
  var manual = document.getElementById('versionCopyManual');
  var status = document.getElementById('versionCopyStatus');
  if (!button || !text || !manual || !status) return;

  function fallback() {
    // Clipboard API is often unavailable on http://birdnetpi.local. Expose
    // selectable text even if the legacy copy command is also blocked.
    manual.open = true;
    text.focus();
    text.select();
    text.setSelectionRange(0, text.value.length);
    var copied = false;
    try { copied = document.execCommand('copy'); } catch (e) { /* Manual copy remains available. */ }
    if (copied) {
      manual.open = false;
      status.textContent = 'Version info copied.';
    } else {
      status.textContent = 'Automatic copy is unavailable. Copy the selected text below.';
    }
  }

  button.addEventListener('click', async function () {
    if (button.disabled) return;
    button.disabled = true;
    status.textContent = '';
    try {
      if (!navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') {
        fallback();
      } else {
        try {
          await navigator.clipboard.writeText(text.value);
          status.textContent = 'Version info copied.';
        } catch (e) { fallback(); }
      }
    } finally {
      button.disabled = false;
      if (status.textContent === 'Version info copied.') button.focus();
    }
  });
})();
