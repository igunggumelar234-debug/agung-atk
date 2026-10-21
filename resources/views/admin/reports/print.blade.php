<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $report['title'] }} - Agung ATK Kuningan</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111827; margin: 24px; font-size: 13px; }
        .header { border-bottom: 3px solid #0f172a; padding-bottom: 10px; margin-bottom: 14px; }
        .brand { font-size: 12px; letter-spacing: 2px; font-weight: 700; color: #0f172a; text-transform: uppercase; }
        h1 { font-size: 18px; margin: 4px 0 2px; }
        .meta { font-size: 12px; color: #475569; line-height: 1.7; }
        .actions { margin: 12px 0 16px; }
        .btn-print { background: #0f172a; color: #fff; border: 0; padding: 8px 16px; border-radius: 6px; font-size: 13px; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { border: 1px solid #94a3b8; padding: 7px 9px; text-align: left; vertical-align: top; }
        th { background: #f1f5f9; font-weight: 700; }
        tbody tr:nth-child(even) { background: #f8fafc; }
        .empty { text-align: center; color: #64748b; padding: 22px !important; }
        .footer { margin-top: 16px; font-size: 11px; color: #64748b; border-top: 1px solid #cbd5e1; padding-top: 8px; }
        @media print {
            body { margin: 8px; }
            .no-print { display: none !important; }
            @@page { margin: 12mm; }
        }
    </style>
</head>
<body onload="window.print()">
    <div class="header">
        <div class="brand">Agung ATK Kuningan</div>
        <h1>{{ $report['title'] }}</h1>
        <div class="meta">
            Periode: {{ $report['filter_labels']['period'] }}
            | Kategori: {{ $report['filter_labels']['category'] }}
            | Status: {{ $report['filter_labels']['status'] }}<br>
            Dicetak: {{ $printedAt }} | Jumlah baris: {{ $report['total_rows'] }}
        </div>
    </div>

    <div class="actions no-print">
        <button type="button" class="btn-print" onclick="window.print()">Cetak / Simpan sebagai PDF</button>
    </div>

    <table>
        <thead>
            <tr>
                @foreach($report['columns'] as $column)
                    <th>{{ $column }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($report['rows'] as $row)
                <tr>
                    @foreach($report['columns'] as $index => $column)
                        <td>{{ $row[$index] ?? '-' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($report['columns']) }}" class="empty">
                        Tidak ada data untuk filter yang dipilih.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Dokumen dihasilkan oleh Sistem Informasi Penjualan Toko Jual Beli Alat Tulis Kerja - Agung ATK Kuningan.
    </div>
</body>
</html>
