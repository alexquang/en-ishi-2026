document.addEventListener('DOMContentLoaded', () => {
    const tabs = document.querySelectorAll('.ptlr-tabs button');
    const rows = document.querySelectorAll('.ptlr-stream');

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            tabs.forEach(t => t.classList.remove('is-active'));
            tab.classList.add('is-active');

            const filter = tab.dataset.filter;
            rows.forEach(row => {
                if (!filter || filter === 'all') {
                    row.style.display = '';
                    return;
                }
                const text = row.dataset.title || '';
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });
    });
});
