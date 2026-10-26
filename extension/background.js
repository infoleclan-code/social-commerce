chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (!message || message.type !== 'compare' || typeof message.query !== 'string') {
    return false;
  }
  lookup(message.query.slice(0, 120))
    .then((result) => sendResponse(result))
    .catch(() => sendResponse({ error: 'unavailable' }));
  return true;
});

async function lookup(query) {
  const saved = await chrome.storage.local.get(['apiBase', 'token']);
  const apiBase = typeof saved.apiBase === 'string' ? saved.apiBase.replace(/\/+$/, '') : '';
  const token = typeof saved.token === 'string' ? saved.token : '';
  if (!/^https?:\/\//.test(apiBase) || token.length < 16 || query.trim().length < 2) {
    return { skipped: true };
  }
  const url = `${apiBase}/api/v1/compare?q=${encodeURIComponent(query.trim())}`;
  const response = await fetch(url, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  });
  if (!response.ok) {
    return { error: 'unavailable' };
  }
  const body = await response.json();
  const offer = body?.data?.products?.[0]?.offers?.[0];
  if (!offer) {
    return { empty: true };
  }
  return {
    store: String(offer.store ?? ''),
    total_minor: Number(offer.total_minor),
    currency: String(offer.currency ?? 'USD'),
    minor_unit: Number(body.data.minor_unit ?? 2),
    shipping_known: Boolean(offer.shipping_known),
    tax_known: Boolean(offer.tax_known),
  };
}
