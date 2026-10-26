/*
 * Nebula 网络验证
 *
 * Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * 使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

const KEY = 'nb_theme';

export function storedTheme() {
    try {
        const t = localStorage.getItem(KEY);
        return (t === 'dark' || t === 'light') ? t : '';
    } catch (e) { return ''; }
}

export function systemTheme() {
    try {
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    } catch (e) { return 'light'; }
}

export function appliedTheme() {
    return storedTheme() || systemTheme();
}

export function applyTheme(t) {
    document.documentElement.setAttribute('data-theme', t === 'dark' ? 'dark' : 'light');
}

export function initTheme() {
    applyTheme(appliedTheme());
    try {
        const mq = window.matchMedia('(prefers-color-scheme: dark)');
        const onChange = e => { if (!storedTheme()) applyTheme(e.matches ? 'dark' : 'light'); };
        mq.addEventListener ? mq.addEventListener('change', onChange) : mq.addListener(onChange);
    } catch (e) {
 }
}

export function toggleTheme() {
    const next = appliedTheme() === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem(KEY, next); } catch (e) {
 }
    applyTheme(next);
    return next;
}
