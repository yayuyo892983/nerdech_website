(function () {
    const lists = document.querySelectorAll('[data-news-list]');
    if (!lists.length) return;

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text) node.textContent = text;
        return node;
    }

    function renderItem(item, headingTag) {
        const article = el('article', 'news-item');
        const time = el('time', 'news-item__date', item.display_date || item.date || '');
        time.dateTime = item.date || '';

        const content = el('div', 'news-item__content');
        content.appendChild(el(headingTag, 'news-item__title', item.title || ''));
        content.appendChild(el('p', 'news-item__body', item.body || ''));

        if (item.link_url && item.link_label) {
            const link = el('a', 'news-item__link', item.link_label);
            link.href = item.link_url;
            link.target = '_blank';
            link.rel = 'noopener';
            content.appendChild(link);
        }

        article.append(time, content);
        return article;
    }

    fetch('/news-feed.php', { cache: 'no-store' })
        .then(response => {
            if (!response.ok) throw new Error('news feed unavailable');
            return response.json();
        })
        .then(payload => {
            const news = Array.isArray(payload.news) ? payload.news : [];

            lists.forEach(list => {
                const limit = Number.parseInt(list.dataset.newsLimit || '', 10);
                const headingTag = list.dataset.newsHeading || 'h2';
                const visibleItems = Number.isFinite(limit) && limit > 0 ? news.slice(0, limit) : news;
                list.replaceChildren(...visibleItems.map(item => renderItem(item, headingTag)));
            });
        })
        .catch(() => {
            // Static HTML remains as the fallback.
        });
})();
