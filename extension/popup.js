const api = document.getElementById('api');
const token = document.getElementById('token');
const status = document.getElementById('status');

chrome.storage.local.get(['apiBase', 'token'], (saved) => {
  api.value = typeof saved.apiBase === 'string' ? saved.apiBase : '';
  token.value = typeof saved.token === 'string' ? saved.token : '';
});

document.getElementById('save').addEventListener('click', () => {
  const apiBase = api.value.trim().replace(/\/+$/, '').replace(/\/api\/v1$/, '');
  const access = token.value.trim();
  if (!/^https?:\/\//.test(apiBase) || access.length < 16) {
    status.textContent = 'Enter an http(s) API origin and a token.';
    return;
  }
  chrome.storage.local.set({ apiBase, token: access }, () => {
    status.textContent = 'Saved on this browser.';
  });
});
