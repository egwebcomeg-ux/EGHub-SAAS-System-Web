{{-- 80mm thermal paper: plain print CSS (no Tailwind) shared by the wallet / gift card slips and the corporate voucher receipts. --}}
        @page { size: 80mm auto; margin: 3mm; }
        body { width: 74mm; margin: 0 auto; font-family: 'Cairo', Tahoma, sans-serif; font-size: 13px; color: #000; }
        h1 { font-size: 16px; margin: 0; text-align: center; }
        .c { text-align: center; } .muted { color: #444; font-size: 11px; }
        .big { font-size: 30px; font-weight: 800; text-align: center; margin: 4px 0; }
        .code { font-family: 'Courier New', monospace; font-size: 20px; font-weight: 800; letter-spacing: 2px; text-align: center; direction: ltr; border: 2px dashed #000; padding: 6px; margin: 6px 0; }
        .qr { display: flex; justify-content: center; margin: 6px 0; } .qr svg { width: 44mm; height: 44mm; }
        hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
        table { width: 100%; border-collapse: collapse; } td { padding: 2px 0; } td.n { text-align: end; white-space: nowrap; }
        @media screen { body { padding: 12px; background: #fff; } }
