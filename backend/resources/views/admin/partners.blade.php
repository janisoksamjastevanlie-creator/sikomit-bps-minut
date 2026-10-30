@extends('layouts.app')

@section('title', 'Mitra')

@section('content')
<div class="page-heading">
    <div><span class="eyebrow">MASTER DATA</span><h1>Mitra</h1><p>Kelola profil dan pemeriksaan administrasi mitra sesuai kewenangan.</p></div>
    @if (auth()->user()->hasRole(['ADMIN_BPS','ADMIN_MITRA','OPERATOR']))
        <button class="primary-button page-action" id="newPartner">+ Tambah Mitra</button>
    @endif
</div>

<section class="panel">
    <div class="toolbar">
        <div class="search-box"><span>⌕</span><input id="partnerSearch" placeholder="Cari nama atau ID mitra" autocomplete="off"></div>
        <select id="partnerStatus" class="select-control">
            <option value="">Semua status</option>
            <option value="DRAFT">Draft</option>
            <option value="MENUNGGU_VERIFIKASI">Menunggu Verifikasi</option>
            <option value="PERLU_PERBAIKAN">Perlu Perbaikan</option>
            <option value="TERVERIFIKASI">Terverifikasi</option>
            <option value="TIDAK_MEMENUHI">Tidak Memenuhi</option>
        </select>
        <button class="secondary-button" id="reloadPartners">Muat Ulang</button>
    </div>
    <div id="partnerNotice" class="notice hidden" role="status"></div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Nama Mitra</th><th>ID Mitra</th><th>Wilayah</th><th>Status Administrasi</th><th>Status</th><th></th></tr></thead>
            <tbody id="partnerRows"><tr><td colspan="6" class="table-empty">Memuat data mitra…</td></tr></tbody>
        </table>
    </div>
    <div class="table-footer"><span id="partnerCount">—</span><button class="secondary-button hidden" id="morePartners">Muat berikutnya</button></div>
</section>

<dialog class="app-dialog" id="partnerDialog">
    <form method="dialog" class="dialog-head"><div><span class="eyebrow">DATA ADMINISTRASI</span><h2 id="partnerDialogTitle">Tambah Mitra</h2></div><button class="icon-button" aria-label="Tutup">×</button></form>
    <form id="partnerForm" class="dialog-content form-grid">
        <label>Nama mitra *<input name="nama" maxlength="180" required></label>
        <label>ID Sobat<input name="sobatId" maxlength="60"></label>
        <label>Kode mitra (jika ada)<input name="partnerCode" maxlength="50"></label>
        <label>Jenis mitra<select name="jenis"><option>PERORANGAN</option><option>LEMBAGA</option><option>PERUSAHAAN</option></select></label>
        <label>Nomor kontak<input name="wa" maxlength="30"></label>
        <label>Email<input name="email" type="email" maxlength="255"></label>
        <label class="full-width">Alamat yang diperlukan<textarea name="alamat" rows="2" maxlength="500"></textarea></label>
        <label>Kecamatan<input name="kecamatan" maxlength="100"></label>
        <label>Desa/Kelurahan<input name="desa" maxlength="100"></label>
        <label>Provinsi<input name="provinsi" maxlength="100"></label>
        <div class="full-width form-note">Data yang tidak diperlukan—seperti nomor identitas atau rekening—tidak diminta di formulir ini.</div>
        <div class="full-width dialog-actions"><button class="secondary-button" type="button" id="cancelPartner">Batal</button><button class="primary-button" type="submit" id="savePartner">Simpan</button></div>
    </form>
</dialog>

<dialog class="app-dialog" id="partnerReviewDialog">
    <form method="dialog" class="dialog-head"><div><span class="eyebrow">PEMERIKSAAN</span><h2 id="reviewPartnerName">Profil Mitra</h2></div><button class="icon-button" aria-label="Tutup">×</button></form>
    <div class="dialog-content">
        <div id="partnerDetail" class="detail-grid"></div>
        <label class="field-label">Catatan pemeriksaan<textarea id="verificationNote" rows="3" placeholder="Wajib jika perlu perbaikan atau tidak memenuhi persyaratan"></textarea></label>
        <div class="dialog-actions review-actions">
            <button class="secondary-button" id="returnPartner" type="button">Perlu Perbaikan</button>
            <button class="danger-button" id="rejectPartner" type="button">Tidak Memenuhi</button>
            <button class="primary-button" id="acceptPartner" type="button">Terverifikasi</button>
        </div>
    </div>
