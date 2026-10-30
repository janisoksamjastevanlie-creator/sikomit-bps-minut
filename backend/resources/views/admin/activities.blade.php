@extends('layouts.app')

@section('title', 'Kegiatan')

@section('content')
<div class="page-heading">
    <div><span class="eyebrow">MASTER KEGIATAN</span><h1>Kegiatan</h1><p>Kelola periode, wilayah, target, dan kebutuhan mitra.</p></div>
    @if (auth()->user()->hasRole(['ADMIN_BPS','PJK','OPERATOR']))<button class="primary-button page-action" id="newActivity">+ Tambah Kegiatan</button>@endif
</div>
<section class="panel">
    <div class="toolbar">
        <div class="search-box"><span>⌕</span><input id="activitySearch" placeholder="Cari kode, nama, atau wilayah"></div>
        <input id="activityYear" class="select-control" inputmode="numeric" maxlength="4" placeholder="Tahun">
        <select id="activityStatus" class="select-control"><option value="">Semua status</option><option>DRAFT</option><option>AKTIF</option><option>SELESAI</option><option>DIBATALKAN</option></select>
        <button class="secondary-button" id="reloadActivities">Cari</button>
    </div>
    <div id="activityNotice" class="notice hidden" role="status"></div>
    <div class="table-wrap"><table class="data-table">
        <thead><tr><th>Kegiatan</th><th>Tahun/Periode</th><th>Penanggung Jawab</th><th>Wilayah</th><th>Target</th><th>Status</th><th></th></tr></thead>
        <tbody id="activityRows"><tr><td colspan="7" class="table-empty">Memuat kegiatan…</td></tr></tbody>
    </table></div>
    <div class="table-footer"><span id="activityCount">—</span></div>
</section>

<dialog class="app-dialog" id="activityDialog">
    <form method="dialog" class="dialog-head"><div><span class="eyebrow">KEGIATAN STATISTIK</span><h2 id="activityDialogTitle">Tambah Kegiatan</h2></div><button class="icon-button" aria-label="Tutup">×</button></form>
    <form id="activityForm" class="dialog-content form-grid">
        <label>Kode (otomatis jika kosong)<input name="kode" maxlength="50"></label>
        <label>Nama kegiatan *<input name="nama" maxlength="200" required></label>
        <label>Tahun *<input name="tahunAnggaran" value="{{ now()->year }}" inputmode="numeric" maxlength="4" required></label>
        <label>Periode<input name="periode" maxlength="100"></label>
        <label>Penanggung jawab *<input name="penanggungJawab" maxlength="180" required></label>
        <label>Jenis kegiatan<input name="jenisKegiatan" maxlength="100"></label>
        <label class="full-width">Uraian pekerjaan<textarea name="uraian" rows="2" maxlength="5000"></textarea></label>
        <label>Wilayah<input name="wilayah" maxlength="200"></label>
        <label>Kebutuhan mitra *<input name="kebutuhanMitra" type="number" min="0" max="10000" required></label>
        <label>Target total<input name="targetVolume" type="number" min="0" step="0.01" value="0"></label>
        <label>Satuan<input name="satuan" maxlength="40"></label>
        <label>Tanggal mulai<input name="tglMulai" type="date"></label>
        <label>Tanggal selesai<input name="tglSelesai" type="date"></label>
        <label class="full-width">Persyaratan administrasi<textarea name="persyaratanAdministrasi" rows="2" placeholder="Satu persyaratan per baris"></textarea></label>
        <div class="full-width target-builder">
            <div class="target-builder-head"><strong>Target pekerjaan</strong><button type="button" class="link-button" id="addActivityTarget">+ Tambah target</button></div>
            <div id="activityTargets"></div>
        </div>
        <div class="full-width form-note">Tarif, anggaran, honor, dan nilai keuangan tidak dikelola pada master kegiatan SiKomit.</div>
        <div class="full-width dialog-actions"><button type="button" class="secondary-button" id="cancelActivity">Batal</button><button type="submit" class="primary-button">Simpan Draft</button></div>
    </form>
</dialog>

<dialog class="app-dialog compact-dialog" id="activateActivityDialog">
    <form method="dialog" class="dialog-head"><div><span class="eyebrow">KONFIRMASI</span><h2>Aktifkan kegiatan?</h2></div><button class="icon-button" aria-label="Tutup">×</button></form>
    <div class="dialog-content"><p>Pastikan penanggung jawab, jadwal, kebutuhan mitra, serta target telah diperiksa.</p><div class="dialog-actions"><button class="secondary-button" id="cancelActivate" type="button">Batal</button><button class="primary-button" id="confirmActivate" type="button">Aktifkan</button></div></div>
