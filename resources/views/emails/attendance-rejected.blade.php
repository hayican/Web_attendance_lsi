Halo {{ $employeeName }},

Pengajuan {{ $phaseLabel }} Anda pada tanggal {{ $dateLabel }} ditolak oleh HR Admin.

Alasan Penolakan:
{{ $rejectReason }}

@if($retryDeadline)
Anda dapat melakukan absen ulang sebelum {{ $retryDeadline }}.
@else
Silakan periksa riwayat di aplikasi atau hubungi HR Admin jika memiliki bukti pendukung untuk koreksi status.
@endif
