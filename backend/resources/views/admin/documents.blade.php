@extends('layouts.app')

@section('title', 'SPK & BAST')

@section('content')
@php($userRoles = auth()->user()->roles()->pluck('code')->all())
<div class="page-heading document-page-heading">
    <div><span class="eyebrow">SIKLUS DOKUMEN</span><h1>SPK & BAST</h1><p>Pantau draft, pemeriksaan, persetujuan, tanda tangan manual, dan arsip dalam satu alur.</p></div>
    <div class="document-page-actions">
        @if(auth()->user()->hasRole(['ADMIN_BPS','PJK','OPERATOR']))
            <button class="secondary-button" id="createBastButton" type="button">Buat BAST</button>
            <button class="primary-button" id="createSpkButton" type="button">+ Buat SPK</button>
        @endif
    </div>
</div>

<div class="document-process-strip" aria-label="Tahapan dokumen">
    <div><span>01</span><strong>Draft</strong></div><i></i><div><span>02</span><strong>Pemeriksaan</strong></div><i></i><div><span>03</span><strong>Persetujuan</strong></div><i></i><div><span>04</span><strong>Tanda tangan</strong></div><i></i><div><span>05</span><strong>Arsip</strong></div>
</div>
<div class="notice notice-info document-template-note"><strong>Prasyarat final:</strong> gunakan template DOCX placeholder yang sudah disahkan. Tanda tangan manual berarti scan hard-copy dan perlu validasi; sistem belum menyediakan TTE tersertifikasi.</div>

<section class="panel document-list-panel">
    <div class="document-toolbar">
        <div class="document-heading-copy"><h2>Daftar dokumen</h2><p>Data mengikuti lingkup akses akun Anda.</p></div>
        <div class="document-filters">
            <select id="documentTypeFilter" class="select-control" aria-label="Filter jenis dokumen"><option value="">Semua jenis</option><option value="SPK">SPK</option><option value="BAST">BAST</option></select>
            <select id="documentStatusFilter" class="select-control" aria-label="Filter status"><option value="">Semua status</option><option>DRAFT</option><option>PERLU_PERBAIKAN</option><option>MENUNGGU_PEMERIKSAAN</option><option>MENUNGGU_PERSETUJUAN</option><option>MENUNGGU_TANDATANGAN</option><option>MENUNGGU_FINALISASI</option><option>FINAL</option><option>SIMULASI_SELESAI</option></select>
            <button class="secondary-button" id="refreshDocuments" type="button">Muat ulang</button>
        </div>
    </div>
    <div id="documentsNotice" class="notice hidden" role="status" aria-live="polite"></div>
    <div class="table-wrap">
        <table class="data-table document-table">
            <thead><tr><th>Dokumen</th><th>Kegiatan / Mitra</th><th>Kategori</th><th>Status</th><th>Versi</th><th>Aksi</th></tr></thead>
            <tbody id="documentRows"><tr><td colspan="6" class="table-empty">Memuat dokumen…</td></tr></tbody>
        </table>
    </div>
    <div class="table-footer"><span id="documentCount">—</span><span>Demo dikecualikan dari arsip operasional</span></div>
</section>

<dialog class="app-dialog compact-dialog" id="createSpkDialog">
    <form method="dialog" class="dialog-head"><div><span class="eyebrow">DRAFT DOKUMEN</span><h2>Buat SPK</h2></div><button class="icon-button" aria-label="Tutup">×</button></form>
    <form id="createSpkForm" class="dialog-content form-grid">
        <label class="full-width">Penugasan dan petugas *<select id="spkAssignmentMember" required></select></label>
        <label class="full-width">Mode dokumen<select id="spkDocumentMode"><option value="TERPISAH">Terpisah (default)</option>@if($combinedModeEnabled)<option value="GABUNGAN">Gabungan (diizinkan)</option>@else<option value="GABUNGAN" disabled>Gabungan (belum diizinkan)</option>@endif</select></label>
        <p class="form-note full-width">Nomor resmi diisi setelah draft dibuat. Untuk petugas gabungan, mode terpisah membuat SPK Pendataan dan Pengolahan secara terpisah.</p>
        <div class="dialog-actions full-width"><button class="secondary-button" type="button" data-close-dialog="createSpkDialog">Batal</button><button class="primary-button" type="submit">Buat Draft SPK</button></div>
    </form>
