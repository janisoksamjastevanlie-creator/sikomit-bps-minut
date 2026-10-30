package id.go.bps.minut.manajemenmitra

import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
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
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import id.go.bps.minut.manajemenmitra.data.ActivitySummary
import id.go.bps.minut.manajemenmitra.data.ActivityTarget
import id.go.bps.minut.manajemenmitra.data.ActivityWriteRequest

private val activityStatuses = listOf(
    "" to "Semua", "DRAFT" to "Draft", "AKTIF" to "Aktif", "SELESAI" to "Selesai", "DIBATALKAN" to "Dibatalkan"
)

@Composable
fun ActivityListScreen(
    role: String,
    onBack: () -> Unit,
    onOpenActivity: (String) -> Unit,
    onCreateActivity: () -> Unit,
    model: ActivityListViewModel = viewModel()
) {
    val state by model.state.collectAsState()
    var query by rememberSaveable { mutableStateOf("") }
    var year by rememberSaveable { mutableStateOf("") }
    var status by rememberSaveable { mutableStateOf("") }
    val canManage = role == "ADMINISTRATOR_SISTEM" || role == "PENGELOLA_KEGIATAN"

    LaunchedEffect(Unit) { model.load() }
    Column(Modifier.fillMaxSize().padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            TextButton(onClick = onBack) { Text("‹ Kembali") }
            Text("Kegiatan", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
        }
        if (canManage) Button(onClick = onCreateActivity, modifier = Modifier.fillMaxWidth()) { Text("Tambah kegiatan") }
        OutlinedTextField(
            value = query,
            onValueChange = { query = it },
            label = { Text("Cari kode, nama, atau wilayah") },
            singleLine = true,
            enabled = !state.loading,
            modifier = Modifier.fillMaxWidth()
        )
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
            OutlinedTextField(
                value = year,
                onValueChange = { year = it.filter(Char::isDigit).take(4) },
                label = { Text("Tahun") },
                singleLine = true,
                enabled = !state.loading,
                modifier = Modifier.weight(1f)
            )
            Button(onClick = { model.load(query, status, year) }, enabled = !state.loading) { Text("Cari") }
        }
        Row(
            modifier = Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()),
            horizontalArrangement = Arrangement.spacedBy(8.dp)
        ) {
            activityStatuses.forEach { (value, label) ->
                FilterChip(
                    selected = status == value,
                    onClick = { status = value; model.load(query, value, year) },
                    enabled = !state.loading,
                    label = { Text(label) }
                )
            }
        }

        when {
            state.loading && state.items.isEmpty() -> Column(
                modifier = Modifier.fillMaxSize(), horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.Center
            ) { CircularProgressIndicator(); Spacer(Modifier.height(10.dp)); Text("Memuat kegiatan…") }
            state.forbidden -> ActivityMessage("Akses tidak diizinkan", state.error ?: "Peran akun ini tidak dapat melihat kegiatan.")
            state.error != null -> Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                ActivityMessage("Kegiatan gagal dimuat", state.error ?: "Terjadi kesalahan.")
                Button(onClick = { model.load(query, status, year) }) { Text("Coba lagi") }
            }
            state.loaded && state.items.isEmpty() -> ActivityMessage("Belum ada kegiatan", "Tidak ada kegiatan yang cocok dengan filter ini.")
            else -> {
                Text("${state.items.size} dari ${state.total} kegiatan", style = MaterialTheme.typography.labelMedium)
                LazyColumn(modifier = Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    items(state.items, key = { it.id }) { activity ->
                        ActivityCard(activity) { onOpenActivity(activity.id) }
                    }
                }
            }
        }
    }
}

