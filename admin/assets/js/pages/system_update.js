/*
 * Nebula 网络验证
 *
 * Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * 使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { loading, esc, tag } from '../core/util.js';
import { toast, confirmBox } from '../core/ui.js';

register('system_update', render);

async function render() {
    const c = document.getElementById('content');
    c.innerHTML = loading();

    const res = await api('system_update_check', { force: 1 }, true);

    if (res.code !== 0) {
        c.innerHTML = `
        <div class="card">
            <div class="card-head">
                <h3>系统更新</h3>
                <div class="acts">
                    <button class="btn ghost sm" id="suRefresh">重新检查</button>
                </div>
            </div>
            <div class="card-body">
                <div style="padding:16px 20px;border-radius:10px;background:rgba(245,158,11,.1);color:#fcd34d;border:1px solid rgba(245,158,11,.3);font-size:13px">
                    ${esc(res.msg || '检查更新失败')}
                </div>
            </div>
        </div>
        <div id="licCardWrap" style="margin-top:16px"></div>`;
        document.getElementById('suRefresh').addEventListener('click', () => render());
        renderLicenseCard();
        return;
    }

    const d = res.data;
    const latest = d.latest || {};
    const currentVer = d.current_version || '0.0.0';
    const latestVer = latest.latest_version || currentVer;
    const latestBuild = latest.latest_build || 0;
    const minVersion = latest.min_version || '';
    const channel = latest.channel || 'stable';
    const publishedAt = latest.published_at || '';
    const hasNew = compareVersion(currentVer, latestVer) < 0;
    const isForce = !!(latest.force_update);
    const cached = d.cached;

    const statusBadge = hasNew
        ? (isForce ? tag('需要强制更新', 'red') : tag('发现新版本', 'yellow'))
        : tag('已是最新版本', 'green');


    const releaseNotes = Array.isArray(latest.release_notes) ? latest.release_notes : [];
    const notesHtml = releaseNotes.length
        ? `<div style="background:var(--bg-alt);border-radius:10px;padding:16px 20px;margin:16px 0">
              <h4 style="font-size:14px;margin-bottom:12px;color:var(--text-sub)">v${esc(latestVer)} 更新内容</h4>
              <ul style="list-style:none;padding:0;margin:0">
                  ${releaseNotes.map(n => `<li style="padding:5px 0 5px 20px;position:relative;font-size:13px;color:var(--text-sub);line-height:1.5">
                      <span style="position:absolute;left:4px;top:13px;width:6px;height:6px;border-radius:50%;background:var(--accent)"></span>${esc(n)}</li>`).join('')}
              </ul>
          </div>`
        : `<div style="padding:12px 20px;border-radius:10px;background:var(--bg-alt);font-size:13px;color:var(--text-sub);margin:16px 0">
              暂无更新日志
          </div>`;


    let actionHtml = '';
    if (hasNew && latest.download_url) {
        actionHtml = `
            <div style="display:flex;gap:12px;margin-top:20px;align-items:center;flex-wrap:wrap">
                <a class="btn ghost sm" href="${esc(latest.download_url)}" target="_blank" style="text-decoration:none">手动下载</a>
                ${latest.sha256 ? `<span class="mono" style="font-size:12px;color:var(--text-sub)">SHA256: ${esc(latest.sha256)}</span>` : ''}
            </div>
            <div id="suPrecheckWrap" style="margin-top:16px"></div>`;
    } else if (!hasNew) {
        actionHtml = `<div style="padding:12px 20px;border-radius:10px;background:rgba(34,197,94,.12);color:#86efac;border:1px solid rgba(34,197,94,.35);font-size:13px;margin-top:16px;display:flex;align-items:center;gap:8px">
            <i class="bi bi-check-circle" style="font-size:16px"></i> 当前已是最新版本，无需更新。
        </div>`;
    }


    const forceAlert = isForce ? `
        <div style="padding:14px 20px;border-radius:10px;background:rgba(239,68,68,.12);color:#fca5a5;border:1px solid rgba(239,68,68,.35);font-size:13px;margin-bottom:16px;display:flex;align-items:flex-start;gap:10px">
            <i class="bi bi-exclamation-triangle" style="font-size:16px;flex-shrink:0;color:#f87171"></i>
            <div>
                <b>当前版本已停止支持</b><br>
                必须更新到 v${esc(latestVer)} 后才能继续使用。最低支持版本为 v${esc(minVersion)}。
            </div>
        </div>` : '';


    const minVersionHint = (hasNew && minVersion && !isForce && compareVersion(currentVer, minVersion) < 0)
        ? `<div style="padding:10px 16px;border-radius:8px;background:rgba(245,158,11,.1);color:#fcd34d;border:1px solid rgba(245,158,11,.2);font-size:12px;margin-top:8px">
            当前版本低于最低支持版本 v${esc(minVersion)}，更新后将获得更好的体验。
        </div>` : '';

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3>系统更新 ${statusBadge}</h3>
            <div class="acts">
                <button class="btn ghost sm" id="suRefresh">重新检查</button>
            </div>
        </div>
        <div class="card-body">
            ${forceAlert}

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px">
                <div style="border:1px solid var(--border);border-radius:10px;padding:20px">
                    <div style="font-size:13px;color:var(--text-sub);margin-bottom:8px">当前版本</div>
                    <div style="font-size:24px;font-weight:600">v${esc(currentVer)}</div>
                    <div style="font-size:13px;color:var(--text-sub);margin-top:4px">Nebula 网络验证系统</div>
                </div>
                <div style="border:1px solid var(--border);border-radius:10px;padding:20px">
                    <div style="font-size:13px;color:var(--text-sub);margin-bottom:8px">最新版本</div>
                    <div style="font-size:24px;font-weight:600">v${esc(latestVer)}</div>
                    <div style="font-size:13px;color:var(--text-sub);margin-top:4px">
                        Build ${esc(latestBuild)} · ${esc(channel)} · ${esc(publishedAt)}
                    </div>
                </div>
            </div>

            ${minVersionHint}

            ${notesHtml}

            ${actionHtml}

            <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border);font-size:12px;color:var(--text-faint)">
                版本检查由在线版本更新系统提供。每次检查间隔 6 小时，点击「重新检查」可强制刷新。<br>
                一键更新会自动下载、校验、备份并安装更新包，无需手动上传。升级前会先列出本站被改动过的文件，可逐个选择保留。
            </div>
        </div>
    </div>
    <div id="licCardWrap" style="margin-top:16px"></div>`;

    document.getElementById('suRefresh').addEventListener('click', () => render());
    renderLicenseCard();

    // 有新版本就先跑预检（升级影响面），把「改过哪些文件」摊开再让用户决定
    if (hasNew && latest.download_url) {
        loadPrecheck(latest, currentVer);
    }
}


/* ------------------------------------------------------------------
 * 升级影响预检
 * ------------------------------------------------------------------
 * 走 system_update_precheck：服务端下载包后按 manifest.hashes 逐文件比对本站
 * 实际 sha256，返回「你改过哪些文件」「新版删除了哪些文件」。
 * 旧包不带 hashes 时 has_hash=false，只能降级展示「将覆盖 N 个文件」。
 * ------------------------------------------------------------------ */
