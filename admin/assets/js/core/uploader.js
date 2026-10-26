/*
 * Nebula 网络验证
 *
 * Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * 使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

import { uploadHeaders } from './api.js';
import { API_ENTRY } from './state.js';
import { toast } from './ui.js';

export function bindImageUpload(btnId, inputId, opts) {
    const btn = document.getElementById(btnId);
    const input = document.getElementById(inputId);
    if (!btn || !input || btn.dataset.bound === '1') return;
    btn.dataset.bound = '1';
    btn.addEventListener('click', () => {
        const pick = document.createElement('input');
        pick.type = 'file';
        pick.accept = 'image/jpeg,image/png,image/gif,image/webp';
        pick.onchange = async () => {
            const f = pick.files && pick.files[0];
            if (!f) return;
            if (f.size > 5 * 1024 * 1024) { toast('图片需在 5MB 以内', 'warn'); return; }
            btn.disabled = true;
            const old = btn.textContent;
            btn.textContent = '上传中…';
            try {
                const fd = new FormData();
                fd.append('file', f);
                const res = await fetch(`${API_ENTRY}?action=${(opts && opts.action) || 'shop_goods_upload'}`, {
                    method: 'POST',
                    headers: uploadHeaders(),
                    body: fd,
                    credentials: 'same-origin',
                });
                const j = await res.json();
                if (j.code !== 0) throw new Error(j.msg || '上传失败');
                input.value = (j.data || {}).url || '';
                toast('图片已上传，记得点击「保存」后才会生效');
                if (opts && typeof opts.onDone === 'function') opts.onDone(input.value);
            } catch (e) {
                toast(e.message || '上传失败', 'err');
            } finally {
                btn.disabled = false;
                btn.textContent = old;
            }
        };
        pick.click();
    });
}
