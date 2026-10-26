chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (!message || typeof message.type !== 'string') {
    return false;
  }
  if (message.type === 'compare' && typeof message.query === 'string') {
    lookup(message.query.slice(0, 120))
      .then((result) => sendResponse(result))
      .catch(() => sendResponse({ error: 'unavailable' }));
    return true;
  }
  if (message.type === 'open' && typeof message.offerId === 'string') {
    openStore(message.offerId)
      .then((result) => sendResponse(result))
      .catch(() => sendResponse({ error: 'unavailable' }));
    return true;
  }
  return false;
});

async function lookup(query) {
  const saved = await chrome.storage.local.get(['apiBase', 'token']);
  const apiBase = typeof saved.apiBase === 'string' ? saved.apiBase.replace(/\/+$/, '') : '';
  const token = typeof saved.token === 'string' ? saved.token : '';
  if (!allowedOrigin(apiBase) || token.length < 16 || query.trim().length < 2) {
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
    offer_id: String(offer.id ?? ''),
    store: String(offer.store ?? ''),
    total_minor: Number(offer.total_minor),
    currency: String(offer.currency ?? 'USD'),
    minor_unit: Number(body.data.minor_unit ?? 2),
    shipping_known: Boolean(offer.shipping_known),
    tax_known: Boolean(offer.tax_known),
  };
}

async function openStore(offerId) {
  const saved = await chrome.storage.local.get(['apiBase', 'token']);
  const apiBase = typeof saved.apiBase === 'string' ? saved.apiBase.replace(/\/+$/, '') : '';
  const token = typeof saved.token === 'string' ? saved.token : '';
  if (!allowedOrigin(apiBase) || token.length < 16 || !/^[a-f0-9]{24}$/.test(offerId)) {
    return { error: 'unavailable' };
  }
  const response = await fetch(`${apiBase}/api/v1/compare/clicks`, {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ offer_id: offerId }),
  });
  if (!response.ok) {
    return { error: 'unavailable' };
  }
  const body = await response.json();
  const url = body?.data?.url;
  return typeof url === 'string' && url.startsWith('https://') ? { url } : { error: 'unavailable' };
}

function allowedOrigin(apiBase) {
  let url;
  try {
    url = new URL(apiBase);
  } catch (_error) {
    return false;
  }
  if (url.username !== '' || url.password !== '') {
    return false;
  }
  if (url.protocol === 'https:') {
    return true;
  }
  return url.protocol === 'http:' && (url.hostname === '127.0.0.1' || url.hostname === 'localhost');
}
