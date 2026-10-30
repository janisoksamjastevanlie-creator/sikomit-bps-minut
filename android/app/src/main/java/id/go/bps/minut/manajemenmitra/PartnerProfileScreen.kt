package id.go.bps.minut.manajemenmitra

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FilterChip
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import id.go.bps.minut.manajemenmitra.data.PartnerWriteRequest

private val partnerTypes = listOf("PERORANGAN", "PERUSAHAAN", "LEMBAGA")

@Composable
fun PartnerProfileScreen(
    partnerId: String?,
    userRole: String,
    onBack: () -> Unit,
    onCreated: (String) -> Unit,
    model: PartnerProfileViewModel = viewModel()
) {
    val state by model.state.collectAsState()
    val isNew = partnerId.isNullOrBlank()
    val canManage = userRole == "ADMINISTRATOR_SISTEM" || userRole == "ADMIN_MITRA"

    var editing by rememberSaveable(partnerId) { mutableStateOf(isNew) }
    var nama by rememberSaveable(partnerId) { mutableStateOf("") }
    var sobatId by rememberSaveable(partnerId) { mutableStateOf("") }
    var jenis by rememberSaveable(partnerId) { mutableStateOf("PERORANGAN") }
    var wa by rememberSaveable(partnerId) { mutableStateOf("") }
    var email by rememberSaveable(partnerId) { mutableStateOf("") }
    var alamat by rememberSaveable(partnerId) { mutableStateOf("") }
    var kecamatan by rememberSaveable(partnerId) { mutableStateOf("") }
    var desa by rememberSaveable(partnerId) { mutableStateOf("") }
    var provinsi by rememberSaveable(partnerId) { mutableStateOf("") }
    var catatan by rememberSaveable(partnerId) { mutableStateOf("") }
    var confirmSave by rememberSaveable { mutableStateOf(false) }
    var confirmVerification by rememberSaveable { mutableStateOf<String?>(null) }

    LaunchedEffect(partnerId) {
        if (!isNew) model.load(partnerId!!)
    }
    LaunchedEffect(state.profile?.updatedAt, state.profile?.createdAt) {
        state.profile?.let { profile ->
            nama = profile.nama
            sobatId = profile.sobatId
            jenis = profile.jenis.ifBlank { "PERORANGAN" }
            wa = profile.wa
            email = profile.email
            alamat = profile.alamat
            kecamatan = profile.kecamatan
            desa = profile.desa
            provinsi = profile.provinsi
            catatan = profile.catatanVerifikasi
            editing = false
        }
    }
    LaunchedEffect(state.saved) {
        if (state.saved && isNew) state.profile?.id?.let(onCreated)
    }

    Column(
        modifier = Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp)
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            TextButton(onClick = onBack) { Text("‹ Kembali") }
            Text(
                if (isNew) "Tambah Mitra" else "Profil Mitra",
                style = MaterialTheme.typography.titleLarge,
                fontWeight = FontWeight.Bold
            )
        }

        if (state.loading && state.profile == null) {
            Column(
                modifier = Modifier.fillMaxWidth().padding(top = 48.dp),
                horizontalAlignment = Alignment.CenterHorizontally
            ) {
                CircularProgressIndicator()
                Spacer(Modifier.height(12.dp))
                Text("Memuat profil mitra…")
            }
        } else if (state.forbidden && state.profile == null) {
            ProfileMessage("Akses tidak diizinkan", state.error ?: "Akun ini tidak dapat melihat profil tersebut.")
        } else if (state.error != null && state.profile == null && !isNew) {
            ProfileMessage("Profil tidak tersedia", state.error ?: "Terjadi kesalahan.")
            Button(onClick = { if (!isNew) model.load(partnerId!!) }) { Text("Coba lagi") }
        } else {
            if (!isNew) {
                state.profile?.let { profile ->
                    ProfileMessage(
                        "Status ${labelStatus(profile.statusVerifikasi)}",
                        if (profile.aktif) "Mitra aktif" else "Mitra tidak aktif"
                    )
                    if (profile.demo) {
                        Text("DATA DEMONSTRASI", color = MaterialTheme.colorScheme.tertiary, fontWeight = FontWeight.Bold)
                    }
                    if (profile.catatanVerifikasi.isNotBlank()) {
                        ProfileMessage("Catatan verifikasi", profile.catatanVerifikasi)
                    }
                }
            }

            if (state.error != null) ProfileMessage("Tindakan gagal", state.error ?: "Terjadi kesalahan.")
            if (state.saved && !isNew) ProfileMessage("Perubahan tersimpan", "Status verifikasi diperbarui sesuai proses administrasi.")

            OutlinedTextField(
                value = nama,
                onValueChange = { nama = it },
                label = { Text("Nama mitra *") },
                singleLine = true,
                enabled = editing && canManage && !state.saving,
                modifier = Modifier.fillMaxWidth()
            )
            OutlinedTextField(
                value = sobatId,
                onValueChange = { sobatId = it },
                label = { Text("ID mitra (jika tersedia)") },
                singleLine = true,
                enabled = editing && canManage && !state.saving,
                modifier = Modifier.fillMaxWidth()
            )
            Text("Jenis mitra", fontWeight = FontWeight.Medium)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                partnerTypes.forEach { type ->
                    FilterChip(
                        selected = jenis == type,
                        onClick = { jenis = type },
                        enabled = editing && canManage && !state.saving,
                        label = { Text(type.lowercase().replaceFirstChar { it.uppercase() }) }
                    )
                }
            }
            OutlinedTextField(
                value = wa,
                onValueChange = { wa = it },
                label = { Text("Nomor kontak") },
                singleLine = true,
                enabled = editing && canManage && !state.saving,
                modifier = Modifier.fillMaxWidth()
            )
            OutlinedTextField(
                value = email,
                onValueChange = { email = it },
                label = { Text("Email (opsional)") },
                singleLine = true,
                enabled = editing && canManage && !state.saving,
                modifier = Modifier.fillMaxWidth()
            )
            OutlinedTextField(
                value = alamat,
                onValueChange = { alamat = it },
                label = { Text("Alamat yang diperlukan") },
                enabled = editing && canManage && !state.saving,
                minLines = 2,
                modifier = Modifier.fillMaxWidth()
            )
            OutlinedTextField(
                value = kecamatan,
                onValueChange = { kecamatan = it },
                label = { Text("Kecamatan") },
                singleLine = true,
                enabled = editing && canManage && !state.saving,
                modifier = Modifier.fillMaxWidth()
            )
            OutlinedTextField(
                value = desa,
                onValueChange = { desa = it },
                label = { Text("Desa/Kelurahan") },
                singleLine = true,
                enabled = editing && canManage && !state.saving,
                modifier = Modifier.fillMaxWidth()
            )
            OutlinedTextField(
                value = provinsi,
                onValueChange = { provinsi = it },
                label = { Text("Provinsi") },
                singleLine = true,
                enabled = editing && canManage && !state.saving,
                modifier = Modifier.fillMaxWidth()
            )

            if (canManage && (isNew || editing)) {
                Button(
                    onClick = { confirmSave = true },
                    enabled = nama.isNotBlank() && !state.saving,
                    modifier = Modifier.fillMaxWidth()
                ) {
                    Text(if (state.saving) "Menyimpan…" else "Simpan")
                }
            } else if (canManage && !isNew && state.profile != null) {
                Button(onClick = { editing = true }, enabled = !state.saving, modifier = Modifier.fillMaxWidth()) {
                    Text("Ubah data mitra")
                }
            }

            state.profile?.let { profile ->
                if (profile.canVerify) {
                    OutlinedTextField(
                        value = catatan,
                        onValueChange = { catatan = it },
                        label = { Text("Catatan pemeriksaan (wajib untuk perbaikan/penolakan)") },
                        enabled = !state.saving,
                        minLines = 2,
                        modifier = Modifier.fillMaxWidth()
                    )
                    Button(
                        onClick = { confirmVerification = "TERVERIFIKASI" },
                        enabled = !state.saving,
                        modifier = Modifier.fillMaxWidth()
                    ) { Text("Tandai terverifikasi") }
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        TextButton(
                            onClick = { confirmVerification = "PERLU_PERBAIKAN" },
                            enabled = !state.saving && catatan.isNotBlank(),
                            modifier = Modifier.weight(1f)
                        ) { Text("Perlu perbaikan") }
                        TextButton(
                            onClick = { confirmVerification = "TIDAK_MEMENUHI" },
                            enabled = !state.saving && catatan.isNotBlank(),
                            modifier = Modifier.weight(1f)
                        ) { Text("Tidak memenuhi") }
                    }
                }
            }
        }
    }

    if (confirmSave) {
        AlertDialog(
            onDismissRequest = { confirmSave = false },
            title = { Text("Simpan data mitra?") },
            text = { Text("Data akan disimpan dengan status menunggu verifikasi. Perubahan pada profil terverifikasi akan memerlukan verifikasi ulang.") },
            confirmButton = {
                Button(onClick = {
                    confirmSave = false
                    model.save(
                        partnerId,
                        PartnerWriteRequest(nama.trim(), sobatId.trim(), jenis, wa.trim(), email.trim(), alamat.trim(), kecamatan.trim(), desa.trim(), provinsi.trim())
                    )
                }) { Text("Simpan") }
            },
            dismissButton = { TextButton(onClick = { confirmSave = false }) { Text("Batal") } }
        )
    }

    confirmVerification?.let { targetStatus ->
        AlertDialog(
            onDismissRequest = { confirmVerification = null },
            title = { Text("Konfirmasi hasil pemeriksaan") },
            text = {
                Text("Ubah status mitra menjadi ${labelStatus(targetStatus)}? Tindakan ini dicatat pada audit trail.")
            },
            confirmButton = {
                Button(onClick = {
                    confirmVerification = null
                    partnerId?.let { model.verify(it, targetStatus, catatan) }
                }) { Text("Konfirmasi") }
            },
            dismissButton = { TextButton(onClick = { confirmVerification = null }) { Text("Batal") } }
        )
    }
}

@Composable
private fun ProfileMessage(title: String, message: String) {
    Card(modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(title, fontWeight = FontWeight.Bold)
            Text(message, style = MaterialTheme.typography.bodyMedium)
        }
    }
}

private fun labelStatus(status: String): String = when (status.uppercase()) {
    "DRAFT" -> "Draft"
    "MENUNGGU_VERIFIKASI" -> "Menunggu verifikasi"
    "PERLU_PERBAIKAN" -> "Perlu perbaikan"
    "TERVERIFIKASI" -> "Terverifikasi"
    "TIDAK_MEMENUHI" -> "Tidak memenuhi persyaratan"
    else -> "Status belum ditentukan"
}
