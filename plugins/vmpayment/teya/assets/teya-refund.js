document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.teya-refund-panel').forEach(function (panel) {
    var messages = JSON.parse(panel.dataset.messages || '{}');
    var text = function (key) { return messages[key] || key; };
    var submit = panel.querySelector('.teya-refund-submit');
    var retry = panel.querySelector('.teya-refund-retry');
    var amount = panel.querySelector('.teya-refund-amount');
    var message = panel.querySelector('.teya-refund-message');
    var sync = panel.querySelector('.teya-refund-sync');
    if (sync) {
      sync.addEventListener('click', async function () {
        if (sync.disabled) return;
        sync.disabled = true;
        var body = new URLSearchParams({teya_action:'refund_retry',order_id:panel.dataset.order,refund_key:panel.dataset.key});
        body.set(panel.dataset.token,'1');
        try {
          var response = await fetch(panel.dataset.url,{method:'POST',credentials:'same-origin',body:body});
          var data = await response.json();
          if (!response.ok || data.error || data.pending || data.remaining !== 0) throw new Error(text('REFUND_STATUS_SYNC_ERROR'));
          window.location.reload();
        } catch (error) { message.textContent=text('REFUND_STATUS_SYNC_ERROR'); }
      });
    }
    if (!submit || !retry) { return; }
    var pending = panel.dataset.pending === '1';
    var busy = false;
    var blocked = panel.dataset.blocked === '1';
    var timer;
    function update() {
      submit.disabled = busy || pending;
      amount.disabled = busy || pending;
      retry.hidden = !pending;
      retry.disabled = busy;
    }
    function send(resume) {
      if (busy) { return; }
      clearTimeout(timer);
      busy = true;
      update();
      var body = new URLSearchParams();
      body.set(panel.dataset.token, '1');
      body.set('order_id', panel.dataset.order);
      body.set('amount', amount.value.trim());
      body.set('refund_key', panel.dataset.key);
      body.set('teya_action', resume ? 'refund_retry' : 'refund');
      message.textContent = text('REFUND_PENDING');
      fetch(panel.dataset.url, {method: 'POST', credentials: 'same-origin', body: body})
        .then(function (response) { return response.json().then(function (data) {
          if (!response.ok) { throw new Error(text('REFUND_ERROR')); }
          return data;
        }); })
        .then(function (data) {
          pending = !!data.pending;
          if (pending) {
            blocked = !!data.blocked;
            message.textContent = blocked ? text('REFUND_BLOCKED') : text('REFUND_PENDING');
            if (!blocked) { timer = setTimeout(function () { send(true); }, Math.max(5000, ((data.retry_at || 0) * 1000) - Date.now())); }
          } else {
            message.textContent = text('REFUND_CONFIRMED');
            window.location.reload();
          }
        })
        .catch(function (error) {
          // An interrupted response may still have reached Teya. Reopen the
          // panel to read its durable state before submitting another amount.
          pending = true;
          message.textContent = text('REFUND_ERROR') + ' ' + text('REFUND_REFRESH');
        })
        .finally(function () { busy = false; update(); });
    }
    submit.addEventListener('click', function () {
      if (window.confirm(amount.value.trim() + ' — ' + text('REFUND_CONFIRM'))) { send(false); }
    });
    retry.addEventListener('click', function () {
      if (window.confirm(text('REFUND_CONFIRM_RETRY'))) { send(true); }
    });
    update();
    if (pending && !blocked) { timer = setTimeout(function () { send(true); }, 5000); }
  });
});
