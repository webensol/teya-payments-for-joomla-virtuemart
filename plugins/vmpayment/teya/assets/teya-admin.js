document.addEventListener('DOMContentLoaded', () => {
  const container = document.querySelectorAll('.teya-onboarding-container')[0];
  const messages = JSON.parse(container?.dataset.messages || '{}');
  const text = key => messages[key] || key;
  const mode = () => {
    const fields = [...document.querySelectorAll('input,select')].filter(el => (el.name || '').includes('sandbox'));
    const value = fields.find(el => el.type === 'radio' && el.checked) || fields.find(el => el.tagName === 'SELECT') || fields.find(el => el.type === 'checkbox') || fields.find(el => el.type === 'hidden');
    document.querySelectorAll('.teya-onboarding-container').forEach(container => {
      const selected = value ? ((value.type === 'checkbox' ? value.checked : value.value === '1') ? 'test' : 'live') : container.dataset.activeMode;
      container.dataset.activeMode = selected;
      container.querySelectorAll('.teya-connect-option').forEach(option => { option.hidden = option.dataset.teyaMode !== selected; });
    });
  };
  document.addEventListener('change', mode); mode();
  async function request(url, body) {
    const response = await fetch(url, {credentials:'same-origin', cache:'no-store', method:body ? 'POST' : 'GET', body});
    let data;
    try { data = await response.json(); }
    catch (error) { throw new Error(text('REQUEST_FAILED')); }
    if (!response.ok || data.error) throw new Error(data.error || text('REQUEST_FAILED'));
    return data;
  }
  function display(option, state) {
    if (state.environment !== option.dataset.teyaMode) throw new Error(text('ENVIRONMENT_MISMATCH'));
    const connected = state.connected === true;
    const label = option.querySelector('.teya-environment-status');
    label.textContent = connected ? option.dataset.connectedLabel : option.dataset.disconnectedLabel;
    label.classList.toggle('is-connected', connected);
    label.classList.toggle('is-disconnected', !connected);
    option.querySelector('.teya-connect-text').textContent = connected ? option.dataset.reconnectLabel : option.dataset.connectLabel;
    const disconnect = option.querySelector('.teya-disconnect-button');
    if (disconnect) disconnect.hidden = !connected;
  }
  document.querySelectorAll('.teya-connect-option').forEach(option => {
    const connectButton = option.querySelector('.teya-connect-button');
    const output = option.querySelector('.teya-poll-status');
    const statusUrl = connectButton.dataset.teyaStatusUrl;
    let generation = 0;
    // Render from a fresh DB-backed response, even when the form was loaded before a callback.
    request(statusUrl).then(state => { if (generation === 0) display(option,state); }).catch(error => { if (generation === 0) output.textContent = error.message; });
    option.querySelectorAll('.teya-connect-button,.teya-disconnect-button').forEach(button => {
      button.addEventListener('click', async event => {
        event.preventDefault();
        if (button.dataset.busy) return;
        button.dataset.busy = '1';
        const current = ++generation;
        const connect = button === connectButton;
        const popup = connect ? window.open('about:blank','_blank') : null;
        if (popup) popup.opener = null;
        try {
          const status = await request(statusUrl);
          const body = new URLSearchParams(); body.set(status.csrf_token,'1');
          const result = await request(button.href,body);
          if (!connect) { display(option,result); output.textContent = ''; delete button.dataset.busy; return; }
          output.textContent = text('WAITING_CONNECTION');
          if (popup) popup.location.href = result.url;
          else {
            const link = document.createElement('a'); link.href = result.url; link.target = '_blank'; link.rel = 'noopener'; link.textContent = ' ' + text('OPEN_TEYA'); output.appendChild(link);
          }
          const end = Date.now() + 30 * 60 * 1000;
          const poll = async () => {
            if (current !== generation) { delete button.dataset.busy; return; }
            try {
              const next = await request(statusUrl);
              if (current !== generation) { delete button.dataset.busy; return; }
              if (next.connected && next.completed_attempt === result.attempt) {
                display(option,next);
                output.textContent = text('CONNECTION_SAVED').replace('%s', text(option.dataset.teyaMode === 'test' ? 'ENV_TEST' : 'ENV_LIVE'));
                delete button.dataset.busy; return;
              }
              if (Date.now() < end) window.setTimeout(poll,3000);
              else { output.textContent = text('CONNECTION_EXPIRED'); delete button.dataset.busy; }
            } catch (error) { output.textContent = error.message; delete button.dataset.busy; }
          };
          window.setTimeout(poll,2000);
        } catch (error) {
          if (popup) popup.close(); output.textContent = error.message; delete button.dataset.busy;
        }
      });
    });
  });
});