</dialog>

<script>
(() => {
    const canManage = @json(auth()->user()->hasRole(['ADMIN_BPS','ADMIN_MITRA','OPERATOR']));
    const canReview = @json(auth()->user()->hasRole(['ADMIN_BPS','ADMIN_MITRA','PEMERIKSA']));
    const rows = document.getElementById('partnerRows');
    const notice = document.getElementById('partnerNotice');
    const form = document.getElementById('partnerForm');
    const dialog = document.getElementById('partnerDialog');
    const reviewDialog = document.getElementById('partnerReviewDialog');
    let currentId = null;
    let offset = 0;
    const limit = 50;

    const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
    const statusLabel = value => ({DRAFT:'Draft',MENUNGGU_VERIFIKASI:'Menunggu Verifikasi',PERLU_PERBAIKAN:'Perlu Perbaikan',TERVERIFIKASI:'Terverifikasi',TIDAK_MEMENUHI:'Tidak Memenuhi'})[value] || value;
    const api = async (url, options = {}) => {
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: {'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content, ...(options.headers || {})},
            ...options,
        });
        const body = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(body.message || body.error || `Permintaan gagal (${response.status})`);
        return body;
    };
    const showNotice = (message, kind = 'error') => {
        notice.textContent = message;
        notice.className = `notice notice-${kind}`;
    };
    const refresh = async (reset = true) => {
        if (reset) { offset = 0; rows.innerHTML = '<tr><td colspan="6" class="table-empty">Memuat data mitra…</td></tr>'; }
        try {
            const params = new URLSearchParams({limit:String(limit),offset:String(offset)});
            const q = document.getElementById('partnerSearch').value.trim();
            const status = document.getElementById('partnerStatus').value;
            if (q) params.set('q', q);
            if (status) params.set('status', status);
            const result = await api(`/api/v2/mitra?${params}`);
            const rendered = result.items.map(partner => `<tr>
                <td><strong>${esc(partner.nama)}</strong>${partner.demo ? '<small class="demo-tag">DEMO</small>' : ''}</td>
                <td>${esc(partner.sobatId || partner.partnerCode || '—')}</td>
                <td>${esc([partner.desa,partner.kecamatan].filter(Boolean).join(', ') || '—')}</td>
                <td><span class="status-pill status-${esc((partner.statusVerifikasi || '').toLowerCase())}">${esc(statusLabel(partner.statusVerifikasi))}</span></td>
                <td><span class="active-dot ${partner.aktif ? 'on' : ''}"></span>${partner.aktif ? 'Aktif' : 'Nonaktif'}</td>
                <td class="table-actions"><button class="link-button" data-detail="${esc(partner.id)}">Detail</button>${canManage ? `<button class="link-button" data-edit="${esc(partner.id)}">Ubah</button>` : ''}${canReview && partner.statusVerifikasi === 'MENUNGGU_VERIFIKASI' ? `<button class="link-button review-link" data-review="${esc(partner.id)}">Periksa</button>` : ''}</td>
            </tr>`).join('');
            if (reset) rows.innerHTML = rendered || '<tr><td colspan="6" class="table-empty">Tidak ada mitra yang cocok dengan filter.</td></tr>';
            else rows.insertAdjacentHTML('beforeend', rendered);
            document.getElementById('partnerCount').textContent = `${result.items.length + offset} dari ${result.total} mitra`;
            offset += result.items.length;
            document.getElementById('morePartners').classList.toggle('hidden', offset >= result.total);
            notice.className = 'notice hidden';
        } catch (error) {
            rows.innerHTML = `<tr><td colspan="6" class="table-empty">${esc(error.message)}</td></tr>`;
            showNotice(error.message);
        }
    };
    const fill = (partner = {}) => {
        for (const [key,value] of Object.entries({
            nama:partner.nama || '', sobatId:partner.sobatId || '', partnerCode:partner.partnerCode || '',
            jenis:partner.jenis || 'PERORANGAN', wa:partner.wa || '', email:partner.email || '', alamat:partner.alamat || '',
            kecamatan:partner.kecamatan || '', desa:partner.desa || '', provinsi:partner.provinsi || ''
        })) form.elements[key].value = value;
    };
    document.getElementById('newPartner')?.addEventListener('click', () => {
        currentId = null; fill(); document.getElementById('partnerDialogTitle').textContent = 'Tambah Mitra'; dialog.showModal();
    });
    document.getElementById('cancelPartner').addEventListener('click', () => dialog.close());
    document.getElementById('reloadPartners').addEventListener('click', () => refresh());
    document.getElementById('partnerStatus').addEventListener('change', () => refresh());
    document.getElementById('partnerSearch').addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); refresh(); } });
    document.getElementById('morePartners').addEventListener('click', () => refresh(false));
    rows.addEventListener('click', async event => {
        const button = event.target.closest('button'); if (!button) return;
        const id = button.dataset.detail || button.dataset.edit || button.dataset.review;
        if (!id) return;
        try {
            const partner = await api(`/api/v2/mitra/${id}`);
            if (button.dataset.edit) {
                currentId = id; fill(partner); document.getElementById('partnerDialogTitle').textContent = 'Ubah Profil Mitra'; dialog.showModal();
            } else if (button.dataset.review) {
                currentId = id; document.getElementById('reviewPartnerName').textContent = partner.nama;
                document.getElementById('verificationNote').value = partner.catatanVerifikasi || '';
                document.getElementById('partnerDetail').innerHTML = `<span>ID Mitra</span><strong>${esc(partner.sobatId || '—')}</strong><span>Wilayah</span><strong>${esc([partner.desa,partner.kecamatan].filter(Boolean).join(', ') || '—')}</strong><span>Status</span><strong>${esc(statusLabel(partner.statusVerifikasi))}</strong>`;
                for (const idButton of ['acceptPartner','returnPartner','rejectPartner']) {
                    document.getElementById(idButton).classList.remove('hidden');
                    document.getElementById(idButton).disabled = !partner.canVerify;
                }
                reviewDialog.showModal();
            } else {
                document.getElementById('reviewPartnerName').textContent = partner.nama;
                document.getElementById('verificationNote').value = partner.catatanVerifikasi || 'Profil mitra';
                document.getElementById('partnerDetail').innerHTML = `<span>Jenis</span><strong>${esc(partner.jenis)}</strong><span>ID Mitra</span><strong>${esc(partner.sobatId || '—')}</strong><span>Kontak</span><strong>${esc(partner.wa || partner.email || '—')}</strong><span>Alamat</span><strong>${esc(partner.alamat || '—')}</strong><span>Status</span><strong>${esc(statusLabel(partner.statusVerifikasi))}</strong>`;
                for (const idButton of ['acceptPartner','returnPartner','rejectPartner']) document.getElementById(idButton).classList.add('hidden');
                reviewDialog.showModal();
            }
        } catch (error) { showNotice(error.message); }
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(form).entries());
        try {
            await api(currentId ? `/api/v2/mitra/${currentId}` : '/api/v2/mitra', {method:currentId ? 'PUT' : 'POST',body:JSON.stringify(data)});
            dialog.close(); await refresh(); showNotice('Data mitra berhasil disimpan. Status menunggu verifikasi.', 'success');
        } catch (error) { showNotice(error.message); }
    });
    const review = async status => {
        try {
            await api(`/api/v2/mitra/${currentId}/verification`, {method:'POST',body:JSON.stringify({status,catatan:document.getElementById('verificationNote').value})});
            reviewDialog.close(); await refresh(); showNotice('Hasil pemeriksaan berhasil dicatat.', 'success');
        } catch (error) { showNotice(error.message); }
    };
    document.getElementById('acceptPartner').addEventListener('click', () => review('TERVERIFIKASI'));
    document.getElementById('returnPartner').addEventListener('click', () => review('PERLU_PERBAIKAN'));
    document.getElementById('rejectPartner').addEventListener('click', () => review('TIDAK_MEMENUHI'));
    refresh();
})();
</script>
@endsection
