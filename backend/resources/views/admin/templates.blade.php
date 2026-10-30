@extends('layouts.app')

@section('title', 'Template Dokumen')

@section('content')
<div class="page-heading">
    <div><span class="eyebrow">TEMPLATE RESMI</span><h1>Template Dokumen</h1><p>Kelola versi SPK/BAST di penyimpanan privat dan tinjau sebelum mengaktifkan.</p></div>
</div>
<div class="notice notice-warning">Template yang diberikan saat ini berupa contoh terisi. Siapkan salinan dinamis dengan placeholder yang disetujui; jangan gunakan nama, nomor, tanda tangan, atau realisasi pada contoh sebagai data operasional.</div>
<section class="panel template-upload-panel">
    <div class="panel-heading"><div><h2>Unggah versi template</h2><p>DOCX disimpan secara privat dan berstatus draft sampai diperiksa Admin BPS yang berbeda.</p></div><span class="panel-icon">▧</span></div>
    <form id="templateForm" class="form-grid">
        <label>Jenis dokumen<select name="documentType"><option value="SPK">SPK</option><option value="BAST">BAST</option></select></label>
        <label>Kategori petugas<select name="workerCategory"><option value="PENDATAAN_LAPANGAN">Pendataan Lapangan (Pemetaan)</option><option value="PENGOLAHAN">Pengolahan</option><option value="PENDATAAN_DAN_PENGOLAHAN">Pendataan dan Pengolahan</option></select></label>
        <label>Mode dokumen<select name="documentMode"><option value="TERPISAH">Terpisah (default)</option>@if($combinedModeEnabled)<option value="GABUNGAN">Gabungan (telah diaktifkan)</option>@else<option value="GABUNGAN" disabled>Gabungan (belum diizinkan)</option>@endif</select></label>
        <label>Nama template<input name="name" maxlength="180" required placeholder="Contoh: SPK Pengolahan - versi berlaku"></label>
        <label class="full-width">File DOCX<input name="file" type="file" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required></label>
        <div class="full-width form-note">Placeholder dinamis yang didukung: <code>${document_number}</code>, <code>${document_date}</code>, <code>${partner_name}</code>, <code>${activity_name}</code>, <code>${work_period}</code>, <code>${work_items}</code>, <code>${draft_banner}</code>, dan field BAST terkait. Redaksi/klausul resmi tidak boleh diubah otomatis.</div>
        <div class="full-width dialog-actions"><button class="primary-button" type="submit">Unggah sebagai Draft</button></div>
    </form>
    <div id="templateNotice" class="notice hidden" role="status"></div>
</section>

<section class="panel templates-list-panel">
    <div class="panel-heading"><div><h2>Versi terdaftar</h2><p>Versi aktif digunakan saat membuat draft; file tidak diakses dari public URL.</p></div><button class="secondary-button" id="reloadTemplates">Muat Ulang</button></div>
    <div class="table-wrap"><table class="data-table">
        <thead><tr><th>Dokumen/Kategori</th><th>Mode</th><th>Nama Template</th><th>Versi</th><th>Status</th><th>Placeholder</th><th></th></tr></thead>
        <tbody id="templateRows"><tr><td colspan="7" class="table-empty">Memuat template…</td></tr></tbody>
    </table></div>
</section>

<dialog class="app-dialog compact-dialog" id="approveTemplateDialog">
    <form method="dialog" class="dialog-head"><div><span class="eyebrow">PERSETUJUAN TEMPLATE</span><h2>Periksa persyaratan tanda tangan</h2></div><button class="icon-button" aria-label="Tutup">×</button></form>
    <div class="dialog-content">
        <p class="muted">Konfigurasikan kapasitas penandatangan sesuai template yang telah disahkan. Persyaratan ini mengunci alur tanda tangan dokumen.</p>
        <div class="form-grid">
            <label>Kapasitas Pihak Pertama<input id="signerCapacityOne" value="PIHAK_PERTAMA" maxlength="100"></label>
            <label>Peran Pihak Pertama<select id="signerRoleOne"><option value="PENANDATANGAN">Penanda Tangan</option><option value="PEJABAT_PERSETUJU">Pejabat Penyetuju</option></select></label>
            <label>Kapasitas Pihak Kedua<input id="signerCapacityTwo" value="PIHAK_KEDUA" maxlength="100"></label>
            <label>Peran Pihak Kedua<select id="signerRoleTwo"><option value="PETUGAS_MITRA">Petugas Mitra</option><option value="PENANDATANGAN">Penanda Tangan</option></select></label>
        </div>
        <div id="approveTemplateNotice" class="notice hidden"></div>
        <div class="dialog-actions"><button class="secondary-button" id="cancelTemplateApproval" type="button">Batal</button><button class="primary-button" id="confirmTemplateApproval" type="button">Setujui Versi</button></div>
    </div>
