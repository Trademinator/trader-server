    <style>
        .pair-guide { max-width:1120px; margin:0 auto; color:#172033; line-height:1.55; }
        .dark .pair-guide { color:#f1f5f9; }
        .pair-guide a { color:#0756b9; text-decoration:underline; text-underline-offset:3px; }
        .dark .pair-guide a { color:#93c5fd; }
        .pair-guide h1 { font-size:1.8rem; font-weight:750; }
        .pair-guide h2 { font-size:1.2rem; font-weight:750; margin-bottom:10px; }
        .pair-guide h3, .pair-guide legend { font-size:1.05rem; font-weight:700; }
        .pair-guide p { margin:8px 0; }
        .pair-guide .guide-hero { background:#0a438e; color:white; padding:24px; border-radius:16px; margin:18px 0; }
        .pair-guide .guide-panel { border:1px solid #aebbc9; padding:22px; border-radius:14px; margin:18px 0; background:#fff; }
        .dark .pair-guide .guide-panel { background:#111827; border-color:#64748b; }
        .pair-guide .guide-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; margin:14px 0; }
        .pair-guide label { display:block; font-weight:650; }
        .pair-guide input:not([type=checkbox]), .pair-guide select { display:block; width:100%; margin-top:6px; min-height:44px; padding:9px 10px; border:2px solid #94a3b8; border-radius:8px; background:white; color:#172033; }
        .pair-guide input[type=checkbox] { width:18px; height:18px; margin:3px 8px 0 0; flex:none; }
        .pair-guide .guide-check { display:flex; align-items:flex-start; margin:14px 0; }
        .pair-guide .guide-help { font-size:.9rem; color:#40536c; font-weight:400; }
        .dark .pair-guide .guide-help { color:#cbd5e1; }
        .pair-guide .guide-button { display:inline-block; padding:11px 18px; border-radius:8px; background:#0756b9; color:#fff; text-decoration:none; font-weight:700; }
        .dark .pair-guide a.guide-button { color:#fff; }
        .pair-guide .guide-delete { padding:10px 16px; border:2px solid #b42318; border-radius:8px; color:#b42318; font-weight:650; }
        .dark .pair-guide .guide-delete { color:#fda4af; border-color:#fda4af; }
        .pair-guide :focus-visible { outline:3px solid #e8a800; outline-offset:3px; }
        .pair-guide .guide-notice { padding:14px; border-radius:9px; background:#e6f0ff; color:#163e70; margin:14px 0; }
        .pair-guide .guide-error { background:#fce7e7; color:#8d1919; }
        .pair-guide .guide-badge { display:inline-block; border-radius:6px; padding:3px 9px; background:#fff0cd; color:#644000; font-size:.85rem; font-weight:700; }
        .pair-guide .guide-badge.is-screened { background:#d1f3e1; color:#125437; }
        .pair-guide ul { list-style:disc; padding-left:22px; margin:8px 0 16px; }
        .pair-guide li { margin:5px 0; }
        .pair-guide summary { cursor:pointer; font-weight:700; padding:10px 0; }
        .pair-guide fieldset { min-width:0; }
        .pair-guide .guide-inline { display:flex; flex-wrap:wrap; gap:12px; align-items:center; }
        @media(max-width:650px) { .pair-guide .guide-grid { grid-template-columns:1fr; } .pair-guide .guide-panel { padding:16px; } }
    </style>
