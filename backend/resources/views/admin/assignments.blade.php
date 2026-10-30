@extends('layouts.app')

@section('title', 'Penugasan')

@section('content')
<div class="page-heading">
    <div><span class="eyebrow">KEGIATAN LAPANGAN</span><h1>Penugasan</h1><p>Pilih kegiatan aktif dan petugas mitra yang sudah terverifikasi.</p></div>
    @if (auth()->user()->hasRole(['ADMIN_BPS','PJK','OPERATOR']))<button class="primary-button page-action" id="newAssignment">+ Buat Penugasan</button>@endif
</div>
<section class="panel">
    <div class="toolbar"><div class="search-box"><span>⌕</span><input id="assignmentSearch" placeholder="Cari kegiatan, mitra, atau wilayah"></div><button class="secondary-button" id="reloadAssignments">Cari</button></div>
    <div id="assignmentNotice" class="notice hidden" role="status"></div>
    <div class="table-wrap"><table class="data-table">
        <thead><tr><th>Kegiatan</th><th>Petugas</th><th>Jenis Kontrak</th><th>Target</th><th>Jadwal</th><th>Status</th></tr></thead>
        <tbody id="assignmentRows"><tr><td colspan="6" class="table-empty">Memuat penugasan…</td></tr></tbody>
    </table></div>
    <div class="table-footer"><span id="assignmentCount">—</span></div>
</section>

<dialog class="app-dialog" id="assignmentDialog">
    <form method="dialog" class="dialog-head"><div><span class="eyebrow">ALOKASI PETUGAS</span><h2>Buat Penugasan</h2></div><button class="icon-button" aria-label="Tutup">×</button></form>
    <form id="assignmentForm" class="dialog-content form-grid">
        <label class="full-width">Kegiatan aktif *<select id="assignmentActivity" required></select></label>
        <div class="full-width"><label>Petugas mitra terverifikasi *</label><div id="partnerOptions" class="checkbox-list"></div></div>
        <label class="full-width">Jenis kontrak petugas *<select name="contractCategory" id="contractCategory"><option value="PENDATAAN_LAPANGAN">Petugas Pendataan Lapangan (Pemetaan)</option><option value="PENGOLAHAN">Petugas Pengolahan</option><option value="PENDATAAN_DAN_PENGOLAHAN">Petugas Pendataan dan Pengolahan</option></select></label>
        <label class="full-width">Uraian pekerjaan *<textarea name="uraian" rows="2" required maxlength="1000"></textarea></label>
        <label>Wilayah<input name="wilayah" maxlength="200"></label>
        <label>Volume utama *<input name="volume" type="number" min="0.01" step="0.01" required></label>
        <label>Satuan *<input name="satuan" maxlength="40" required></label>
        <label>Jadwal mulai *<input name="jadwalMulai" type="date" required></label>
        <label>Jadwal selesai *<input name="jadwalSelesai" type="date" required></label>
        <div class="full-width target-builder"><div class="target-builder-head"><strong>Rincian target kerja</strong><button type="button" class="link-button" id="addAssignmentTarget">+ Tambah target</button></div><div id="assignmentTargets"></div></div>
        <div class="full-width form-note">Server memeriksa status kegiatan/mitra dan konflik jadwal. Tidak ada nilai pembayaran pada formulir penugasan.</div>
        <div class="full-width dialog-actions"><button type="button" class="secondary-button" id="cancelAssignment">Batal</button><button type="submit" class="primary-button">Simpan Penugasan</button></div>
    </form>
</dialog>

<dialog class="app-dialog compact-dialog" id="confirmAssignmentDialog">
    <form method="dialog" class="dialog-head"><div><span class="eyebrow">KONFIRMASI</span><h2>Simpan penugasan?</h2></div><button class="icon-button" aria-label="Tutup">×</button></form>
    <div class="dialog-content"><p>Penugasan hanya akan tersimpan jika kegiatan aktif, semua mitra terverifikasi, dan jadwal tidak bertabrakan.</p><div class="dialog-actions"><button class="secondary-button" id="cancelAssignmentConfirm" type="button">Kembali</button><button class="primary-button" id="confirmAssignmentSave" type="button">Konfirmasi</button></div></div>