@Composable
private fun ActivityCard(activity: ActivitySummary, onClick: () -> Unit) {
    Card(Modifier.fillMaxWidth().clickable(onClick = onClick)) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(activity.nama.ifBlank { "Kegiatan tanpa nama" }, fontWeight = FontWeight.Bold)
            Text("${activity.kode} · ${activity.tahunAnggaran} · ${labelActivityStatus(activity.status)}")
            val period = listOf(activity.periode, activity.wilayah).filter(String::isNotBlank).joinToString(" · ")
            if (period.isNotBlank()) Text(period, style = MaterialTheme.typography.bodySmall)
            if (activity.targetVolume > 0) Text("Target: ${activity.targetVolume} ${activity.satuan}", style = MaterialTheme.typography.bodySmall)
            if (activity.demo) Text("DATA DEMONSTRASI", color = MaterialTheme.colorScheme.tertiary, fontWeight = FontWeight.Bold)
        }
    }
}

@Composable
fun ActivityProfileScreen(
    activityId: String?,
    role: String,
    onBack: () -> Unit,
    onCreated: (String) -> Unit,
    model: ActivityProfileViewModel = viewModel()
) {
    val state by model.state.collectAsState()
    val isNew = activityId.isNullOrBlank()
    val canManage = role == "ADMINISTRATOR_SISTEM" || role == "PENGELOLA_KEGIATAN"
    var editing by rememberSaveable(activityId) { mutableStateOf(isNew) }
    var code by rememberSaveable(activityId) { mutableStateOf("") }
    var name by rememberSaveable(activityId) { mutableStateOf("") }
    var year by rememberSaveable(activityId) { mutableStateOf("") }
    var period by rememberSaveable(activityId) { mutableStateOf("") }
    var responsible by rememberSaveable(activityId) { mutableStateOf("") }
    var kind by rememberSaveable(activityId) { mutableStateOf("") }
    var description by rememberSaveable(activityId) { mutableStateOf("") }
    var territory by rememberSaveable(activityId) { mutableStateOf("") }
    var targetVolume by rememberSaveable(activityId) { mutableStateOf("") }
    var unit by rememberSaveable(activityId) { mutableStateOf("") }
    var startDate by rememberSaveable(activityId) { mutableStateOf("") }
    var endDate by rememberSaveable(activityId) { mutableStateOf("") }
    var requirements by rememberSaveable(activityId) { mutableStateOf("") }
    var partnerNeed by rememberSaveable(activityId) { mutableStateOf("") }
    val tasks = remember(activityId) { mutableStateListOf(ActivityTarget()) }
    var confirmSave by rememberSaveable { mutableStateOf(false) }
    var confirmActivate by rememberSaveable { mutableStateOf(false) }

    LaunchedEffect(activityId) { if (!isNew) model.load(activityId!!) }
    LaunchedEffect(state.activity?.updatedAt, state.activity?.createdAt) {
        state.activity?.let { activity ->
            code = activity.kode
            name = activity.nama
            year = activity.tahunAnggaran
            period = activity.periode
            responsible = activity.penanggungJawab
            kind = activity.jenisKegiatan
            description = activity.uraian
            territory = activity.wilayah
            targetVolume = activity.targetVolume.takeIf { it > 0 }?.toString() ?: ""
            unit = activity.satuan
            startDate = activity.tglMulai
            endDate = activity.tglSelesai
            requirements = activity.persyaratanAdministrasi.joinToString(", ")
            partnerNeed = activity.kebutuhanMitra.takeIf { it > 0 }?.toString() ?: ""
            tasks.clear()
            tasks.addAll(activity.targetPekerjaan.ifEmpty { listOf(ActivityTarget()) })
            editing = false
        }
    }
    LaunchedEffect(state.saved) {
        if (state.saved && isNew) state.activity?.id?.let(onCreated)
    }

    Column(
        Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(9.dp)
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            TextButton(onClick = onBack) { Text("‹ Kembali") }
            Text(if (isNew) "Tambah Kegiatan" else "Detail Kegiatan", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
        }
        if (state.loading && state.activity == null) {
            CircularProgressIndicator()
            Text("Memuat kegiatan…")
        } else if (state.forbidden && state.activity == null) {
            ActivityMessage("Akses tidak diizinkan", state.error ?: "Kegiatan ini di luar lingkup akun.")
        } else if (state.error != null && state.activity == null && !isNew) {
            ActivityMessage("Kegiatan tidak tersedia", state.error ?: "Terjadi kesalahan.")
            Button(onClick = { activityId?.let(model::load) }) { Text("Coba lagi") }
        } else {
            state.activity?.let { activity ->
                ActivityMessage("Status ${labelActivityStatus(activity.status)}", if (activity.demo) "Data demonstrasi" else "Data administrasi")
                if (activity.demo) Text("DATA DEMONSTRASI", color = MaterialTheme.colorScheme.tertiary, fontWeight = FontWeight.Bold)
                if (state.saved && !isNew) ActivityMessage("Tersimpan", "Perubahan status/data kegiatan berhasil disimpan.")
            }
            if (state.error != null) ActivityMessage("Tindakan gagal", state.error ?: "Terjadi kesalahan.")
            ActivityInput("Kode kegiatan (otomatis jika kosong)", code, { code = it }, editing && canManage && !state.saving)
            ActivityInput("Nama kegiatan *", name, { name = it }, editing && canManage && !state.saving)
            ActivityInput("Tahun anggaran (4 digit) *", year, { year = it.filter(Char::isDigit).take(4) }, editing && canManage && !state.saving)
            ActivityInput("Periode", period, { period = it }, editing && canManage && !state.saving)
            ActivityInput("Penanggung jawab *", responsible, { responsible = it }, editing && canManage && !state.saving)
            ActivityInput("Jenis kegiatan", kind, { kind = it }, editing && canManage && !state.saving)
            ActivityInput("Uraian pekerjaan", description, { description = it }, editing && canManage && !state.saving, minLines = 2)
            ActivityInput("Wilayah", territory, { territory = it }, editing && canManage && !state.saving)
            ActivityInput("Target total", targetVolume, { targetVolume = it.filter { c -> c.isDigit() || c == '.' || c == ',' } }, editing && canManage && !state.saving)
            ActivityInput("Satuan target", unit, { unit = it }, editing && canManage && !state.saving)
            ActivityInput("Tanggal mulai (YYYY-MM-DD)", startDate, { startDate = it }, editing && canManage && !state.saving)
            ActivityInput("Tanggal selesai (YYYY-MM-DD)", endDate, { endDate = it }, editing && canManage && !state.saving)
            ActivityInput("Kebutuhan jumlah mitra *", partnerNeed, { partnerNeed = it.filter(Char::isDigit).take(5) }, editing && canManage && !state.saving)
            ActivityInput("Persyaratan administrasi (pisahkan dengan koma)", requirements, { requirements = it }, editing && canManage && !state.saving, minLines = 2)

            Text("Target dan uraian pekerjaan", fontWeight = FontWeight.Bold)
            tasks.forEachIndexed { index, task ->
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text("Target ${index + 1}", fontWeight = FontWeight.Medium)
                        ActivityInput("Uraian target *", task.uraian, { tasks[index] = tasks[index].copy(uraian = it) }, editing && canManage && !state.saving)
                        ActivityInput("Volume", task.target.takeIf { it > 0 }?.toString() ?: "", {
                            tasks[index] = tasks[index].copy(target = it.toDoubleOrNull() ?: 0.0)
                        }, editing && canManage && !state.saving)
                        ActivityInput("Satuan", task.satuan, { tasks[index] = tasks[index].copy(satuan = it) }, editing && canManage && !state.saving)
                        ActivityInput("Wilayah target", task.wilayah, { tasks[index] = tasks[index].copy(wilayah = it) }, editing && canManage && !state.saving)
                        ActivityInput("Jadwal mulai (YYYY-MM-DD)", task.jadwalMulai, { tasks[index] = tasks[index].copy(jadwalMulai = it) }, editing && canManage && !state.saving)
                        ActivityInput("Jadwal selesai (YYYY-MM-DD)", task.jadwalSelesai, { tasks[index] = tasks[index].copy(jadwalSelesai = it) }, editing && canManage && !state.saving)
                        if (editing && canManage && tasks.size > 1) {
                            TextButton(onClick = { tasks.removeAt(index) }) { Text("Hapus target") }
                        }
                    }
                }
            }
            if (editing && canManage) {
                TextButton(onClick = { tasks.add(ActivityTarget()) }) { Text("Tambah target pekerjaan") }
                Button(
                    onClick = { confirmSave = true },
                    enabled = name.isNotBlank() && year.length == 4 && !state.saving,
                    modifier = Modifier.fillMaxWidth()
                ) { Text(if (state.saving) "Menyimpan…" else "Simpan sebagai draft") }
            } else if (canManage && state.activity?.status == "DRAFT") {
                Button(onClick = { editing = true }, modifier = Modifier.fillMaxWidth()) { Text("Ubah kegiatan draft") }
                Button(onClick = { confirmActivate = true }, enabled = !state.saving, modifier = Modifier.fillMaxWidth()) {
                    Text(if (state.saving) "Memproses…" else "Aktifkan kegiatan")
                }
            }
        }
    }

    if (confirmSave) {
        AlertDialog(
            onDismissRequest = { confirmSave = false },
            title = { Text("Simpan draft kegiatan?") },
            text = { Text("Kegiatan disimpan sebagai DRAFT. Kegiatan harus dilengkapi dan diaktifkan sebelum penugasan dibuat.") },
            confirmButton = {
                Button(onClick = {
                    confirmSave = false
                    model.save(
                        activityId,
                        ActivityWriteRequest(
                            kode = code.trim(), nama = name.trim(), tahunAnggaran = year,
                            periode = period.trim(), penanggungJawab = responsible.trim(),
                            jenisKegiatan = kind.trim(), uraian = description.trim(), wilayah = territory.trim(),
                            targetVolume = targetVolume.replace(',', '.').toDoubleOrNull() ?: 0.0,
                            satuan = unit.trim(), tglMulai = startDate.trim(), tglSelesai = endDate.trim(),
                            persyaratanAdministrasi = requirements.split(',').map(String::trim).filter(String::isNotBlank),
                            kebutuhanMitra = partnerNeed.toIntOrNull() ?: 0,
                            targetPekerjaan = tasks.map { it.copy(uraian = it.uraian.trim(), satuan = it.satuan.trim(), wilayah = it.wilayah.trim()) }
                        )
                    )
                }) { Text("Simpan") }
            },
            dismissButton = { TextButton(onClick = { confirmSave = false }) { Text("Batal") } }
        )
    }
    if (confirmActivate) {
        AlertDialog(
            onDismissRequest = { confirmActivate = false },
            title = { Text("Aktifkan kegiatan?") },
            text = { Text("Kegiatan aktif dapat digunakan untuk penugasan. Pastikan tanggal, penanggung jawab, kebutuhan mitra, dan target sudah benar.") },
            confirmButton = {
                Button(onClick = { confirmActivate = false; activityId?.let(model::activate) }) { Text("Aktifkan") }
            },
            dismissButton = { TextButton(onClick = { confirmActivate = false }) { Text("Batal") } }
        )
    }
}

@Composable
private fun ActivityInput(label: String, value: String, onValueChange: (String) -> Unit, enabled: Boolean, minLines: Int = 1) {
    OutlinedTextField(
        value = value,
        onValueChange = onValueChange,
        label = { Text(label) },
        enabled = enabled,
        singleLine = minLines == 1,
        minLines = minLines,
        modifier = Modifier.fillMaxWidth()
    )
}

@Composable
private fun ActivityMessage(title: String, body: String) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(title, fontWeight = FontWeight.Bold)
            Text(body)
        }
    }
}

private fun labelActivityStatus(status: String): String = when (status.uppercase()) {
    "DRAFT" -> "Draft"
    "AKTIF" -> "Aktif"
    "SELESAI" -> "Selesai"
    "DIBATALKAN" -> "Dibatalkan"
    else -> "Status belum diketahui"
}
