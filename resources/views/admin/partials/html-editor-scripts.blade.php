{{-- Keeps a raw HTML textarea, a Trix editor and a live preview in sync inside each [data-html-editor] container. --}}
<script src="https://cdn.jsdelivr.net/npm/trix@2.1.19/dist/trix.umd.min.js"></script>
<script>
    (() => {
        const initialiseHtmlEditors = () => {
            document.querySelectorAll('[data-html-editor]').forEach((container) => {
                if (container.dataset.initialised === '1') {
                    return;
                }

                container.dataset.initialised = '1';

                const source = container.querySelector('[data-html-description-source]');
                const preview = container.querySelector('[data-html-description-preview]');
                const trixInput = container.querySelector('[data-html-description-trix-input]');
                const trixEditor = container.querySelector('trix-editor');

                if (! source || ! preview || ! trixInput || ! trixEditor) {
                    return;
                }

                let syncingFromSource = false;
                let trixHasFocus = false;
                let trixRefreshTimeout = null;
                let trixChangeSuppressionTimeout = null;

                const setPreviewHtml = (html) => {
                    if (html.trim() === '') {
                        const placeholder = document.createElement('p');
                        placeholder.className = 'text-sm text-zinc-500';
                        placeholder.textContent = preview.dataset.emptyText || 'Podgląd pojawi się po wpisaniu treści HTML.';
                        preview.replaceChildren(placeholder);

                        return;
                    }

                    preview.innerHTML = html;
                };

                const refreshTrixFromSource = () => {
                    if (! trixEditor.editor) {
                        return;
                    }

                    syncingFromSource = true;
                    trixInput.value = source.value;
                    trixEditor.editor.loadHTML(source.value);

                    window.clearTimeout(trixChangeSuppressionTimeout);
                    trixChangeSuppressionTimeout = window.setTimeout(() => {
                        syncingFromSource = false;
                    }, 500);
                };

                const syncFromSource = () => {
                    trixInput.value = source.value;
                    setPreviewHtml(source.value);

                    window.clearTimeout(trixRefreshTimeout);
                    trixRefreshTimeout = window.setTimeout(refreshTrixFromSource, 350);
                };

                const syncFromTrix = () => {
                    if (syncingFromSource || ! trixHasFocus) {
                        return;
                    }

                    source.value = trixInput.value;
                    setPreviewHtml(source.value);
                };

                source.addEventListener('focus', () => {
                    trixHasFocus = false;
                });
                source.addEventListener('input', syncFromSource);
                trixEditor.addEventListener('trix-focus', () => {
                    trixHasFocus = true;
                });
                trixEditor.addEventListener('trix-change', syncFromTrix);
                trixEditor.addEventListener('trix-file-accept', (event) => event.preventDefault());
                trixEditor.addEventListener('trix-initialize', refreshTrixFromSource);

                setPreviewHtml(source.value);

                if (trixEditor.editor) {
                    refreshTrixFromSource();
                }
            });
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initialiseHtmlEditors);
        } else {
            initialiseHtmlEditors();
        }
    })();
</script>