</dialog>

<script>
(() => {
    const canCreate=@json(auth()->user()->hasRole(['ADMIN_BPS','PJK','OPERATOR']));
    const rows=document.getElementById('assignmentRows'), notice=document.getElementById('assignmentNotice');
    const dialog=document.getElementById('assignmentDialog'), confirmDialog=document.getElementById('confirmAssignmentDialog');
    const activitySelect=document.getElementById('assignmentActivity'), partnerOptions=document.getElementById('partnerOptions');
    const targetHost=document.getElementById('assignmentTargets'); let options=null;
    const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
    const api=async(url,opts={})=>{const response=await fetch(url,{credentials:'same-origin',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content,...(opts.headers||{})},...opts});const body=await response.json().catch(()=>({}));if(!response.ok)throw new Error(body.message||body.error||`Permintaan gagal (${response.status})`);return body;};
    const note=(message,type='error')=>{notice.textContent=message;notice.className=`notice notice-${type}`;};
    const targetMarkup=task=>`<div class="target-row">
        <label>Uraian *<input data-key="uraian" value="${esc(task?.uraian)}" required maxlength="500"></label>
        <label>Volume *<input data-key="target" type="number" min="0.01" step="0.01" value="${esc(task?.target||'')}" required></label>
        <label>Satuan *<input data-key="satuan" value="${esc(task?.satuan)}" required maxlength="40"></label>
        <label>Wilayah<input data-key="wilayah" value="${esc(task?.wilayah)}" maxlength="150"></label>
        <label>Mulai<input data-key="jadwalMulai" type="date" value="${esc(task?.jadwalMulai)}"></label>
        <label>Selesai<input data-key="jadwalSelesai" type="date" value="${esc(task?.jadwalSelesai)}"></label>
        <label class="full-width work-category-wrap">Jenis rincian *<select data-key="workCategory"><option value="PENDATAAN_LAPANGAN">Pendataan Lapangan</option><option value="PENGOLAHAN">Pengolahan</option></select></label>
        <button type="button" class="link-button remove-assignment-target">Hapus target</button>
    </div>`;
    const setTargetCategory=(row,category)=>{const select=row.querySelector('[data-key="workCategory"]');if(select)select.value=category;};
    const addTarget=(task={})=>{targetHost.insertAdjacentHTML('beforeend',targetMarkup(task));const row=targetHost.lastElementChild;setTargetCategory(row,document.getElementById('contractCategory').value==='PENGOLAHAN'?'PENGOLAHAN':'PENDATAAN_LAPANGAN');};
    const collectTargets=()=>[...targetHost.querySelectorAll('.target-row')].map(row=>Object.fromEntries([...row.querySelectorAll('[data-key]')].map(input=>[input.dataset.key,input.dataset.key==='target'?Number(input.value||0):input.value])));
    const loadOptions=async()=>{options=await api('/api/v2/assignments/options');activitySelect.innerHTML=options.kegiatan.map(a=>`<option value="${esc(a.id)}">${esc(a.kode)} · ${esc(a.nama)}</option>`).join('');partnerOptions.innerHTML=options.mitra.map(p=>`<label class="checkbox-row"><input type="checkbox" name="mitraIds" value="${esc(p.id)}"><span><strong>${esc(p.nama)}</strong><small>${esc(p.sobatId||p.kode||'')}</small></span></label>`).join('')||'<div class="form-note">Belum ada mitra aktif dan terverifikasi.</div>';if(options.kegiatan.length){activitySelect.dispatchEvent(new Event('change'));}};
     const refresh=async()=>{rows.innerHTML='<tr><td colspan="6" class="table-empty">Memuat penugasan…</td></tr>';try{const params=new URLSearchParams({limit:'100',offset:'0'});const q=document.getElementById('assignmentSearch').value.trim();if(q)params.set('q',q);const result=await api(`/api/v2/assignments?${params}`);rows.innerHTML=result.items.length?result.items.map(item=>`<tr><td><strong>${esc(item.kegiatanNama)}</strong><small class="cell-subtitle">${esc(item.kegiatanKode)}</small>${item.demo?'<small class="demo-tag">DEMO</small>':''}</td><td>${esc(item.mitraNama)}</td><td>${esc(item.contractCategory||'—')}</td><td>${esc(item.volume)} ${esc(item.satuan)}</td><td>${esc(item.jadwalMulai)} – ${esc(item.jadwalSelesai)}</td><td><span class="status-pill status-${esc(item.status.toLowerCase())}">${esc(item.status.replaceAll('_',' '))}</span></td></tr>`).join(''):'<tr><td colspan="6" class="table-empty">Belum ada penugasan.</td></tr>';document.getElementById('assignmentCount').textContent=`${result.items.length} dari ${result.total} penugasan`;notice.className='notice hidden';}catch(error){rows.innerHTML=`<tr><td colspan="6" class="table-empty">${esc(error.message)}</td></tr>`;note(error.message);}};
    document.getElementById('newAssignment')?.addEventListener('click',async()=>{if(!canCreate)return;try{if(!options)await loadOptions();const form=document.getElementById('assignmentForm');form.reset();targetHost.innerHTML='';document.getElementById('contractCategory').dispatchEvent(new Event('change'));addTarget();dialog.showModal();}catch(error){note(error.message);}});
    activitySelect.addEventListener('change',()=>{const activity=options?.kegiatan.find(item=>String(item.id)===activitySelect.value);if(!activity)return;const form=document.getElementById('assignmentForm');form.elements.uraian.value=activity.uraian||activity.nama;form.elements.wilayah.value=activity.wilayah||'';form.elements.volume.value=activity.targetVolume||'';form.elements.satuan.value=activity.satuan||'';form.elements.jadwalMulai.value=activity.tglMulai||'';form.elements.jadwalSelesai.value=activity.tglSelesai||'';targetHost.innerHTML='';(activity.targetPekerjaan?.length?activity.targetPekerjaan:[{}]).forEach(addTarget);});
    document.getElementById('contractCategory').addEventListener('change',event=>{const category=event.target.value;if(category!=='PENDATAAN_DAN_PENGOLAHAN'){targetHost.querySelectorAll('.target-row').forEach(row=>{setTargetCategory(row,category);row.querySelector('.work-category-wrap').classList.add('hidden');});}else targetHost.querySelectorAll('.work-category-wrap').forEach(node=>node.classList.remove('hidden'));});
    document.getElementById('addAssignmentTarget').addEventListener('click',()=>addTarget());
    targetHost.addEventListener('click',event=>{if(event.target.closest('.remove-assignment-target')){const all=targetHost.querySelectorAll('.target-row');if(all.length>1)event.target.closest('.target-row').remove();}});
    document.getElementById('cancelAssignment').addEventListener('click',()=>dialog.close());
    document.getElementById('cancelAssignmentConfirm').addEventListener('click',()=>confirmDialog.close());
    document.getElementById('reloadAssignments').addEventListener('click',refresh);
    document.getElementById('assignmentSearch').addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();refresh();}});
    document.getElementById('assignmentForm').addEventListener('submit',event=>{event.preventDefault();dialog.close();confirmDialog.showModal();});
    document.getElementById('confirmAssignmentSave').addEventListener('click',async()=>{confirmDialog.close();try{const form=document.getElementById('assignmentForm');const mitraIds=[...form.querySelectorAll('input[name="mitraIds"]:checked')].map(input=>Number(input.value));const payload={kegiatanId:Number(activitySelect.value),mitraIds,contractCategory:form.elements.contractCategory.value,uraian:form.elements.uraian.value.trim(),volume:Number(form.elements.volume.value),satuan:form.elements.satuan.value.trim(),wilayah:form.elements.wilayah.value.trim(),jadwalMulai:form.elements.jadwalMulai.value,jadwalSelesai:form.elements.jadwalSelesai.value,targetPekerjaan:collectTargets()};await api('/api/v2/assignments',{method:'POST',body:JSON.stringify(payload)});await refresh();note('Penugasan berhasil disimpan.','success');}catch(error){note(error.message);}});
    refresh();
})();
</script>
@endsection