async function loadPrecheck(latest, currentVer) {
    const wrap = document.getElementById('suPrecheckWrap');
    if (!wrap) return;
    const latestVer = latest.latest_version || '';

    wrap.innerHTML = `<div class="card">
        <div class="card-head"><h3>升级影响预检</h3></div>
        <div class="card-body" style="padding:24px 20px;font-size:13px;color:var(--text-sub)">
            正在下载并比对更新包（约需数秒）…
        </div>
    </div>`;

    let res;
    try {
        res = await api('system_update_precheck', {
            download_url: latest.download_url,
            sha256: latest.sha256,
            version: latestVer,
            build: latest.latest_build || 0,
        }, true);
    } catch (e) {
        res = { code: -1, msg: '预检请求失败：' + (e.message || '网络错误') };
    }

    if (!res || res.code !== 0) {
        // 预检失败不阻断升级：退回旧的「一键更新」按钮，但明确告知拿不到清单
        wrap.innerHTML = `<div class="card">
            <div class="card-head"><h3>升级影响预检</h3></div>
            <div class="card-body">
                <div style="padding:14px 18px;border-radius:10px;background:rgba(245,158,11,.1);color:#fcd34d;border:1px solid rgba(245,158,11,.3);font-size:13px;line-height:1.6">
                    预检未完成：${esc((res && res.msg) || '未知原因')}<br>
                    仍可直接更新，但无法提前得知本站哪些文件被改动过（服务端会自动备份所有被覆盖的文件）。
                </div>
                <div style="margin-top:16px">
                    <button class="btn success" id="suDoUpdateFallback">直接更新到 v${esc(latestVer)}</button>
                </div>
            </div>
        </div>`;
        const fb = document.getElementById('suDoUpdateFallback');
        if (fb) {
            fb.addEventListener('click', () => {
                confirmBox('一键更新', `将全量覆盖并安装 v${esc(latestVer)}，更新期间后台将暂时不可用。确定继续吗？`, () => {
                    doUpdate(latest, fb, [], true);
                });
            });
        }
        return;
    }

    const d = res.data || {};
    const counts = d.counts || {};
    const modified = Array.isArray(d.modified) ? d.modified : [];
    const deleted = Array.isArray(d.deleted) ? d.deleted : [];
    const hasHash = !!d.has_hash;

    const stat = (label, val, color) => `
        <div style="border:1px solid var(--border);border-radius:10px;padding:14px 16px">
            <div style="font-size:12px;color:var(--text-sub);margin-bottom:6px">${esc(label)}</div>
            <div style="font-size:20px;font-weight:600;color:${color || 'var(--text)'}">${esc(val)}</div>
        </div>`;

    const noHashWarn = hasHash ? '' : `
        <div style="padding:14px 18px;border-radius:10px;background:rgba(245,158,11,.1);color:#fcd34d;border:1px solid rgba(245,158,11,.3);font-size:13px;line-height:1.6;margin-bottom:16px">
            <b>此更新包未携带逐文件哈希</b>，无法判断本站哪些文件被改动过，本次将全量覆盖（覆盖前会自动备份到
            <span class="mono">logs/update_backups/</span>）。
        </div>`;

    const modifiedHtml = modified.length ? `
        <div style="margin-top:20px;border:1px solid rgba(245,158,11,.35);border-radius:10px;overflow:hidden">
            <div style="padding:14px 16px;background:rgba(245,158,11,.08);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
                <div style="font-size:13px;color:#fcd34d">
                    <b>检测到 ${modified.length} 个文件在本地被修改过</b>
                    <div style="font-size:12px;color:var(--text-sub);margin-top:4px">默认勾选「保留」——不覆盖这些文件。取消勾选则用新版本覆盖（原文件仍会备份）。</div>
                </div>
                <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-sub);cursor:pointer;white-space:nowrap">
                    <input type="checkbox" id="suKeepAll" checked> 全部保留
                </label>
            </div>
            <div style="max-height:280px;overflow:auto;padding:8px 0">
                ${modified.map(m => `
                    <label style="display:flex;align-items:center;gap:10px;padding:7px 16px;font-size:12px;cursor:pointer">
                        <input type="checkbox" data-keep="${esc(m.path)}" checked>
                        <span class="mono" style="flex:1;word-break:break-all">${esc(m.path)}</span>
                        <span style="color:var(--text-faint);white-space:nowrap">本地 ${fmtSize(m.local_size)} / 新版 ${fmtSize(m.remote_size)}</span>
                    </label>`).join('')}
            </div>
            ${d.truncated ? `<div style="padding:10px 16px;font-size:12px;color:#fcd34d">清单过长，仅展示前 500 项；未展示的项按「覆盖」处理。</div>` : ''}
        </div>` : '';

    const deletedHtml = deleted.length ? `
        <div style="margin-top:16px;border:1px solid rgba(239,68,68,.3);border-radius:10px;overflow:hidden">
            <div style="padding:14px 16px;background:rgba(239,68,68,.07);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
                <div style="font-size:13px;color:#fca5a5">
                    <b>本次更新将删除 ${deleted.length} 个旧版文件</b>
                    <div style="font-size:12px;color:var(--text-sub);margin-top:4px">这些文件在新版本中已被移除。删除前同样会备份，可随时找回。</div>
                </div>
                <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-sub);cursor:pointer;white-space:nowrap">
                    <input type="checkbox" id="suDelAll" checked> 执行删除
                </label>
            </div>
            <div style="max-height:200px;overflow:auto;padding:8px 0">
                ${deleted.map(x => `
                    <div style="padding:6px 16px;font-size:12px;color:var(--text-sub)">
                        <span class="mono" style="word-break:break-all">${esc(x.path)}</span>
                        <span style="color:var(--text-faint)"> · ${fmtSize(x.size)}</span>
                    </div>`).join('')}
            </div>
        </div>` : '';

    wrap.innerHTML = `<div class="card">
        <div class="card-head">
            <h3>升级影响预检 ${hasHash ? tag('已比对逐文件哈希', 'green') : tag('无哈希基线', 'yellow')}</h3>
        </div>
        <div class="card-body">
            ${noHashWarn}
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px">
                ${stat('将覆盖', counts.overwrite || 0)}
                ${stat('新增', counts.missing || 0)}
                ${stat('你改过的', counts.modified || 0, counts.modified ? '#fbbf24' : '')}
                ${stat('将删除', counts.deleted || 0, counts.deleted ? '#fca5a5' : '')}
            </div>
            ${modifiedHtml}
            ${deletedHtml}
            <div style="margin-top:20px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                <button class="btn success" id="suDoUpdate">按以上选择更新到 v${esc(latestVer)}</button>
                <span style="font-size:12px;color:var(--text-faint)">共 ${esc(counts.entries || 0)} 个文件参与本次升级</span>
            </div>
        </div>
    </div>`;

    // 全选/全不选（CSP: script-src 无 unsafe-inline，必须事件绑定）
    const keepAll = document.getElementById('suKeepAll');
    if (keepAll) {
        keepAll.addEventListener('change', () => {
            document.querySelectorAll('[data-keep]').forEach(el => { el.checked = keepAll.checked; });
        });
        document.querySelectorAll('[data-keep]').forEach(el => {
            el.addEventListener('change', () => {
                const all = Array.from(document.querySelectorAll('[data-keep]'));
                keepAll.checked = all.every(x => x.checked);
                keepAll.indeterminate = !keepAll.checked && all.some(x => x.checked);
            });
        });
    }

    const btn = document.getElementById('suDoUpdate');
    if (btn) {
        btn.addEventListener('click', () => {
            const keep = Array.from(document.querySelectorAll('[data-keep]'))
                .filter(el => el.checked)
                .map(el => el.getAttribute('data-keep'));
            const delAll = document.getElementById('suDelAll');
            const deleteRemoved = delAll ? delAll.checked : true;
            const delCount = deleteRemoved ? deleted.length : 0;

            let msg = `将更新到 v${latestVer}，更新期间后台将暂时不可用。`;
            if (keep.length) msg += ` 保留 ${keep.length} 个本地改动文件（不覆盖）。`;
            if (delCount) msg += ` 删除 ${delCount} 个旧版文件。`;
            msg += ' 确定继续吗？';

            confirmBox('确认升级', msg, () => doUpdate(latest, btn, keep, deleteRemoved));
        });
    }
}