</dialog>

<dialog class="app-dialog compact-dialog" id="createBastDialog">
    <form method="dialog" class="dialog-head"><div><span class="eyebrow">DRAFT SERAH TERIMA</span><h2>Buat BAST</h2></div><button class="icon-button" aria-label="Tutup">×</button></form>
    <form id="createBastForm" class="dialog-content form-grid">
        <label class="full-width">SPK final sumber *<select id="bastParentSpk" required></select></label>
        <p class="form-note full-width">Server hanya membuat BAST jika seluruh target pada SPK memiliki laporan hasil yang sudah diterima.</p>
        <div class="dialog-actions full-width"><button class="secondary-button" type="button" data-close-dialog="createBastDialog">Batal</button><button class="primary-button" type="submit">Buat Draft BAST</button></div>
    </form>
</dialog>

<dialog class="app-dialog document-detail-dialog" id="documentDetailDialog">
    <div class="dialog-head"><div><span class="eyebrow">DETAIL & TINDAKAN</span><h2 id="documentDetailTitle">Dokumen</h2></div><button class="icon-button" type="button" data-close-dialog="documentDetailDialog" aria-label="Tutup">×</button></div>
    <div class="dialog-content" id="documentDetailContent"><div class="table-empty">Pilih dokumen untuk melihat detail.</div></div>
</dialog>

