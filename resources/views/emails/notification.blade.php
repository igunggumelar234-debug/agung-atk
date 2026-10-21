<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f5f9;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                       style="max-width:600px;background-color:#ffffff;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden;">
                    {{-- Kepala email --}}
                    <tr>
                        <td style="background-color:#1e3a8a;padding:20px 24px;">
                            <div style="color:#ffffff;font-size:18px;font-weight:bold;">{{ $appName }}</div>
                            <div style="color:#bfdbfe;font-size:12px;margin-top:4px;">
                                Sistem Informasi Penjualan Toko Alat Tulis Kerja
                            </div>
                        </td>
                    </tr>

                    {{-- Isi pesan --}}
                    <tr>
                        <td style="padding:24px;">
                            <h1 style="margin:0 0 16px;font-size:18px;line-height:1.4;color:#0f172a;">
                                {{ $subject }}
                            </h1>

                            @foreach ($lines as $line)
                                @if ($line === '')
                                    <div style="height:12px;line-height:12px;">&nbsp;</div>
                                @elseif (str_starts_with($line, '- '))
                                    <div style="color:#334155;font-size:14px;line-height:1.7;padding-left:10px;">
                                        &bull; {{ substr($line, 2) }}
                                    </div>
                                @elseif (str_starts_with($line, 'http'))
                                    <p style="margin:0 0 10px;font-size:14px;line-height:1.7;word-break:break-all;">
                                        <a href="{{ $line }}" style="color:#2563eb;">{{ $line }}</a>
                                    </p>
                                @else
                                    <p style="margin:0 0 10px;color:#334155;font-size:14px;line-height:1.7;">{{ $line }}</p>
                                @endif
                            @endforeach
                        </td>
                    </tr>

                    {{-- Kaki email --}}
                    <tr>
                        <td style="background-color:#f8fafc;padding:16px 24px;border-top:1px solid #e2e8f0;">
                            <p style="margin:0;color:#94a3b8;font-size:12px;line-height:1.6;">
                                Email ini dikirim otomatis oleh {{ $appName }} dan tidak perlu dibalas.<br>
                                &copy; {{ $year }} {{ $appName }} &mdash; Kuningan, Jawa Barat.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