async function doUpdate(latest, btn, keep, deleteRemoved) {
    btn.disabled = true;
    btn.textContent = '正在下载并安装...';
    btn.style.opacity = '0.7';

    try {
        const res = await api('system_update_do', {
            download_url: latest.download_url,
            sha256: latest.sha256,
            version: latest.latest_version,
            build: latest.latest_build || 0,
            keep: keep || [],
            delete_removed: deleteRemoved ? 1 : 0,
        }, true);

        if (res.code === 0) {
            const d = res.data || {};
            toast(`更新完成！已更新 ${d.updated_files || 0} 个文件`, 'ok');

            const extra = [];
            if (d.kept_files) extra.push(`保留了 ${d.kept_files} 个本地改动文件`);
            if (d.deleted_files) extra.push(`移除了 ${d.deleted_files} 个旧版文件`);
            if (Array.isArray(d.errors) && d.errors.length) extra.push(`${d.errors.length} 项失败`);

            const c = document.getElementById('content');
            c.innerHTML = `
            <div class="card">
                <div class="card-head">
                    <h3>更新完成</h3>
                </div>
                <div class="card-body" style="text-align:center;padding:40px 20px">
                    <div style="width:64px;height:64px;margin:0 auto 16px;border-radius:50%;background:rgba(34,197,94,.15);display:flex;align-items:center;justify-content:center">
                        <i class="bi bi-check-lg" style="font-size:32px;color:#4ade80"></i>
                    </div>
                    <h3 style="font-size:18px;margin-bottom:8px">系统已成功更新到 v${esc(d.new_version || latest.latest_version)}</h3>
                    <p style="font-size:13px;color:var(--text-sub);margin-bottom:20px">
                        更新了 ${d.updated_files || 0} 个文件，备份了 ${d.backup_files || 0} 个文件。<br>
                        ${extra.length ? esc(extra.join('，')) + '<br>' : ''}
                        备份目录：${esc(d.backup_path || '-')}
                    </p>
                    ${Array.isArray(d.errors) && d.errors.length ? `<div style="text-align:left;max-width:560px;margin:0 auto 20px;padding:12px 16px;border-radius:8px;background:rgba(239,68,68,.1);color:#fca5a5;font-size:12px;line-height:1.7">${d.errors.slice(0, 20).map(e => esc(e)).join('<br>')}</div>` : ''}
                    <button class="btn success" id="suReload">刷新后台</button>
                </div>
            </div>`;
            document.getElementById('suReload').addEventListener('click', () => location.reload());
        } else {
            btn.disabled = false;
            btn.textContent = `按以上选择更新到 v${latest.latest_version}`;
            btn.style.opacity = '1';
            toast(res.msg || '更新失败', 'err');
        }
    } catch (e) {
        btn.disabled = false;
        btn.textContent = `按以上选择更新到 v${latest.latest_version}`;
        btn.style.opacity = '1';
        toast('更新请求失败：' + (e.message || '网络错误'), 'err');
    }
}