</dialog>

<script>
(() => {
    const form=document.getElementById('templateForm'), rows=document.getElementById('templateRows');
    const notice=document.getElementById('templateNotice'), approvalDialog=document.getElementById('approveTemplateDialog');
    let approvalVersionId=null;
    const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
    const api=async(url,options={})=>{const response=await fetch(url,{credentials:'same-origin',headers:{'Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content,...(options.headers||{})},...options});const body=await response.json().catch(()=>({}));if(!response.ok)throw new Error(body.message||`Permintaan gagal (${response.status})`);return body;};
    const showNotice=(target,message,type='error')=>{target.textContent=message;target.className=`notice notice-${type}`;};
    const labelCategory=value=>({PENDATAAN_LAPANGAN:'Pendataan Lapangan',PENGOLAHAN:'Pengolahan',PENDATAAN_DAN_PENGOLAHAN:'Pendataan dan Pengolahan'})[value]||value;
    const refresh=async()=>{rows.innerHTML='<tr><td colspan="7" class="table-empty">Memuat template…</td></tr>';try{const result=await api('/api/v2/templates');rows.innerHTML=result.items.length?result.items.flatMap(template=>template.versions.map(version=>`<tr>
        <td><strong>${esc(template.documentType)} · ${esc(labelCategory(template.workerCategory))}</strong></td><td>${esc(template.documentMode)}</td><td>${esc(template.name)}</td><td>v${esc(version.version)}</td>
        <td><span class="status-pill status-${esc(version.status.toLowerCase())}">${esc(version.status)}</span>${template.isActive&&version.status==='APPROVED'?'<small class="cell-subtitle">Aktif</small>':''}</td>
        <td>${esc((version.placeholderFields||[]).join(', ')||'Tidak ditemukan')}</td>
        <td class="table-actions"><a class="link-button" href="/api/v2/templates/${esc(version.id)}/file" target="_blank" rel="noopener">Unduh privat</a>${version.canApprove?`<button class="link-button review-link" data-approve="${esc(version.id)}">Periksa & Setujui</button>`:''}</td>
    </tr>`)).join(''):'<tr><td colspan="7" class="table-empty">Belum ada versi template. Upload DOCX dinamis yang telah ditinjau.</td></tr>';notice.className='notice hidden';}catch(error){rows.innerHTML=`<tr><td colspan="7" class="table-empty">${esc(error.message)}</td></tr>`;showNotice(notice,error.message);}};
    form.addEventListener('submit',async event=>{event.preventDefault();const data=new FormData(form);try{const result=await api('/api/v2/templates',{method:'POST',body:data});form.reset();await refresh();const findings=result.validationFindings||[];showNotice(notice,findings.length?`Template tersimpan sebagai DRAFT. ${findings.join(' ')}`:'Template tersimpan sebagai DRAFT. Minta pemeriksa berbeda untuk meninjau placeholder dan redaksi.','success');}catch(error){showNotice(notice,error.message);}});
    document.getElementById('reloadTemplates').addEventListener('click',refresh);
    document.getElementById('cancelTemplateApproval').addEventListener('click',()=>approvalDialog.close());
    rows.addEventListener('click',event=>{const button=event.target.closest('[data-approve]');if(!button)return;approvalVersionId=button.dataset.approve;document.getElementById('approveTemplateNotice').className='notice hidden';approvalDialog.showModal();});
    document.getElementById('confirmTemplateApproval').addEventListener('click',async()=>{try{await api(`/api/v2/templates/${approvalVersionId}/approve`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({requiredSignatures:[{capacity:document.getElementById('signerCapacityOne').value.trim(),role:document.getElementById('signerRoleOne').value},{capacity:document.getElementById('signerCapacityTwo').value.trim(),role:document.getElementById('signerRoleTwo').value}]})});approvalDialog.close();await refresh();showNotice(notice,'Template disetujui dan menjadi versi aktif. Pastikan keputusan persyaratan tanda tangan sesuai kewenangan resmi.','success');}catch(error){showNotice(document.getElementById('approveTemplateNotice'),error.message);}});
    refresh();
})();
</script>
@endsection
