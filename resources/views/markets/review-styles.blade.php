    <style>
        .pair-review { min-width:0; width:100%; }
        .pair-review .review-topline { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; }
        .pair-review .review-eyebrow { font-size:.85rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
        .pair-review .review-title { font-size:2.3rem; letter-spacing:-.025em; }
        .pair-review .review-score { font-size:2.7rem; font-weight:750; line-height:1.2; white-space:nowrap; }
        .pair-review .review-score small { font-size:1rem; font-weight:500; }
        .pair-review .review-metrics { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin:18px 0; }
        .pair-review .review-metric { padding:16px; border-radius:10px; background:#edf3fb; color:#172033; }
        .dark .pair-review .review-metric { background:#24334b; color:#f1f5f9; }
        .pair-review .review-metric strong { display:block; font-size:1.65rem; margin:5px 0; }
        .pair-review .review-table-wrap { overflow-x:auto; }
        .pair-review table { width:100%; border-collapse:collapse; font-size:.95rem; }
        .pair-review th, .pair-review td { text-align:left; vertical-align:top; border-bottom:1px solid #b8c4d2; padding:12px 10px; }
        .dark .pair-review th, .dark .pair-review td { border-color:#52647d; }
        .pair-review th { font-weight:700; }
        .pair-review .review-points { white-space:nowrap; font-weight:750; }
        .pair-review .review-formula { padding:14px; border-radius:8px; background:#edf3fb; color:#172033; font-family:ui-monospace,monospace; overflow-wrap:anywhere; }
        .dark .pair-review .review-formula { background:#24334b; color:#f1f5f9; }
        .pair-review dl { display:grid; grid-template-columns:minmax(120px,1fr) minmax(0,2fr); gap:12px; }
        .pair-review dt { font-weight:650; }
        .pair-review dd { overflow-wrap:anywhere; }
        .pair-review .review-chart { height:390px; width:100%; min-width:0; margin:14px 0; }
        .pair-review .review-chart[hidden] { display:none; }
        .pair-review .review-control { border:1px solid #7c8da3; padding:7px 12px; border-radius:7px; font-weight:650; cursor:pointer; }
        .pair-review button:disabled { opacity:.55; cursor:wait; }
        .pair-review .review-legend { min-height:1.6em; font-size:.9rem; font-variant-numeric:tabular-nums; }
        .pair-review .review-subscribe { border-top:4px solid #0756b9; }
        .pair-review .review-subscribe form { margin:14px 0; }
        @media(max-width:650px) { .pair-review .review-metrics { grid-template-columns:1fr; } .pair-review .review-chart { height:300px; } .pair-review .review-title { font-size:1.9rem; } }
    </style>