<script>
(() => {
    const roles=@json($userRoles);
    const canPrepare=roles.some(role=>['ADMIN_BPS','PJK','OPERATOR'].includes(role));
    const canReview=roles.some(role=>['PJK','PEMERIKSA'].includes(role));
    const canApprove=roles.some(role=>['ADMIN_BPS','PEJABAT_PERSETUJU'].includes(role));
    const canSign=roles.some(role=>['PENANDATANGAN','PEJABAT_PERSETUJU','PETUGAS_MITRA'].includes(role));
    const canValidate=roles.some(role=>['ADMIN_BPS','PJK','PEMERIKSA'].includes(role));
    const canFinalize=roles.some(role=>['ADMIN_BPS','PEJABAT_PERSETUJU'].includes(role));
    const rows=document.getElementById('documentRows');
    const notice=document.getElementById('documentsNotice');
    const detailDialog=document.getElementById('documentDetailDialog');
    const detailContent=document.getElementById('documentDetailContent');
    let currentDocument=null, assignments=[];
    const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
    const statusLabel=status=>({DRAFT:'Draft',PERLU_PERBAIKAN:'Perlu perbaikan',MENUNGGU_PEMERIKSAAN:'Menunggu pemeriksaan',MENUNGGU_PERSETUJUAN:'Menunggu persetujuan',MENUNGGU_TANDATANGAN:'Menunggu tanda tangan',MENUNGGU_FINALISASI:'Menunggu finalisasi',FINAL:'Final',SIMULASI_SELESAI:'Simulasi selesai'})[status]||String(status||'—').replaceAll('_',' ');
    const categoryLabel=value=>({PENDATAAN_LAPANGAN:'Pendataan Lapangan',PENGOLAHAN:'Pengolahan',PENDATAAN_DAN_PENGOLAHAN:'Pendataan dan Pengolahan'})[value]||value||'—';
    const showNotice=(message,type='error')=>{notice.textContent=message;notice.className=`notice notice-${type}`;};
    const hideNotice=()=>{notice.textContent='';notice.className='notice hidden'};
    const api=async(url,options={})=>{
        const {headers:extraHeaders={},...requestOptions}=options;
        const response=await fetch(url,{...requestOptions,credentials:'same-origin',headers:{'Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content,...extraHeaders}});
        const body=await response.json().catch(()=>({}));
        if(!response.ok)throw new Error(body.message||`Permintaan gagal (${response.status})`);
        return body;
    };
    const refresh=async()=>{
        rows.innerHTML='<tr><td colspan="6" class="table-empty">Memuat dokumen…</td></tr>';
        try{
            const params=new URLSearchParams({limit:'100',offset:'0'});
            const type=document.getElementById('documentTypeFilter').value;
            const status=document.getElementById('documentStatusFilter').value;
            if(type)params.set('type',type);if(status)params.set('status',status);
            const result=await api(`/api/v2/documents?${params}`);
            rows.innerHTML=result.items.length?result.items.map(doc=>`<tr>
                <td><strong>${esc(doc.documentType)}${doc.demo?' · DEMO':''}</strong><small class="cell-subtitle">${esc(doc.documentNumber||'Nomor belum diisi')}</small></td>
                <td><strong>${esc(doc.activityName)}</strong><small class="cell-subtitle">${esc(doc.partnerName)}</small></td>
                <td>${esc(categoryLabel(doc.workerCategory))}</td>
                <td><span class="status-pill status-${esc(String(doc.status).toLowerCase())}">${esc(statusLabel(doc.status))}</span></td>
                <td>v${esc(doc.version)}</td><td><button class="link-button" type="button" data-open-document="${esc(doc.id)}">Lihat detail</button></td>
            </tr>`).join(''):'<tr><td colspan="6" class="table-empty">Belum ada dokumen dalam lingkup akun ini.</td></tr>';
            document.getElementById('documentCount').textContent=`${result.items.length} dari ${result.total} dokumen`;
            hideNotice();
        }catch(error){rows.innerHTML=`<tr><td colspan="6" class="table-empty">${esc(error.message)}</td></tr>`;showNotice(error.message)}
    };
    const openPdf=async(url,filename,download=false)=>{
        const response=await fetch(url,{credentials:'same-origin',headers:{'Accept':'application/pdf','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content}});
        if(!response.ok){const body=await response.json().catch(()=>({}));throw new Error(body.message||`PDF gagal dibuka (${response.status})`)}
        const blob=await response.blob();const objectUrl=URL.createObjectURL(blob);const link=document.createElement('a');link.href=objectUrl;if(download)link.download=filename;else{link.target='_blank';link.rel='noopener'}link.click();setTimeout(()=>URL.revokeObjectURL(objectUrl),60000);
    };
    const refreshDetails=async(id)=>{
        const doc=await api(`/api/v2/documents/${id}`);currentDocument=doc;
        document.getElementById('documentDetailTitle').textContent=`${doc.documentType} · ${doc.documentNumber||'Draft'}`;
        const snapshot=doc.snapshot||{};
        const info=`<div class="document-detail-top"><div><span class="status-pill status-${esc(String(doc.status).toLowerCase())}">${esc(statusLabel(doc.status))}</span>${doc.demo?'<span class="demo-tag">DEMO — BUKAN DOKUMEN RESMI</span>':''}</div><small>Versi ${esc(doc.version)} · ${doc.templateApproved?'Template disetujui':'Template belum disetujui'}</small></div>
        <div class="document-summary-grid"><div><span>Kegiatan</span><strong>${esc(doc.activityName)}</strong></div><div><span>Mitra</span><strong>${esc(doc.partnerName)}</strong></div><div><span>Kategori</span><strong>${esc(categoryLabel(doc.workerCategory))}</strong></div><div><span>Mode</span><strong>${esc(doc.documentMode)}</strong></div>${doc.parentDocumentId?`<div><span>Referensi SPK</span><strong>${esc(doc.parentDocumentId)}</strong></div>`:''}</div>
        ${doc.demo?'<div class="notice notice-warning">Ini record demo. Tidak ada template resmi atau tanda tangan yang direkam; jangan gunakan sebagai dokumen operasional.</div>':''}
        ${(doc.status==='DRAFT'||doc.status==='PERLU_PERBAIKAN')&&canPrepare&&!doc.demo?`<section class="document-action-panel"><h3>Isi draft</h3><div class="form-grid"><label>Nomor dokumen<input id="draftNumber" value="${esc(doc.documentNumber)}" maxlength="120"></label><label>Tanggal dokumen<input id="draftDate" type="date" value="${esc(snapshot.document_date)}"></label>${doc.documentType==='BAST'?`<label>Tanggal serah terima<input id="handoverDate" type="date" value="${esc(snapshot.handover_date)}"></label>`:''}</div>${(doc.templateFields||[]).includes('work_item_value')?`<div class="document-item-values"><h4>Nilai per rincian dokumen</h4><p class="muted">Isi hanya bila memang diwajibkan template resmi; sistem tidak menghitung nilai.</p>${(snapshot.work_items||[]).map((item,index)=>`<label class="document-value-row">${esc(item.uraian)}<input data-document-value="${esc(item.assignmentWorkItemId)}" value="${esc(item.documentValue)}" maxlength="120" placeholder="Nilai sesuai dokumen"></label>`).join('')}</div>`:''}<div class="document-action-buttons"><button class="secondary-button" type="button" data-save-draft="${esc(doc.id)}">Simpan draft</button><button class="secondary-button" type="button" data-preview="${esc(doc.id)}">Pratinjau PDF</button><button class="primary-button" type="button" data-submit-document="${esc(doc.id)}">Kirim untuk pemeriksaan</button></div><small class="document-helper">Pemeriksaan hanya dapat dikirim setelah nomor dokumen, template disetujui, dan pratinjau berhasil dibuat.</small></section>`:''}
        ${doc.status==='MENUNGGU_PEMERIKSAAN'&&canReview&&!doc.demo?`<section class="document-action-panel"><h3>Pemeriksaan</h3><label class="field-label">Catatan pemeriksa<textarea id="reviewNote" rows="2" placeholder="Wajib diisi jika meminta perbaikan"></textarea></label><div class="document-action-buttons"><button class="secondary-button" type="button" data-review="PERLU_PERBAIKAN" data-document="${esc(doc.id)}">Minta perbaikan</button><button class="primary-button" type="button" data-review="SETUJUI" data-document="${esc(doc.id)}">Setujui pemeriksaan</button></div></section>`:''}
        ${doc.status==='MENUNGGU_PERSETUJUAN'&&canApprove&&!doc.demo?`<section class="document-action-panel"><h3>Persetujuan</h3><p class="muted">Penyetuju harus berbeda dari pembuat dan pemeriksa.</p><button class="primary-button" type="button" data-approve-document="${esc(doc.id)}">Setujui dan teruskan ke tanda tangan</button></section>`:''}
        ${doc.status==='MENUNGGU_TANDATANGAN'&&canSign&&!doc.demo?`<section class="document-action-panel"><h3>Unggah bukti tanda tangan manual</h3><p class="muted">Unggah scan dokumen hard-copy bertanda tangan. Ini bukan TTE tersertifikasi.</p><form data-sign-form="${esc(doc.id)}" class="form-grid"><label>Kapasitas penandatangan<select name="capacity" required>${(doc.requiredSignatures||[]).map(item=>`<option value="${esc(item.capacity)}">${esc(item.capacity)}</option>`).join('')}</select></label><label>PDF hasil tanda tangan<input type="file" name="signedDocument" accept="application/pdf,.pdf" required></label><input type="hidden" name="signatureMethod" value="MANUAL_HARD_COPY"><div class="document-action-buttons full-width"><button class="primary-button" type="submit">Simpan bukti manual</button></div></form></section>`:''}
        ${(doc.signatures||[]).length?`<section class="document-action-panel"><h3>Bukti tanda tangan manual</h3>${doc.signatures.map(signature=>`<div class="signature-row"><div><strong>${esc(signature.capacity)}</strong><small>${esc(signature.signerName)} · ${esc(signature.method)} · ${esc(signature.status)}</small></div><div class="signature-actions"><button class="link-button" type="button" data-signature-file="${esc(signature.id)}" data-document="${esc(doc.id)}">Lihat file</button>${canValidate&&signature.status==='MENUNGGU_VERIFIKASI'?`<div class="signature-review"><input data-rejection-note="${esc(signature.id)}" aria-label="Catatan penolakan" placeholder="Catatan jika ditolak"><button class="link-button review-link" type="button" data-validate-signature="${esc(signature.id)}" data-document="${esc(doc.id)}" data-decision="VALID">Validasi</button><button class="link-button" type="button" data-validate-signature="${esc(signature.id)}" data-document="${esc(doc.id)}" data-decision="DITOLAK">Tolak</button></div>`:''}</div></div>`).join('')}</section>`:''}
        ${doc.status==='MENUNGGU_FINALISASI'&&canFinalize&&!doc.demo?`<section class="document-action-panel"><h3>Finalisasi dan arsip</h3><p class="muted">Pastikan seluruh tanda tangan manual sudah divalidasi dan file final sesuai versi yang disetujui.</p><form data-finalize-form="${esc(doc.id)}" class="form-grid"><label>PDF final hasil tanda tangan<input type="file" name="finalSignedPdf" accept="application/pdf,.pdf" required></label><label class="checkbox-row"><input type="checkbox" name="confirmComplete" value="1" required><span>Saya memastikan dokumen lengkap dan siap diarsipkan.</span></label><label class="full-width">Catatan arsip<textarea name="note" rows="2"></textarea></label><div class="document-action-buttons full-width"><button class="primary-button" type="submit">Finalisasi dan arsipkan</button></div></form></section>`:''}
        ${doc.status==='FINAL'||doc.status==='SIMULASI_SELESAI'?`<section class="document-action-panel"><h3>Arsip privat</h3><p class="muted">${doc.demo?'File simulasi dengan watermark DEMO.':'PDF final tersimpan pada arsip privat.'}</p><button class="primary-button" type="button" data-download-document="${esc(doc.id)}">Unduh PDF${doc.demo?' DEMO':''}</button></section>`:''}
        <section class="document-action-buttons document-footer-actions"><button class="secondary-button" type="button" data-close-dialog="documentDetailDialog">Tutup</button></section>`;
        detailContent.innerHTML=info;
        document.querySelectorAll('[data-close-dialog="documentDetailDialog"]').forEach(button=>button.addEventListener('click',()=>detailDialog.close()));
    };
    const loadAssignmentOptions=async()=>{
        if(assignments.length)return assignments;
        const result=await api('/api/v2/assignments?limit=100&offset=0');
        assignments=result.items.filter(item=>!item.demo).flatMap(item=>(item.members||[]).map(member=>({
            assignmentMemberId:member.assignmentMemberId,
            label:`${item.kegiatanKode} · ${item.kegiatanNama} · ${member.nama} · ${categoryLabel(member.contractCategory)}`,
            category:member.contractCategory
        })));
        return assignments;
    };
    const openCreateSpk=async()=>{
        try{const options=await loadAssignmentOptions();const select=document.getElementById('spkAssignmentMember');select.innerHTML=options.map(item=>`<option value="${esc(item.assignmentMemberId)}">${esc(item.label)}</option>`).join('')||'<option value="">Belum ada penugasan tersedia</option>';document.getElementById('createSpkButton').disabled=!options.length;document.getElementById('createSpkDialog').showModal()}catch(error){showNotice(error.message)}};
    const openCreateBast=async()=>{
        try{const result=await api('/api/v2/documents?type=SPK&status=FINAL&limit=100&offset=0');const select=document.getElementById('bastParentSpk');const eligible=result.items.filter(item=>!item.demo);select.innerHTML=eligible.map(item=>`<option value="${esc(item.id)}">${esc(item.documentNumber)} · ${esc(item.partnerName)} · ${esc(item.activityName)}</option>`).join('')||'<option value="">Belum ada SPK final non-demo</option>';document.getElementById('createBastButton').disabled=!eligible.length;document.getElementById('createBastDialog').showModal()}catch(error){showNotice(error.message)}};
    document.getElementById('createSpkButton')?.addEventListener('click',openCreateSpk);
    document.getElementById('createBastButton')?.addEventListener('click',openCreateBast);
    document.querySelectorAll('[data-close-dialog]').forEach(button=>button.addEventListener('click',()=>document.getElementById(button.dataset.closeDialog)?.close()));
    document.getElementById('createSpkForm').addEventListener('submit',async event=>{event.preventDefault();const button=event.submitter;button.disabled=true;try{const body=await api('/api/v2/spk/drafts',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({assignmentMemberId:Number(document.getElementById('spkAssignmentMember').value),documentMode:document.getElementById('spkDocumentMode').value})});document.getElementById('createSpkDialog').close();await refresh();if(body.items?.length)openDetails(body.items[0].id);showNotice('Draft SPK berhasil dibuat. Isi nomor resmi dan gunakan template yang disetujui.','success')}catch(error){showNotice(error.message)}finally{button.disabled=false}});
    document.getElementById('createBastForm').addEventListener('submit',async event=>{event.preventDefault();const button=event.submitter;button.disabled=true;try{const body=await api('/api/v2/bast/drafts',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({spkDocumentId:Number(document.getElementById('bastParentSpk').value)})});document.getElementById('createBastDialog').close();await refresh();openDetails(body.id);showNotice('Draft BAST berhasil dibuat.','success')}catch(error){showNotice(error.message)}finally{button.disabled=false}});
    async function openDetails(id){try{await refreshDetails(id);detailDialog.showModal()}catch(error){showNotice(error.message)}}
    rows.addEventListener('click',event=>{const button=event.target.closest('[data-open-document]');if(button)openDetails(button.dataset.openDocument)});
    document.getElementById('documentTypeFilter').addEventListener('change',refresh);
    document.getElementById('documentStatusFilter').addEventListener('change',refresh);
    document.getElementById('refreshDocuments').addEventListener('click',refresh);
    detailContent.addEventListener('click',async event=>{
        const button=event.target.closest('button');if(!button)return;const id=button.dataset.document||button.dataset.saveDraft||button.dataset.preview||button.dataset.submitDocument||button.dataset.approveDocument||button.dataset.downloadDocument;
        try{
            if(button.dataset.saveDraft){
                const values=[...detailContent.querySelectorAll('[data-document-value]')].map(input=>({assignmentWorkItemId:Number(input.dataset.documentValue),value:input.value.trim()}));
                const data={documentNumber:document.getElementById('draftNumber')?.value.trim()||'',documentDate:document.getElementById('draftDate')?.value||''};
                if(document.getElementById('handoverDate'))data.handoverDate=document.getElementById('handoverDate').value;
                if(values.length)data.workItemValues=values;
                await api(`/api/v2/documents/${id}/draft`,{method:'PUT',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});await refresh();await refreshDetails(id);showNotice('Draft berhasil disimpan.','success');
            }else if(button.dataset.preview){
                await openPdf(`/api/v2/documents/${id}/preview`,`${currentDocument.documentType}-preview.pdf`);
            }else if(button.dataset.submitDocument){
                await api(`/api/v2/documents/${id}/submit`,{method:'POST'});await refresh();await refreshDetails(id);showNotice('Dokumen dikirim untuk pemeriksaan.','success');
            }else if(button.dataset.review){
                const note=document.getElementById('reviewNote')?.value.trim()||'';await api(`/api/v2/documents/${id}/review`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({decision:button.dataset.review,note})});await refresh();await refreshDetails(id);showNotice('Hasil pemeriksaan tersimpan.','success');
            }else if(button.dataset.approveDocument){
                await api(`/api/v2/documents/${id}/approve`,{method:'POST'});await refresh();await refreshDetails(id);showNotice('Persetujuan tersimpan.','success');
            }else if(button.dataset.validateSignature){
                const note=detailContent.querySelector(`[data-rejection-note="${button.dataset.validateSignature}"]`)?.value.trim()||null;if(button.dataset.decision==='DITOLAK'&&!note){showNotice('Isi catatan jika bukti tanda tangan ditolak.');return}await api(`/api/v2/documents/${id}/signatures/${button.dataset.validateSignature}/validate`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({decision:button.dataset.decision,note})});await refreshDetails(id);showNotice('Validasi tanda tangan manual tersimpan.','success');
            }else if(button.dataset.signatureFile){
                await openPdf(`/api/v2/documents/${id}/signatures/${button.dataset.signatureFile}/file`,'bukti-tanda-tangan-manual.pdf');
            }else if(button.dataset.downloadDocument){
                await openPdf(`/api/v2/documents/${id}/file`,`${currentDocument.documentType}-${currentDocument.documentNumber}.pdf`,true);
            }
        }catch(error){showNotice(error.message)}
    });
    detailContent.addEventListener('submit',async event=>{
        const signForm=event.target.closest('[data-sign-form]');const finalizeForm=event.target.closest('[data-finalize-form]');if(!signForm&&!finalizeForm)return;event.preventDefault();const form=event.target;const id=signForm?signForm.dataset.signForm:finalizeForm.dataset.finalizeForm;const button=form.querySelector('[type="submit"]');button.disabled=true;
        try{const body=new FormData(form);const response=await fetch(signForm?`/api/v2/documents/${id}/signatures`:`/api/v2/documents/${id}/finalize`,{method:'POST',credentials:'same-origin',headers:{'Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body});const data=await response.json().catch(()=>({}));if(!response.ok)throw new Error(data.message||'Unggahan gagal disimpan.');await refresh();await refreshDetails(id);showNotice(signForm?'Bukti tanda tangan manual berhasil diunggah.':'Dokumen simulasi/final berhasil diproses server.','success')}
        catch(error){showNotice(error.message)}finally{button.disabled=false}
    });
    const initialType=new URLSearchParams(location.search).get('type');if(initialType)document.getElementById('documentTypeFilter').value=initialType;
    refresh();
})();
</script>
@endsection