</dialog>

<script>
(() => {
    const canManage = @json(auth()->user()->hasRole(['ADMIN_BPS','PJK','OPERATOR']));
    const rows = document.getElementById('activityRows');
    const notice = document.getElementById('activityNotice');
    const dialog = document.getElementById('activityDialog');
    const form = document.getElementById('activityForm');
    const targetHost = document.getElementById('activityTargets');
    let currentId = null, pendingActivationId = null;

    const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
    const statusLabel = value => ({DRAFT:'Draft',AKTIF:'Aktif',SELESAI:'Selesai',DIBATALKAN:'Dibatalkan'})[value] || value;
    const api = async (url, options = {}) => {
        const response = await fetch(url, {credentials:'same-origin',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content,...(options.headers||{})},...options});
        const body = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(body.message || body.error || `Permintaan gagal (${response.status})`);
        return body;
    };
    const showNotice = (message, type='error') => { notice.textContent=message; notice.className=`notice notice-${type}`; };
    const targetMarkup = task => `<div class="target-row">
        <label>Uraian *<input data-key="uraian" value="${esc(task?.uraian)}" maxlength="500" required></label>
        <label>Target *<input data-key="target" type="number" min="0" step="0.01" value="${esc(task?.target ?? 0)}" required></label>
        <label>Satuan<input data-key="satuan" value="${esc(task?.satuan)}" maxlength="40"></label>
        <label>Wilayah<input data-key="wilayah" value="${esc(task?.wilayah)}" maxlength="150"></label>
        <label>Mulai<input data-key="jadwalMulai" type="date" value="${esc(task?.jadwalMulai)}"></label>
        <label>Selesai<input data-key="jadwalSelesai" type="date" value="${esc(task?.jadwalSelesai)}"></label>
        <button type="button" class="link-button remove-target">Hapus target</button>
    </div>`;
    const setTargets = targets => { targetHost.innerHTML=''; (targets?.length ? targets : [{}]).forEach(task=>targetHost.insertAdjacentHTML('beforeend',targetMarkup(task))); };
    const collectTargets = () => [...targetHost.querySelectorAll('.target-row')].map(row => Object.fromEntries([...row.querySelectorAll('[data-key]')].map(input=>[input.dataset.key,input.dataset.key==='target' ? Number(input.value||0) : input.value.trim()])));
    const refresh = async () => {
        rows.innerHTML='<tr><td colspan="7" class="table-empty">Memuat kegiatan…</td></tr>';
        try {
            const params = new URLSearchParams({limit:'100',offset:'0'});
            const q=document.getElementById('activitySearch').value.trim(), year=document.getElementById('activityYear').value.trim(), status=document.getElementById('activityStatus').value;
            if(q) params.set('q',q); if(year) params.set('tahun',year); if(status) params.set('status',status);
            const result=await api(`/api/v2/activities?${params}`);
            rows.innerHTML=result.items.length ? result.items.map(item=>`<tr>
                <td><strong>${esc(item.nama)}</strong><small class="cell-subtitle">${esc(item.kode)}</small>${item.demo?'<small class="demo-tag">DEMO</small>':''}</td>
                <td>${esc(item.tahunAnggaran)}<small class="cell-subtitle">${esc(item.periode||'—')}</small></td>
                <td>${esc(item.penanggungJawab||'—')}</td><td>${esc(item.wilayah||'—')}</td>
                <td>${esc(item.targetVolume)} ${esc(item.satuan)}</td>
                <td><span class="status-pill status-${esc(item.status.toLowerCase())}">${esc(statusLabel(item.status))}</span></td>
                <td class="table-actions"><button class="link-button" data-view="${esc(item.id)}">Detail</button>${canManage&&item.status==='DRAFT'?`<button class="link-button" data-edit="${esc(item.id)}">Ubah</button><button class="link-button review-link" data-activate="${esc(item.id)}">Aktifkan</button>`:''}</td>
            </tr>`).join(''):'<tr><td colspan="7" class="table-empty">Belum ada kegiatan sesuai filter.</td></tr>';
            document.getElementById('activityCount').textContent=`${result.items.length} dari ${result.total} kegiatan`;
            notice.className='notice hidden';
        } catch(error) { rows.innerHTML=`<tr><td colspan="7" class="table-empty">${esc(error.message)}</td></tr>`; showNotice(error.message); }
    };
    const fill = activity => {
        for(const [key,value] of Object.entries({kode:activity.kode||'',nama:activity.nama||'',tahunAnggaran:activity.tahunAnggaran||'{{ now()->year }}',periode:activity.periode||'',penanggungJawab:activity.penanggungJawab||'',jenisKegiatan:activity.jenisKegiatan||'',uraian:activity.uraian||'',wilayah:activity.wilayah||'',kebutuhanMitra:activity.kebutuhanMitra||0,targetVolume:activity.targetVolume||0,satuan:activity.satuan||'',tglMulai:activity.tglMulai||'',tglSelesai:activity.tglSelesai||'',persyaratanAdministrasi:(activity.persyaratanAdministrasi||[]).join('\n')})) form.elements[key].value=value;
        setTargets(activity.targetPekerjaan||[]);
    };
    document.getElementById('newActivity')?.addEventListener('click',()=>{currentId=null;form.reset();for(const element of form.elements){if(element.name)element.disabled=false;}setTargets([]);document.getElementById('addActivityTarget').disabled=false;form.querySelector('[type="submit"]').classList.remove('hidden');document.getElementById('activityDialogTitle').textContent='Tambah Kegiatan';dialog.showModal();});
    document.getElementById('cancelActivity').addEventListener('click',()=>dialog.close());
    document.getElementById('addActivityTarget').addEventListener('click',()=>targetHost.insertAdjacentHTML('beforeend',targetMarkup({})));
    targetHost.addEventListener('click',event=>{if(event.target.closest('.remove-target')){const all=targetHost.querySelectorAll('.target-row');if(all.length>1)event.target.closest('.target-row').remove();}});
    document.getElementById('reloadActivities').addEventListener('click',refresh);
    document.getElementById('activityStatus').addEventListener('change',refresh);
    document.getElementById('activitySearch').addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();refresh();}});
    document.getElementById('activityYear').addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();refresh();}});
    rows.addEventListener('click',async event=>{
        const button=event.target.closest('button');if(!button)return;
        const id=button.dataset.view||button.dataset.edit||button.dataset.activate;if(!id)return;
        try{
            const activity=await api(`/api/v2/activities/${id}`);
            if(button.dataset.activate){pendingActivationId=id;document.getElementById('activateActivityDialog').showModal();return;}
            currentId=button.dataset.edit?id:null;fill(activity);document.getElementById('activityDialogTitle').textContent=currentId?'Ubah Draft Kegiatan':'Detail Kegiatan';
            for(const element of form.elements){if(element.name)element.disabled=!currentId;}
            document.getElementById('addActivityTarget').disabled=!currentId;
            form.querySelector('[type="submit"]').classList.toggle('hidden',!currentId);
            form.querySelectorAll('.remove-target').forEach(control=>control.classList.toggle('hidden',!currentId));
            dialog.showModal();
        }catch(error){showNotice(error.message);}
    });
    form.addEventListener('submit',async event=>{
        event.preventDefault();const f=Object.fromEntries(new FormData(form).entries());
        const payload={kode:f.kode,nama:f.nama,tahunAnggaran:f.tahunAnggaran,periode:f.periode,penanggungJawab:f.penanggungJawab,jenisKegiatan:f.jenisKegiatan,uraian:f.uraian,wilayah:f.wilayah,targetVolume:Number(f.targetVolume||0),satuan:f.satuan,tglMulai:f.tglMulai||null,tglSelesai:f.tglSelesai||null,persyaratanAdministrasi:f.persyaratanAdministrasi.split(/\r?\n/).map(x=>x.trim()).filter(Boolean),kebutuhanMitra:Number(f.kebutuhanMitra||0),targetPekerjaan:collectTargets()};
        try{await api(currentId?`/api/v2/activities/${currentId}`:'/api/v2/activities',{method:currentId?'PUT':'POST',body:JSON.stringify(payload)});dialog.close();await refresh();showNotice('Draft kegiatan tersimpan.','success');}
        catch(error){showNotice(error.message);}
    });
    document.getElementById('cancelActivate').addEventListener('click',()=>document.getElementById('activateActivityDialog').close());
    document.getElementById('confirmActivate').addEventListener('click',async()=>{try{await api(`/api/v2/activities/${pendingActivationId}/activate`,{method:'POST',body:'{}'});document.getElementById('activateActivityDialog').close();await refresh();showNotice('Kegiatan berhasil diaktifkan.','success');}catch(error){document.getElementById('activateActivityDialog').close();showNotice(error.message);}});
    document.getElementById('addActivityTarget').disabled=!canManage;
    refresh();
})();
</script>
@endsection
