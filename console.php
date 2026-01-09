<?php
require_once __DIR__ . '/app.php';

session_start();
$settings = loadSettings();
$entryValue = (string)($settings['entry_value'] ?? 'admin123');
if (!($_SESSION['authenticated'] ?? false) || !($_SESSION['entry_valid'] ?? false)) {
    http_response_code(404);
    echo 'Not Found';
    exit;
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>内容同步控制台</title>
  <style>
    body { font-family: "Noto Sans SC", "Microsoft YaHei", sans-serif; background: #eef2f7; margin: 0; color: #1f2937; }
    .shell { display: grid; grid-template-columns: 220px 1fr; min-height: 100vh; }
    .sidebar { background: #0f172a; color: #e2e8f0; padding: 24px 20px; display: flex; flex-direction: column; gap: 20px; }
    .sidebar h1 { font-size: 18px; margin: 0; }
    .sidebar p { font-size: 13px; color: #94a3b8; margin: 0; }
    .nav a { display: block; margin-bottom: 10px; color: #e2e8f0; text-decoration: none; font-size: 14px; }
    .nav a:hover { color: #fff; }
    main { padding: 24px 32px 60px; }
    .card { background: #fff; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 6px 20px rgba(15, 23, 42, 0.08); }
    h2 { margin-top: 0; font-size: 20px; }
    label { display: block; font-weight: 600; margin-bottom: 6px; }
    input[type="text"], input[type="password"], textarea { width: 100%; border-radius: 8px; border: 1px solid #cbd5f5; padding: 10px 12px; box-sizing: border-box; }
    textarea { min-height: 120px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; }
    .grid { display: grid; gap: 16px; }
    .grid-2 { grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
    .btn { background: #2563eb; color: #fff; border: none; padding: 10px 16px; border-radius: 8px; cursor: pointer; }
    .btn-secondary { background: #64748b; }
    .btn + .btn { margin-left: 8px; }
    .tag { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; background: #e0f2fe; color: #0369a1; font-size: 12px; margin-right: 6px; margin-bottom: 6px; position: relative; }
    .tag button { background: transparent; border: none; color: #0369a1; cursor: pointer; font-size: 12px; padding: 0; }
    .message { background: #ecfeff; border: 1px solid #a5f3fc; color: #0e7490; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; display: none; white-space: pre-line; }
    .error { background: #fee2e2; border-color: #fecaca; color: #991b1b; }
    .muted { color: #64748b; font-size: 13px; }
    .weekday-list { display: grid; gap: 12px; }
    .weekday-card { border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; }
    @media (max-width: 900px) {
      .shell { grid-template-columns: 1fr; }
      .sidebar { flex-direction: row; flex-wrap: wrap; align-items: center; justify-content: space-between; }
      .nav { display: flex; gap: 12px; }
      .nav a { margin-bottom: 0; }
    }
  </style>
</head>
<body>
<div class="shell">
  <aside class="sidebar">
    <div>
      <h1>同步控制台</h1>
      <p>更番表同步服务</p>
    </div>
    <div class="nav">
      <a href="/settings.php">设置</a>
      <a class="entry-link" href="/portal.php?entry=<?php echo urlencode($entryValue); ?>">入口</a>
      <a href="/logout.php">退出登录</a>
    </div>
    <div>
      <p>抓取源：comicat.org</p>
      <p>建议定时更新</p>
    </div>
  </aside>
  <main>
    <div id="message" class="message"></div>

    <div class="card">
      <h2>更番表抓取</h2>
      <p>当前更新：<span id="schedule-updated">尚未更新</span></p>
      <button class="btn" id="fetch-schedule">抓取并保存更番表</button>
      <p class="muted">已抓取 <span id="schedule-count">0</span> 条番剧信息。</p>
    </div>

    <div class="card">
      <h2>同步 mac_vod.vod_weekday</h2>
      <div class="grid grid-2">
        <button class="btn" id="update-weekday">全部更新</button>
        <button class="btn btn-secondary" id="update-weekday-fetched">仅同步抓取数据</button>
        <button class="btn btn-secondary" id="update-weekday-manual">仅同步手动数据</button>
      </div>
      <div class="grid grid-2" style="margin-top: 12px;">
        <div>
          <h3>更新成功</h3>
          <div id="update-success" class="muted">暂无成功记录。</div>
        </div>
        <div>
          <h3>更新失败</h3>
          <div id="update-failed" class="muted">暂无失败记录。</div>
        </div>
      </div>
    </div>

    <div class="card">
      <h2>快速新增同名替换</h2>
      <p class="muted">更新失败后，可在这里添加同名替换，然后再点击“更新更番表”。</p>
      <div class="grid grid-2">
        <div>
          <label for="replacement-from">原名称</label>
          <input type="text" id="replacement-from" placeholder="抓取到的名称">
        </div>
        <div>
          <label for="replacement-to">替换为</label>
          <input type="text" id="replacement-to" placeholder="数据库中的名称">
        </div>
        <div>
          <button class="btn" id="add-replacement">新增替换</button>
        </div>
      </div>
    </div>

    <div class="card">
      <h2>去除完结条目的 vod_weekday</h2>
      <button class="btn btn-secondary" id="cleanup-weekday">执行清理</button>
      <div id="cleanup-result" class="muted"></div>
      <div id="cleanup-names" style="margin-top: 12px;"></div>
    </div>

    <div class="card">
      <h2>有效更番信息</h2>
      <div id="schedule-list" class="weekday-list"></div>
    </div>

    <div class="card">
      <h2>手动维护更番信息</h2>
      <p class="muted">手动条目不会被抓取覆盖，只能手动修改或删除。</p>
      <div class="grid grid-2">
        <div>
          <label for="new-weekday">星期</label>
          <input type="text" id="new-weekday" placeholder="例如 一">
        </div>
        <div>
          <label for="new-name">名称</label>
          <input type="text" id="new-name" placeholder="番剧名称">
        </div>
        <div>
          <button class="btn" id="add-schedule-item">新增条目</button>
          <button class="btn btn-secondary" id="clear-manual">清空手动条目</button>
          <div class="message" id="message-manual-add" style="margin-top: 10px; display: none;"></div>
        </div>
      </div>
      <div class="weekday-card" style="margin-top: 16px;">
        <table style="width: 100%; border-collapse: collapse;">
          <thead>
            <tr>
              <th style="text-align: left; padding: 8px 6px;">星期</th>
              <th style="text-align: left; padding: 8px 6px;">名称</th>
              <th style="text-align: left; padding: 8px 6px; width: 160px;">操作</th>
            </tr>
          </thead>
          <tbody id="schedule-editor"></tbody>
        </table>
      </div>
    </div>
  </main>
</div>
<script>
  const messageBox = document.getElementById('message');
  const scheduleUpdated = document.getElementById('schedule-updated');
  const scheduleCount = document.getElementById('schedule-count');

  function showMessage(text, isError = false) {
    messageBox.textContent = text;
    messageBox.classList.toggle('error', isError);
    messageBox.style.display = 'block';
    setTimeout(() => {
      messageBox.style.display = 'none';
    }, 4000);
  }

  function showInlineMessage(elementId, text, isError = false) {
    const message = document.getElementById(elementId);
    message.textContent = text;
    message.classList.toggle('error', isError);
    message.style.display = 'block';
    setTimeout(() => {
      message.style.display = 'none';
    }, 4000);
  }

  function renderSchedule(items) {
    const list = document.getElementById('schedule-list');
    if (!items.length) {
      list.innerHTML = '<div class="muted">暂无更番信息。</div>';
      return;
    }
    const weekdays = ['一', '二', '三', '四', '五', '六', '日'];
    const grouped = Object.fromEntries(weekdays.map(day => [day, []]));
    items.forEach(item => {
      if (grouped[item.weekday]) {
        grouped[item.weekday].push(item);
      }
    });
    list.innerHTML = weekdays.map(day => {
      const names = grouped[day];
      const tags = names.length ? names.map(item => `
        <span class="tag" data-source="${item.source}" data-index="${item.index}">
          <span class="tag-name" title="双击修改">${item.name}</span>
          <button class="delete-tag" title="删除">×</button>
        </span>
      `).join('') : '<span class="muted">暂无</span>';
      return `
        <div class="weekday-card">
          <strong>星期${day}</strong>
          <div>${tags}</div>
        </div>
      `;
    }).join('');
  }

  function renderScheduleEditor(items) {
    const container = document.getElementById('schedule-editor');
    if (!items.length) {
      container.innerHTML = '<tr><td colspan="3" class="muted" style="padding: 8px 6px;">暂无手动更番信息。</td></tr>';
      return;
    }
    container.innerHTML = items.map((item, index) => `
      <tr data-index="${index}">
        <td style="padding: 6px;"><input type="text" class="edit-weekday" value="${item.weekday || ''}" style="width: 80px;"></td>
        <td style="padding: 6px;"><input type="text" class="edit-name" value="${item.name || ''}"></td>
        <td style="padding: 6px;">
          <button class="btn save-item">保存</button>
          <button class="btn btn-secondary delete-item">删除</button>
        </td>
      </tr>
    `).join('');
  }

  async function loadState() {
    const res = await fetch('/api.php?action=state');
    if (!res.ok) {
      return;
    }
    const data = await res.json();
    scheduleUpdated.textContent = data.schedule.updated_at || '尚未更新';
    const scheduleItems = data.schedule.items || [];
    const manualItems = data.schedule.manual_items || [];
    const combinedItems = [
      ...scheduleItems.map((item, index) => ({ ...item, source: 'fetched', index })),
      ...manualItems.map((item, index) => ({ ...item, source: 'manual', index })),
    ];
    scheduleCount.textContent = combinedItems.length || 0;
    renderSchedule(combinedItems);
    renderScheduleEditor(manualItems);
  }

  async function postAction(action, payload = null) {
    const res = await fetch(`/api.php?action=${action}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: payload ? JSON.stringify(payload) : null,
    });
    const data = await res.json();
    if (!res.ok) {
      throw new Error(data.error || '操作失败');
    }
    return data;
  }

  function renderUpdateSummary(data) {
    const success = Object.keys(data.summary.success || {});
    const failed = Object.keys(data.summary.failed || {});
    const skipped = Object.keys(data.summary.skipped || {});
    const successBox = document.getElementById('update-success');
    const failedBox = document.getElementById('update-failed');
    successBox.innerHTML = success.length ? success.map(name => `<span class="tag">${name}</span>`).join('') : '暂无成功记录。';
    const failedTags = failed.length ? failed.map(name => `<span class="tag">${name}</span>`).join('') : '暂无失败记录。';
    const skippedTags = skipped.length ? `<div class="muted" style="margin-top: 8px;">已跳过：${skipped.map(name => `<span class="tag">${name}</span>`).join('')}</div>` : '';
    failedBox.innerHTML = failedTags + skippedTags;
    if (data.summary.errors && data.summary.errors.length) {
      showMessage(data.summary.errors.join('\n'), true);
    }
  }

  document.getElementById('fetch-schedule').addEventListener('click', async () => {
    try {
      const data = await postAction('fetch_schedule');
      const statusText = data.status_code ? `（HTTP ${data.status_code}）` : '';
      showMessage(`${data.message || '更番表已更新。'} ${statusText} 已抓取 ${data.count || 0} 条。`.trim());
      await loadState();
    } catch (err) {
      showMessage(err.message, true);
    }
  });

  document.getElementById('update-weekday').addEventListener('click', async () => {
    try {
      const data = await postAction('update_weekday');
      renderUpdateSummary(data);
    } catch (err) {
      showMessage(err.message, true);
    }
  });

  document.getElementById('update-weekday-fetched').addEventListener('click', async () => {
    try {
      const data = await postAction('update_weekday_fetched');
      renderUpdateSummary(data);
    } catch (err) {
      showMessage(err.message, true);
    }
  });

  document.getElementById('update-weekday-manual').addEventListener('click', async () => {
    try {
      const data = await postAction('update_weekday_manual');
      renderUpdateSummary(data);
    } catch (err) {
      showMessage(err.message, true);
    }
  });

  document.getElementById('add-replacement').addEventListener('click', async () => {
    const from = document.getElementById('replacement-from').value.trim();
    const to = document.getElementById('replacement-to').value.trim();
    if (!from) {
      showMessage('原名称不能为空。', true);
      return;
    }
    try {
      await postAction('add_replacement', { from, to });
      showMessage('已新增同名替换。');
      document.getElementById('replacement-from').value = '';
      document.getElementById('replacement-to').value = '';
    } catch (err) {
      showMessage(err.message, true);
    }
  });

  document.getElementById('cleanup-weekday').addEventListener('click', async () => {
    try {
      const data = await postAction('cleanup_weekday');
      const result = Object.entries(data.summary.affected || {}).map(([label, count]) => `${label}：已清理 ${count} 条记录。`).join('<br>');
      document.getElementById('cleanup-result').innerHTML = result || '暂无清理结果。';
      const namesByDb = data.summary.names || {};
      const namesFlat = data.summary.names_flat || [];
      const allNames = namesFlat.length ? namesFlat : Object.values(namesByDb).flat().filter(Boolean);
      if (allNames.length) {
        const uniqueNames = [...new Set(allNames)];
        const list = uniqueNames.map(name => `
          <label style="display: inline-flex; align-items: center; margin-right: 10px; margin-bottom: 6px;">
            <input type="checkbox" class="cleanup-name" value="${name}" checked>
            <span style="margin-left: 6px;">${name}</span>
          </label>
        `).join('');
        document.getElementById('cleanup-names').innerHTML = `
          <div class="muted" style="margin-bottom: 8px;">已清理的条目，可选择从系统更番表中删除：</div>
          <div>${list}</div>
          <button class="btn btn-secondary" id="delete-cleanup-names" style="margin-top: 10px;">删除选中更番信息</button>
        `;
      } else {
        document.getElementById('cleanup-names').innerHTML = '';
      }
      if (data.summary.errors && data.summary.errors.length) {
        showMessage(data.summary.errors.join('\n'), true);
      }
    } catch (err) {
      showMessage(err.message, true);
    }
  });

  document.getElementById('cleanup-names').addEventListener('click', async (event) => {
    if (!event.target.closest('#delete-cleanup-names')) {
      return;
    }
    const checked = Array.from(document.querySelectorAll('.cleanup-name:checked')).map(input => input.value);
    if (!checked.length) {
      showMessage('请先选择需要删除的更番信息。', true);
      return;
    }
    try {
      await postAction('delete_schedule_names', { names: checked });
      await loadState();
      document.getElementById('cleanup-names').innerHTML = '';
      showMessage('已删除选中的更番信息。');
    } catch (err) {
      showMessage(err.message, true);
    }
  });

  document.getElementById('add-schedule-item').addEventListener('click', async () => {
    const weekday = document.getElementById('new-weekday').value.trim();
    const name = document.getElementById('new-name').value.trim();
    if (!weekday || !name) {
      showInlineMessage('message-manual-add', '星期与名称不能为空。', true);
      return;
    }
    try {
      await postAction('add_schedule_item', { weekday, name });
      document.getElementById('new-weekday').value = '';
      document.getElementById('new-name').value = '';
      await loadState();
    } catch (err) {
      showInlineMessage('message-manual-add', err.message, true);
    }
  });

  document.getElementById('schedule-editor').addEventListener('click', async (event) => {
    const row = event.target.closest('tr');
    if (!row) {
      return;
    }
    const index = Number(row.dataset.index);
    if (Number.isNaN(index)) {
      return;
    }
    if (event.target.classList.contains('save-item')) {
      const weekday = row.querySelector('.edit-weekday').value.trim();
      const name = row.querySelector('.edit-name').value.trim();
      if (!weekday || !name) {
        showMessage('星期与名称不能为空。', true);
        return;
      }
      try {
        await postAction('update_schedule_item', { index, weekday, name });
        await loadState();
      } catch (err) {
        showMessage(err.message, true);
      }
    }
    if (event.target.classList.contains('delete-item')) {
      try {
        await postAction('delete_schedule_item', { index });
        await loadState();
      } catch (err) {
        showMessage(err.message, true);
      }
    }
  });

  document.getElementById('schedule-list').addEventListener('click', async (event) => {
    const tag = event.target.closest('.tag');
    if (!tag) {
      return;
    }
    const source = tag.dataset.source;
    const index = Number(tag.dataset.index);
    if (Number.isNaN(index) || !source) {
      return;
    }
    if (event.target.classList.contains('delete-tag')) {
      try {
        await postAction('delete_schedule_entry', { source, index });
        await loadState();
      } catch (err) {
        showMessage(err.message, true);
      }
    }
  });

  document.getElementById('schedule-list').addEventListener('dblclick', async (event) => {
    const tag = event.target.closest('.tag');
    if (!tag) {
      return;
    }
    const source = tag.dataset.source;
    const index = Number(tag.dataset.index);
    const nameEl = tag.querySelector('.tag-name');
    if (!nameEl || Number.isNaN(index) || !source) {
      return;
    }
    const currentName = nameEl.textContent;
    const nextName = prompt('请输入新的名称', currentName);
    if (!nextName || nextName.trim() === currentName) {
      return;
    }
    try {
      await postAction('update_schedule_entry', { source, index, name: nextName.trim() });
      await loadState();
    } catch (err) {
      showMessage(err.message, true);
    }
  });

  document.getElementById('clear-manual').addEventListener('click', async () => {
    try {
      await postAction('clear_manual_schedule');
      await loadState();
    } catch (err) {
      showMessage(err.message, true);
    }
  });

  loadState();
</script>
</body>
</html>
