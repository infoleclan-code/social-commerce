(function () {
  const priceMeta = document.querySelector('meta[property="product:price:amount"]')?.content
    || document.querySelector('[itemprop="price"]')?.getAttribute('content');
  if (typeof priceMeta !== 'string' || priceMeta.trim() === '') {
    return;
  }

  const title = document.querySelector('meta[property="og:title"]')?.content
    || document.querySelector('h1')?.textContent
    || document.title;
  const query = (title || '').replace(/\s+/g, ' ').trim().slice(0, 120);
  if (query.length < 2) {
    return;
  }

  chrome.runtime.sendMessage({ type: 'compare', query }, (result) => {
    if (!result || result.skipped || result.error || result.empty || !result.store) {
      return;
    }
    const pageMinor = majorToMinor(priceMeta, result.minor_unit);
    const best = formatMoney(result.total_minor, result.currency, result.minor_unit);
    const panel = document.createElement('aside');
    panel.setAttribute('data-compare', 'offer');
    const cheaper = pageMinor !== null && Number.isFinite(result.total_minor) && result.total_minor < pageMinor;
    const heading = document.createElement('strong');
    heading.textContent = cheaper ? `Cheaper at ${result.store}` : `Best price: ${result.store}`;
    const detail = document.createElement('div');
    const parts = [best];
    if (!result.shipping_known) {
      parts.push('shipping unknown');
    }
    if (!result.tax_known) {
      parts.push('tax unknown');
    }
    detail.textContent = parts.join(' · ');
    panel.append(heading, detail);
    if (typeof result.offer_id === 'string' && result.offer_id !== '') {
      const open = document.createElement('button');
      open.type = 'button';
      open.textContent = 'Open store';
      open.addEventListener('click', () => {
        chrome.runtime.sendMessage({ type: 'open', offerId: result.offer_id }, (opened) => {
          if (opened && typeof opened.url === 'string' && opened.url.startsWith('https://')) {
            window.open(opened.url, '_blank', 'noopener');
          }
        });
      });
      panel.append(open);
    }
    document.documentElement.append(panel);
  });

  function majorToMinor(raw, exponent) {
    if (typeof raw !== 'string' || !/^\d+(\.\d{1,2})?$/.test(raw.trim())) {
      return null;
    }
    const exp = Number.isInteger(exponent) && exponent >= 0 && exponent <= 3 ? exponent : 2;
    const value = Number(raw.trim());
    if (!Number.isFinite(value)) {
      return null;
    }
    return Math.round(value * (10 ** exp));
  }

  function formatMoney(minor, currency, exponent) {
    const exp = Number.isInteger(exponent) && exponent >= 0 && exponent <= 3 ? exponent : 2;
    try {
      return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(Number(minor) / (10 ** exp));
    } catch (_error) {
      return `${currency} ${Number(minor) / (10 ** exp)}`;
    }
  }
})();
