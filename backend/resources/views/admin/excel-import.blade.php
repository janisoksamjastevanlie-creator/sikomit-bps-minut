@extends('layouts.app')

@section('title', 'Import Excel')

@section('content')
<div class="page-heading">
    <div><span class="eyebrow">UNGGAH DATA TERSTRUKTUR</span><h1>Import Excel</h1><p>Unduh template, isi satu jenis data, lalu validasi dan simpan ke database.</p></div>
    <a class="primary-button excel-template-download" href="{{ url('/api/v2/excel-import/template') }}">Unduh Template XLSX <span>↓</span></a>
</div>

<div class="notice notice-warning"><strong>Import bersifat atomik:</strong> jika ada kesalahan pada satu baris, seluruh import dibatalkan. Baris Excel tidak dapat melewati verifikasi mitra, aktivasi kegiatan, syarat penugasan, pemeriksaan bukti, atau persetujuan dokumen.</div>

<section class="panel excel-import-panel">
    <div class="panel-heading"><div><h2>Pilih jenis data</h2><p>Dalam satu proses, pilih satu sheet/jenis data dari workbook template.</p></div><span class="panel-icon">▤</span></div>
    <form id="excelImportForm" class="form-grid">
        <label>Jenis import
            <select name="type" id="excelImportType" required>
                @if(auth()->user()->hasRole(['ADMIN_BPS','ADMIN_MITRA','OPERATOR']))<option value="mitra">Mitra</option>@endif
                @if(auth()->user()->hasRole(['ADMIN_BPS','PJK','OPERATOR']))<option value="kegiatan">Kegiatan beserta target</option>@endif
                @if(auth()->user()->hasRole(['ADMIN_BPS','PJK','OPERATOR']))<option value="penugasan">Penugasan beserta rincian target</option>@endif
                @if(auth()->user()->hasRole('PETUGAS_MITRA'))<option value="laporan">Laporan pekerjaan saya</option>@endif
            </select>
        </label>
        <label>File Excel (.xlsx)
            <input name="file" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
        </label>
        <div id="excelImportHelp" class="form-note full-width"></div>
        <div class="full-width excel-import-actions"><button class="primary-button" type="submit" id="excelImportSubmit">Validasi dan Simpan</button></div>
    </form>
    <div id="excelImportResult" class="notice hidden" role="status" aria-live="polite"></div>
</section>

<section class="panel excel-rules-panel">
    <div class="panel-heading"><div><h2>Aturan import</h2><p>Sistem memvalidasi ulang data dan kewenangan sebelum menulis ke database.</p></div></div>
    <ul class="excel-rules">
        <li><strong>Mitra:</strong> record baru selalu berstatus menunggu verifikasi.</li>
        <li><strong>Kegiatan:</strong> record baru masuk sebagai draft, target disimpan sebagai rincian terpisah.</li>
        <li><strong>Penugasan:</strong> wajib memakai kegiatan aktif, mitra aktif-terverifikasi non-demo, dan jadwal tanpa bentrok.</li>
        <li><strong>Laporan:</strong> hanya akun mitra tertaut, memakai SPK final miliknya; hasil import berstatus draft.</li>
        <li>Maksimum 500 baris per import. Jangan mengubah nama atau urutan kolom, dan jangan memakai rumus Excel.</li>
        <li>Tidak ada kolom pembayaran, pajak, rekening, NIK, NPWP, tanda tangan, atau TTE.</li>
    </ul>
</section>

<script>
(() => {
    const form=document.getElementById('excelImportForm');
    const result=document.getElementById('excelImportResult');
    const type=document.getElementById('excelImportType');
    const help=document.getElementById('excelImportHelp');
    const submit=document.getElementById('excelImportSubmit');
    const hints={
        mitra:'Sheet Mitra · import membuat mitra baru berstatus MENUNGGU_VERIFIKASI.',
        kegiatan:'Sheet Kegiatan · satu baris untuk setiap target; kegiatan baru berstatus DRAFT.',
        penugasan:'Sheet Penugasan · kelompokkan target memakai kodePenugasan; kegiatan dan mitra harus sudah memenuhi prasyarat.',
        laporan:'Sheet Laporan · satu baris untuk setiap target pada SPK final milik akun mitra. Uraian target harus sama persis dan kategori target wajib diisi; laporan dibuat sebagai DRAFT.'
    };
    const show=(message,kind='info',details=[])=>{
        result.replaceChildren();
        const title=document.createElement('strong');title.textContent=message;result.appendChild(title);
        if(details.length){const list=document.createElement('ul');for(const text of details){const item=document.createElement('li');item.textContent=text;list.appendChild(item)}result.appendChild(list)}
        result.className=`notice notice-${kind}`;
    };
    const api=async(url,options={})=>{
        const response=await fetch(url,{credentials:'same-origin',headers:{'Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content,...(options.headers||{})},...options});
        const body=await response.json().catch(()=>({}));
        if(!response.ok){const errors=Object.values(body.errors||{}).flat();throw Object.assign(new Error(body.message||`Import gagal (HTTP ${response.status})`),{details:errors})}
        return body;
    };
    const updateHelp=()=>{help.textContent=hints[type.value]||'Pilih sheet sesuai jenis data.'};
    type.addEventListener('change',updateHelp);updateHelp();
    form.addEventListener('submit',async(event)=>{
        event.preventDefault();submit.disabled=true;submit.textContent='Memvalidasi…';result.className='notice hidden';
        try{const body=new FormData(form);const response=await api('/api/v2/excel-import/import',{method:'POST',body});show(`${response.message} ${response.note}`,'success')}
        catch(error){show(error.message,'error',error.details||[])}
        finally{submit.disabled=false;submit.textContent='Validasi dan Simpan'}
    });
})();
</script>
@endsection