function fmtSize(n) {
    n = Number(n) || 0;
    if (n >= 1048576) return (n / 1048576).toFixed(1) + ' MB';
    if (n >= 1024) return (n / 1024).toFixed(1) + ' KB';
    return n + ' B';
}


function compareVersion(a, b) {
    const pa = (a || '0').replace(/^v/i, '').split('.').map(Number);
    const pb = (b || '0').replace(/^v/i, '').split('.').map(Number);
    const n = Math.max(pa.length, pb.length);
    for (let i = 0; i < n; i++) {
        const va = pa[i] || 0, vb = pb[i] || 0;
        if (va < vb) return -1;
        if (va > vb) return 1;
    }
    return 0;
}


// ------------------------------------------------------------------
// 授权激活卡片：查看当前授权状态 / 重新填写激活（过期、换码、补激活）
// ------------------------------------------------------------------
async function renderLicenseCard() {
    const wrap = document.getElementById('licCardWrap');
    if (!wrap) return;
    // 先渲染卡片骨架（状态获取失败不影响卡片可见），再异步填充状态
    let d = { configured: false, masked: '', domain: '', pending: true };
    try {
        const st = await api('license_manage', { op: 'status' }, true);
        if (st.code === 0 && st.data) d = st.data;
    } catch (e) { /* 状态获取失败按未激活展示 */ }

    wrap.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3>授权激活 ${d.configured ? tag('已配置', 'green') : tag('未激活', 'yellow')}</h3>
        </div>
        <div class="card-body">
            <div style="font-size:13px;color:var(--text-sub);margin-bottom:12px">
                ${d.configured
                    ? `当前授权码：<span class="mono">${esc(d.masked)}</span> · 绑定域名：<span class="mono">${esc(d.domain || '-')}</span>`
                    : '尚未配置授权激活码。未激活不影响系统使用，但后续将无法在线获取新版本。'}
                授权过期或更换授权码时，在此重新填写即可完成激活（绑定当前域名）。
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <input id="licKeyInput" class="mono" placeholder="32 位授权码" maxlength="32"
                       autocomplete="off" spellcheck="false"
                       style="flex:1;min-width:220px;background:var(--bg-alt);border:1px solid var(--border);border-radius:8px;padding:9px 12px;color:var(--text)">
                <button class="btn success sm" id="licActivateBtn">激活 / 重新激活</button>
            </div>
        </div>
    </div>`;

    // CSP 的 script-src 不含 unsafe-inline，内联 oninput 会被拦截，改为事件绑定
    const licInput = document.getElementById('licKeyInput');
    if (licInput) {
        licInput.addEventListener('input', () => {
            licInput.value = licInput.value.toLowerCase().replace(/[^0-9a-f]/g, '');
        });
    }

    document.getElementById('licActivateBtn').addEventListener('click', async (ev) => {
        const btn = ev.currentTarget;
        const key = (document.getElementById('licKeyInput').value || '').trim();
        if (!key) { toast('请输入授权码', 'err'); return; }
        btn.disabled = true; btn.textContent = '激活中...';
        try {
            const r = await api('license_manage', { op: 'activate', license_key: key }, true);
            if (r.code === 0) {
                toast(r.msg || '激活成功', 'ok');
                renderLicenseCard();
                // 激活后刷新更新检查结果
                render();
            } else {
                btn.disabled = false; btn.textContent = '激活 / 重新激活';
                toast(r.msg || '激活失败', 'err');
            }
        } catch (e) {
            btn.disabled = false; btn.textContent = '激活 / 重新激活';
            toast('激活请求失败：' + (e.message || '网络错误'), 'err');
        }
    });
}
