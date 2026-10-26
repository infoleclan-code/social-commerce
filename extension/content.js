(function () {
  const title = document.querySelector('meta[property="og:title"]')?.content
    || document.querySelector('h1')?.textContent
    || document.title;
  const query = (title || '').replace(/\s+/g, ' ').trim().slice(0, 120);
  if (query.length < 2) {
    return;
  }

  const priceMeta = document.querySelector('meta[property="product:price:amount"]')?.content
    || document.querySelector('[itemprop="price"]')?.getAttribute('content');
  const pageMinor = majorToMinor(priceMeta);

  chrome.runtime.sendMessage({ type: 'compare', query }, (result) => {
    if (!result || result.skipped || result.error || result.empty || !result.store) {
      return;
    }
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
    document.documentElement.append(panel);
  });

  function majorToMinor(raw) {
    if (typeof raw !== 'string' || !/^\d+(\.\d{1,2})?$/.test(raw.trim())) {
      return null;
    }
    const [whole, fraction = ''] = raw.trim().split('.');
    return Number(whole) * 100 + Number((fraction + '00').slice(0, 2));
  }

  function formatMoney(minor, currency, exponent) {
    const scale = exponent === 0 ? 1 : 100;
    try {
      return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(Number(minor) / scale);
    } catch (_error) {
      return `${currency} ${Number(minor) / scale}`;
    }
  }
})();
